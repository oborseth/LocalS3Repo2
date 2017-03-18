<?php
/*
        Modified to work with 1.21 and CloudFront.
  	Owen Borseth - owen at borseth dot us
*/

/**
 * A repository for files accessible via the Amazon S3 filesystem. Does not support
 * database access or registration.
 * @ingroup FileRepo
 */
class FSs3Repo extends FileRepo {
	var $directory, $deletedDir, $deletedHashLevels, $fileMode;
	var $urlbase;
	var $AWS_ACCESS_KEY, $AWS_SECRET_KEY, $AWS_S3_BUCKET, $AWS_S3_PUBLIC, $AWS_S3_SSL;
	var $cloudFrontUrl;
	var $fileFactory = array( 'UnregisteredLocalFile', 'newFromTitle' );
	var $oldFileFactory = false;
	var $pathDisclosureProtection = 'simple';

	function __construct( $info ) {
		parent::__construct( $info );

		// Required settings
		$this->directory = isset( $info['directory'] ) ? $info['directory'] : 
			"http://s3.amazonaws.com/".$info['wgUploadS3Bucket']."/".$info['wgUploadDirectory'];
		$this->AWS_ACCESS_KEY = $info['AWS_ACCESS_KEY'];
		$this->AWS_SECRET_KEY = $info['AWS_SECRET_KEY'];
		$this->AWS_S3_BUCKET = $info['AWS_S3_BUCKET'];
		$this->cloudFrontUrl = $info['cloudFrontUrl'];
		$this->cloudFrontDirectory = $this->cloudFrontUrl.($this->directory ? $this->directory : $info['wgUploadDirectory']);

		global $s3;
		$s3->setAuth($this->AWS_ACCESS_KEY, $this->AWS_SECRET_KEY);

		// Optional settings
		$this->AWS_S3_PUBLIC = isset( $info['AWS_S3_PUBLIC'] ) ? $info['AWS_S3_PUBLIC'] : false;
		$s3::$useSSL = $this->AWS_S3_SSL = isset( $info['AWS_S3_SSL'] ) ? $info['AWS_S3_SSL'] : true;
		$this->url = isset( $info['url'] ) ? $info['url'] :
			($this->AWS_S3_SSL ? "https://" : "http://") . "s3.amazonaws.com/" .
				$this->AWS_S3_BUCKET . "/" . $this->directory;
		$this->hashLevels = isset( $info['hashLevels'] ) ? $info['hashLevels'] : 2;
		$this->deletedHashLevels = isset( $info['deletedHashLevels'] ) ?
			$info['deletedHashLevels'] : $this->hashLevels;
		$this->deletedDir = isset( $info['deletedDir'] ) ? $info['deletedDir'] : false;
		$this->fileMode = isset( $info['fileMode'] ) ? $info['fileMode'] : 0644;
		if ( isset( $info['thumbDir'] ) ) {
			$this->thumbDir =  $info['thumbDir'];
		} else {
			$this->thumbDir = "{$this->directory}/thumb";
		}
		if ( isset( $info['thumbUrl'] ) ) {
			$this->thumbUrl = $info['thumbUrl'];
		} else {
			$this->thumbUrl = "{$this->url}/thumb";
		}
		$this->urlbase = $info['urlbase'];
	}

	/**
	 * Get the public (upload) root directory of the repository.
	 */
	function getRootDirectory() {
		return $this->directory;
	}

	/**
	 * Get the public root URL of the repository (not authenticated, will not work in general)
	 */
	function getRootUrl() {
		return $this->url;
	}

	/**
	 * Get the base URL of the repository (not authenticated, will not work in general)
	 */
	function getUrlBase() {
		return $this->urlbase;
	}
	
	/**
	 * Returns true if the repository uses a multi-level directory structure
	 */
	function isHashed() {
		return (bool)$this->hashLevels;
	}

	/**
	 * Get the S3 directory corresponding to one of the three basic zones
	 */
	function getZonePath($zone, $ext = NULL) {
		switch ( $zone ) {
			case 'public':
				return $this->directory;
			case 'temp':
				return "{$this->directory}/temp";
			case 'deleted':
				return $this->deletedDir;
			case 'thumb':
				return $this->thumbDir;
			default:
				return false;
		}
	}

	/** Returns zone part of repo URL, plus base URL, to be appended to S3 base URL
	 * @see FileRepo::getZoneUrl()
	 */
	function getZoneUrl($zone, $ext = NULL) {
		switch ( $zone ) {
			case 'public':
				$retval = $this->url;
				break;
			case 'temp':
				$retval = "{$this->url}/temp";
				break;
			case 'deleted':
				$retval = parent::getZoneUrl( $zone ); // no public URL
				break;
			case 'thumb':
				$retval = $this->thumbUrl;
				break;
			default:
				$retval = parent::getZoneUrl( $zone );
				break;
		}
		wfDebug(__METHOD__.": ".print_r($zone,true).", retval: $retval \n");
		return $retval;
	}

	/**
	 * Get a URL referring to this repository, with the private mwrepo protocol.
	 * The suffix, if supplied, is considered to be unencoded, and will be
	 * URL-encoded before being returned.
	 */
	function getVirtualUrl( $suffix = false ) {
		$path = 'mwrepo://' . $this->name;
		if ( $suffix !== false ) {
			$path .= '/' . rawurlencode( $suffix );
		}
		wfDebug(__METHOD__.": ".print_r($path,true)." --> $suffix \n");
		return $path;
	}

	/**
	 * Get the local path corresponding to a virtual URL
	 */
	function resolveVirtualUrl( $url ) {
		if ( substr( $url, 0, 9 ) != 'mwrepo://' ) {
			throw new MWException( __METHOD__.': unknown protoocl' );
		}

		$bits = explode( '/', substr( $url, 9 ), 3 );
		if ( count( $bits ) != 3 ) {
			throw new MWException( __METHOD__.": invalid mwrepo URL: $url" );
		}
		list( $repo, $zone, $rel ) = $bits;
		if ( $repo !== $this->name ) {
			throw new MWException( __METHOD__.": fetching from a foreign repo is not supported" );
		}
		$base = $this->getZonePath( $zone );
		if ( !$base ) {
			throw new MWException( __METHOD__.": invalid zone: $zone" );
		}
		return $base . '/' . rawurldecode( $rel );
	}

	/**
	 * Store a batch of files from local (i.e. Windows or Linux) filesystem to S3
	 *
	 * @param $triplets Array: (src,zone,dest) triplets as per store()
	 * @param $flags Integer: bitwise combination of the following flags:
	 *     self::DELETE_SOURCE     Delete the source file after upload
	 *     self::OVERWRITE         Overwrite an existing destination file instead of failing
	 *     self::OVERWRITE_SAME    Overwrite the file if the destination exists and has the
	 *                             same contents as the source (not implemented in S3)
	 */
	function storeBatch(array $triplets, $flags = 0) {
		wfDebug(__METHOD__." triplets: ".print_r($triplets,true)."flags: ".print_r($flags)."\n");
		global $s3;
		$status = $this->newGood();
		foreach ( $triplets as $i => $triplet ) {
			list( $srcPath, $dstZone, $dstRel ) = $triplet;

			$root = $this->getZonePath( $dstZone );
			if ( !$root ) {
				throw new MWException( "Invalid zone: $dstZone" );
			}
			if ( !$this->validateFilename( $dstRel ) ) {
				throw new MWException( 'Validation error in $dstRel' );
			}
			$dstPath = "$root/$dstRel";

			if ( self::isVirtualUrl( $srcPath ) ) {
				$srcPath = $ntuples[$i][0] = $this->resolveVirtualUrl( $srcPath );
			}
			$s3path = $srcPath;
			$info = $s3->getObjectInfo($this->AWS_S3_BUCKET, $s3path);
			if ( ! $info && !is_file( $srcPath ) ) { // check both local system and S3
				// Make a list of files that don't exist for return to the caller
				$status->fatal( 'filenotfound', $srcPath );
				continue;
			}
			$s3path = $dstPath;
			$info = $s3->getObjectInfo($this->AWS_S3_BUCKET, $s3path);
			wfDebug(__METHOD__."(validation) s3path-dest: $s3path\ninfo:".print_r($info,true)."\n");
			if ( !( $flags & self::OVERWRITE ) && $info ) {
				$status->fatal( 'fileexistserror', $dstPath );
			}
		}

		$deleteDest = wfIsWindows() && ( $flags & self::OVERWRITE );

		// Abort now on failure
		if ( !$status->ok ) {
			return $status;
		}

		foreach ( $triplets as $triplet ) {
			list( $srcPath, $dstZone, $dstRel ) = $triplet;
			$root = $this->getZonePath( $dstZone );
			$dstPath = "$root/$dstRel";
			$good = true;

			if ( $flags & self::DELETE_SOURCE ) {
				wfDebug(__METHOD__."(delete): dstPath: $dstPath, ".print_r($triplet,true));
				if ( $deleteDest ) {				
					if(! $s3->deleteObject($this->AWS_S3_BUCKET, $dstPath)) {
						wfDebug(__METHOD__.": FAILED - delete: $dstPath");
					}
				}
				$info = $s3->getObjectInfo($this->AWS_S3_BUCKET, $srcPath);
				if ( ! $info ) { // local file
					if ( ! $s3->putObjectFile($srcPath, $this->AWS_S3_BUCKET, $dstPath, 
							($this->AWS_S3_PUBLIC ? S3::ACL_PUBLIC_READ : S3::ACL_PRIVATE))) {
						$status->error( 'filecopyerror', $srcPath, $dstPath );
						$good = false;
					}
					unlink( $srcPath );
				} else { // s3 file
					if ( ! $s3->copyObject($this->AWS_S3_BUCKET, $srcPath, 
								$this->AWS_S3_BUCKET, $dstPath, 
						   ($this->AWS_S3_PUBLIC ? S3::ACL_PUBLIC_READ : S3::ACL_PRIVATE)) &&
						! $s3->deleteObject($this->AWS_S3_BUCKET, $srcPath)) {
						$status->error( 'filecopyerror', $srcPath, $dstPath );
						$good = false;
					}
				}
			} else {
				wfDebug(__METHOD__."(transfer): dstPath: $dstPath, ".print_r($triplet,true));
				$info = $s3->getObjectInfo($this->AWS_S3_BUCKET, $srcPath);
				if ( ! $info ) { // local file
					if ( ! $s3->putObjectFile($srcPath, $this->AWS_S3_BUCKET, $dstPath, 
							($this->AWS_S3_PUBLIC ? S3::ACL_PUBLIC_READ : S3::ACL_PRIVATE))) {
						$status->error( 'filecopyerror', $srcPath, $dstPath );
						$good = false;
					}
					unlink( $srcPath );
				} else { // s3 file
					if ( ! $s3->copyObject($this->AWS_S3_BUCKET, $srcPath, 
								$this->AWS_S3_BUCKET, $dstPath, 
						   ($this->AWS_S3_PUBLIC ? S3::ACL_PUBLIC_READ : S3::ACL_PRIVATE)) &&
						! $s3->deleteObject($this->AWS_S3_BUCKET, $srcPath)) {
						$status->error( 'filecopyerror', $srcPath, $dstPath );
						$good = false;
					}
				}
			}
			if ( $good ) {
				$status->successCount++;
			} else {
				$status->failCount++;
			}
		}
		return $status;
	}

	/**
	 * Append a from from local (i.e. Windows or Linux) filesystem to S3 existing file
	 *
	 * @param $srcPath - file on S3 filesystem
	 * @param $toAppendPath - file from local filesystem
	 * @param $flags Integer: bitwise combination of the following flags:
	 *     self::DELETE_SOURCE     Delete the toAppend file after append
	 */
	function append( $srcPath, $toAppendPath, $flags = 0 ) {
		global $s3;
		$status = $this->newGood();

		// Resolve the virtual URL
		if ( self::isVirtualUrl( $srcPath ) ) {
			$srcPath = $this->resolveVirtualUrl( $srcPath );
		}
		// Make sure the files are there
		if ( !is_file( $toAppendPath ) )
			$status->fatal( 'filenotfound', $toAppendPath );

		$info = $s3->getObjectInfo($this->AWS_S3_BUCKET, $srcPath);
		if ( ! $info )
			$status->fatal( 'filenotfound', $srcPath );

		if ( !$status->isOk() ) return $status;

		// Do the append
		$tmpLoc = tempnam(wfTempDir(), "Append");
		if(! $s3->getObject($this->AWS_S3_BUCKET, $srcPath, $tmpLoc)) {
			$status->fatal( 'fileappenderrorread', $srcPath );
		}
		$chunk = file_get_contents( $toAppendPath );
		if( $chunk === false ) {
			$status->fatal( 'fileappenderrorread', $toAppendPath );
		}

		if( $status->isOk() ) {
			if ( file_put_contents( $tmpLoc, $chunk, FILE_APPEND ) ) {
				$status->value = $srcPath;
				if ( ! $s3->putObjectFile($tmpLoc, $this->AWS_S3_BUCKET, $srcPath, 
						($this->AWS_S3_PUBLIC ? S3::ACL_PUBLIC_READ : S3::ACL_PRIVATE))) {
					$status->fatal( 'fileappenderror', $toAppendPath,  $srcPath);
				}
			} else {
				$status->fatal( 'fileappenderror', $toAppendPath,  $srcPath);
			}
		}

		if ( $flags & self::DELETE_SOURCE ) {
			unlink( $toAppendPath );
		}

		return $status;
	}

	/**
	 * Checks existence of specified array of files.
	 *
	 * @param $files Array: URLs of files to check
	 * @return Either array of files and existence flags, or false
	 */
	function fileExistsBatch(array $files) {
		global $s3;
		$result = array();
		foreach ( $files as $key => $file ) {
			if ( self::isVirtualUrl( $file ) ) {
				$file = $this->resolveVirtualUrl( $file );
			}
			$info = $s3->getObjectInfo($this->AWS_S3_BUCKET, $file);
			$result[$key] = ($info ? true : false) ;
			// if( $flags & self::FILES_ONLY ) {
				// $result[$key] = is_file( $file );
			// } else {
				// $result[$key] = file_exists( $file );
			// }
		}

		return $result;
	}

	/**
	 * Take all available measures to prevent web accessibility of new deleted
	 * directories, in case the user has not configured offline storage
	 * Not applicable in S3
	 */
	protected function initDeletedDir( $dir ) {
		return;
	}

	/**
	 * Pick a random name in the temp zone and store a file to it.
	 * @param $originalName String: the base name of the file as specified
	 *     by the user. The file extension will be maintained.
	 * @param $srcPath String: the current location of the file.
	 * @return FileRepoStatus object with the URL in the value.
	 */
	function storeTemp( $originalName, $srcPath ) {
		wfDebug(__METHOD__.": ".print_r($originalName,true)."--> $srcPath \n");
		$date = gmdate( "YmdHis" );
		$hashPath = $this->getHashPath( $originalName );
		$dstRel = "$hashPath$date!$originalName";
		$dstUrlRel = $hashPath . $date . '!' . rawurlencode( $originalName );

		$result = $this->store( $srcPath, 'temp', $dstRel );
		$result->value = $this->getVirtualUrl( 'temp' ) . '/' . $dstUrlRel;
		return $result;
	}

	/**
	 * Remove a temporary file or mark it for garbage collection
	 * @param $virtualUrl String: the virtual URL returned by storeTemp
	 * @return Boolean: true on success, false on failure
	 */
	function freeTemp( $virtualUrl ) {
		wfDebug(__METHOD__.": ".print_r($virtualUrl,true)."\n");
		global $s3;
		$s3path = $virtualUrl;
		$infoS3  = $s3->getObjectInfo($this->AWS_S3_BUCKET, $s3path); // see if on S3
		wfDebug(__METHOD__." s3path: $s3path, infoS3:".print_r($infoS3,true)."\n");
		$success = $s3->deleteObject($this->AWS_S3_BUCKET, $s3path);
		return $success;
	}

	/**
	 * Publish a batch of files
	 * @param $ntuples Array: (source,dest,archive) triplets as per publish()
	 *        source can be on local machine or on S3, dest must be on S3
	 * @param $flags Integer: bitfield, may be FileRepo::DELETE_SOURCE to indicate
	 *        that the source files should be deleted if possible
	 */
	function publishBatch(array $ntuples, $flags = 0 ) {
		// Perform initial checks
		wfDebug(__METHOD__.": ".print_r($ntuples,true));
		global $s3;
		$status = $this->newGood( array() );
		foreach ( $ntuples as $i => $triplet ) {
			list( $srcPath, $dstRel, $archiveRel ) = $triplet;

			if ( substr( $srcPath, 0, 9 ) == 'mwrepo://' ) {
				$ntuples[$i][0] = $srcPath = $this->resolveVirtualUrl( $srcPath );
			}
			if ( !$this->validateFilename( $dstRel ) ) {
				throw new MWException( 'Validation error in $dstRel' );
			}
			if ( !$this->validateFilename( $archiveRel ) ) {
				throw new MWException( 'Validation error in $archiveRel' );
			}
			$dstPath = "{$this->directory}/$dstRel";
			$archivePath = "{$this->directory}/$archiveRel";

			$dstDir = dirname( $dstPath );
			$archiveDir = dirname( $archivePath );
			$infoS3  = $s3->getObjectInfo($this->AWS_S3_BUCKET, $srcPath); // see if on S3
			$infoLoc = is_file( $srcPath ); // see if local file
			wfDebug(__METHOD__."(validation) srcPath: $srcPath, infoLoc: $infoLoc, infoS3:".print_r($infoS3,true)."\n");
			if ( ! $infoS3 && ! $infoLoc ) {
				// Make a list of files that don't exist for return to the caller
				$status->fatal( 'filenotfound', $srcPath );
			}
		}

		if ( !$status->ok ) {
			return $status;
		}

		foreach ( $ntuples as $i => $triplet ) {
			list( $srcPath, $dstRel, $archiveRel ) = $triplet;
			$dstPath = "{$this->directory}/$dstRel";
			$archivePath = "{$this->directory}/$archiveRel";

			// Archive destination file if it exists
			$info = $s3->getObjectInfo($this->AWS_S3_BUCKET, $dstPath);
			wfDebug(__METHOD__."(transfer) dstPath: $dstPath, info:".print_r($info,true)."\n");
			if( $info ) {
				// Check if the archive file exists
				// This is a sanity check to avoid data loss. In UNIX, the rename primitive
				// unlinks the destination file if it exists. DB-based synchronisation in
				// publishBatch's caller should prevent races. In Windows there's no
				// problem because the rename primitive fails if the destination exists.
				if ( is_file( $archivePath ) ) {
					$success = false;
				} else 				// Check if the archive file exists
				// This is a sanity check to avoid data loss. In UNIX, the rename primitive
				// unlinks the destination file if it exists. DB-based synchronisation in
				// publishBatch's caller should prevent races. In Windows there's no
				// problem because the rename primitive fails if the destination exists.
				$s3path = /*$this->directory/*AWS_S3_FOLDER .*/ $archivePath;
				$info = $s3->getObjectInfo($this->AWS_S3_BUCKET, $s3path);
				wfDebug(__METHOD__."(file exists): $s3path, info:".print_r($info,true)."\n");
				if ( $info /*is_file( $archivePath )*/ ) {
					$success = false;
				} else {
					wfDebug(__METHOD__.": moving file $dstPath to $archivePath\n");
					if(! (
							$s3->copyObject($this->AWS_S3_BUCKET, $dstPath, 
								$this->AWS_S3_BUCKET, $archivePath, 
								   ($this->AWS_S3_PUBLIC ? S3::ACL_PUBLIC_READ : S3::ACL_PRIVATE)) &&
							$s3->deleteObject($this->AWS_S3_BUCKET, $dstPath))
						) {
						wfDebug(__METHOD__.": FAILED moving file $dstPath to $archivePath\n");
						$success = false;
					} else {
						$success = true;
					}
				}


				if( !$success ) {
					$status->error( 'filerenameerror',$dstPath, $archivePath );
					$status->failCount++;
					continue;
				} else {
					wfDebug(__METHOD__.": moved file $dstPath to $archivePath\n");
				}
				$status->value[$i] = 'archived';
			} else {
				$status->value[$i] = 'new';
			}

			$good = true;
			wfSuppressWarnings();
			if(! is_file( $srcPath )) {			
				// S3
				if(! $s3->copyObject($this->AWS_S3_BUCKET, $srcPath, $this->AWS_S3_BUCKET, 
				        $this->AWS_S3_FOLDER . $dstPath, ($this->AWS_S3_PUBLIC ? S3::ACL_PUBLIC_READ : S3::ACL_PRIVATE))) {
					wfDebug(__METHOD__.": FAILED - copy: $srcPath to $dstPath");
				}
				//$s3->putObjectFile($srcPath, $this->AWS_S3_BUCKET, $this->directory/*AWS_S3_FOLDER*/ . $dstPath, ($this->AWS_S3_PUBLIC ? S3::ACL_PUBLIC_READ : S3::ACL_PRIVATE));
				if ( $flags & self::DELETE_SOURCE ) {
					if(! $s3->deleteObject($this->AWS_S3_BUCKET, /*$this->directory/*AWS_S3_FOLDER .*/ $srcPath)) {
						wfDebug(__METHOD__.": FAILED - delete: $srcPath");
					}
				}
			} else {
				// Local file
				if(! $s3->putObjectFile($srcPath, $this->AWS_S3_BUCKET, $dstPath, 
							($this->AWS_S3_PUBLIC ? S3::ACL_PUBLIC_READ : S3::ACL_PRIVATE))) {
					$status->error( 'filecopyerror', $srcPath, $dstPath );
					$good = false;
				}
				if ( $flags & self::DELETE_SOURCE ) {
					unlink($srcPath);
				}
			}
			wfRestoreWarnings();

			if ( $good ) {
				$status->successCount++;
				wfDebug(__METHOD__.": wrote tempfile $srcPath to $dstPath\n");
			} else {
				$status->failCount++;
			}
		}
		return $status;
	}

	/**
	 * Move a group of files to the deletion archive.
	 * If no valid deletion archive is configured, this may either delete the
	 * file or throw an exception, depending on the preference of the repository.
	 *
	 * @param $sourceDestPairs Array of source/destination pairs. Each element
	 *        is a two-element array containing the source file path relative to the
	 *        public root in the first element, and the archive file path relative
	 *        to the deleted zone root in the second element.
	 * @return FileRepoStatus
	 */
	function deleteBatch(array $sourceDestPairs) {
		wfDebug(__METHOD__.": ".print_r($sourceDestPairs,true)."\n");
		global $s3;
		$status = $this->newGood();
		if ( !$this->deletedDir ) {
			throw new MWException( __METHOD__.': no valid deletion archive directory' );
		}

		/**
		 * Validate filenames and create archive directories
		 */
		foreach ( $sourceDestPairs as $pair ) {
			list( $srcRel, $archiveRel ) = $pair;
			if ( !$this->validateFilename( $srcRel ) ) {
				throw new MWException( __METHOD__.':Validation error in $srcRel' );
			}
			if ( !$this->validateFilename( $archiveRel ) ) {
				throw new MWException( __METHOD__.':Validation error in $archiveRel' );
			}
			$archivePath = "{$this->deletedDir}/$archiveRel";
			// $archiveDir = dirname( $archivePath );
			// if ( !is_dir( $archiveDir ) ) {
				// if ( !wfMkdirParents( $archiveDir ) ) {
					// $status->fatal( 'directorycreateerror', $archiveDir );
					// continue;
				// }
				// $this->initDeletedDir( $archiveDir );
			// }
			// // Check if the archive directory is writable
			// // This doesn't appear to work on NTFS
			// if ( !is_writable( $archiveDir ) ) {
				// $status->fatal( 'filedelete-archive-read-only', $archiveDir );
			// }
		}
		if ( !$status->ok ) {
			// Abort early
			return $status;
		}

		/**
		 * Move the files
		 * We're now committed to returning an OK result, which will lead to
		 * the files being moved in the DB also.
		 */
		foreach ( $sourceDestPairs as $pair ) {
			list( $srcRel, $archiveRel ) = $pair;
			$srcPath = "{$this->directory}/$srcRel";
			$archivePath = "{$this->deletedDir}/$archiveRel";
			wfDebug(__METHOD__.": src: $srcPath, dest: $archivePath \n");
			$good = true;
			$info = $s3->getObjectInfo($this->AWS_S3_BUCKET, $archivePath);
			wfDebug(__METHOD__." :$archivePath\ninfo:".print_r($info,true)."\n");
			if ( $info ) {
				# A file with this content hash is already archived
				if ( !$s3->deleteObject($this->AWS_S3_BUCKET, $srcPath) ) {
					$status->error( 'filedeleteerror', $srcPath );
					$good = false;
				}
			} else{
				if(! (
						$s3->copyObject($this->AWS_S3_BUCKET, $srcPath, 
							$this->AWS_S3_BUCKET, $archivePath, 
							   ($this->AWS_S3_PUBLIC ? S3::ACL_PUBLIC_READ : S3::ACL_PRIVATE)) &&
						$s3->deleteObject($this->AWS_S3_BUCKET, $srcPath))
					) {
					wfDebug(__METHOD__.": FAILED moving file $dstPath to $archivePath\n");
					$status->error( 'filerenameerror', $srcPath, $archivePath );
					$good = false;
				}
			}
			if ( $good ) {
				$status->successCount++;
			} else {
				$status->failCount++;
			}
		}
		return $status;
	}

	/**
	 * Get a relative path for a deletion archive key,
	 * e.g. s/z/a/ for sza251lrxrc1jad41h5mgilp8nysje52.jpg
	 */
	function getDeletedHashPath( $key ) {
		$path = '';
		for ( $i = 0; $i < $this->deletedHashLevels; $i++ ) {
			$path .= $key[$i] . '/';
		}
		return $path;
	}

	/**
	 * Call a callback function for every file in the repository.
	 * Uses the filesystem even in child classes.
	 */
	function enumFilesInFS( $callback ) {
		global $s3;
		$s3contents = $s3->getBucket($this->AWS_S3_BUCKET, $this->directory."/");
		wfDebug(__METHOD__." :".print_r($s3contents,true)."\n");
		foreach( $s3contents as $path ) {
			call_user_func( $callback, $path->name );
		}
	}

	/**
	 * Call a callback function for every file in the repository
	 * May use either the database or the filesystem
	 */
	function enumFiles( $callback ) {
		$this->enumFilesInFS( $callback );
	}

	/**
	 * Get properties of a file with a given virtual URL
	 * The virtual URL must refer to this repo
	 */
	function getFileProps( $virtualUrl ) {
		$path = $this->resolveVirtualUrl( $virtualUrl );
		return File::getPropsFromPath( $path );
	}

	/**
	 * Path disclosure protection functions
	 *
	 * Get a callback function to use for cleaning error message parameters
	 */
	function getErrorCleanupFunction() {
		switch ( $this->pathDisclosureProtection ) {
			case 'simple':
				$callback = array( $this, 'simpleClean' );
				break;
			default:
				$callback = parent::getErrorCleanupFunction();
		}
		return $callback;
	}

	function simpleClean( $param ) {
		if ( !isset( $this->simpleCleanPairs ) ) {
			global $IP;
			$this->simpleCleanPairs = array(
				$this->directory => 'public',
				"{$this->directory}/temp" => 'temp',
				$IP => '$IP',
				dirname( __FILE__ ) => '$IP/extensions/WebStore',
			);
			if ( $this->deletedDir ) {
				$this->simpleCleanPairs[$this->deletedDir] = 'deleted';
			}
		}
		return strtr( $param, $this->simpleCleanPairs );
	}

	/**
	 * Chmod a file, supressing the warnings.
	 * @param $path String: the path to change
	 */
	protected function chmod( $path ) {
		wfSuppressWarnings();
		chmod( $path, $this->fileMode );
		wfRestoreWarnings();
	}

}
