<?php
/**
 * @package WP Static HTML Output
 *
 * Copyright (c) 2011 Leon Stafford
 */

use Kunnu\Dropbox\Dropbox;
use Kunnu\Dropbox\DropboxApp;
use Kunnu\Dropbox\DropboxFile;
use Kunnu\Dropbox\Exceptions\DropboxClientException;
use GuzzleHttp\Client;

class StaticHtmlOutput {
	const VERSION = '2.2';
	const OPTIONS_KEY = 'wp-static-html-output-options';
	const HOOK = 'wp-static-html-output';

	protected static $_instance = null;
	protected $_options = null;
	protected $_view = null;
	protected $_exportLog = array();
	protected function __construct() {}
	protected function __clone() {}

	public static function getInstance() {
		if (null === self::$_instance) {
			self::$_instance = new self();
			self::$_instance->_options = new StaticHtmlOutput_Options(self::OPTIONS_KEY);
			self::$_instance->_view = new StaticHtmlOutput_View();
		}

		return self::$_instance;
	}

	public static function init($bootstrapFile) {
		$instance = self::getInstance();

		register_activation_hook($bootstrapFile, array($instance, 'activate'));

		if (is_admin()) {
			add_action('admin_menu', array($instance, 'registerOptionsPage'));
			add_action(self::HOOK . '-saveOptions', array($instance, 'saveOptions'));
		}

		return $instance;
	}

	public function activate() {
		if (null === $this->_options->getOption('version')) {
			$this->_options
				->setOption('version', self::VERSION)
				->setOption('static_export_settings', self::VERSION)
				->save();
		}
	}

	public function registerOptionsPage() {
		$page = add_submenu_page('tools.php', __('WP Static HTML Output', 'static-html-output-plugin'), __('WP Static HTML Output', 'static-html-output-plugin'), 'manage_options', self::HOOK . '-options', array($this, 'renderOptionsPage'));
		add_action('admin_print_styles-' . $page, array($this, 'enqueueAdminStyles'));
	}

	public function enqueueAdminStyles() {
		$pluginDirUrl = plugin_dir_url(dirname(__FILE__));
		wp_enqueue_style(self::HOOK . '-admin', $pluginDirUrl . '/css/wp-static-html-output.css');
	}

	public function renderOptionsPage() {
		// Check system requirements
		$uploadDir = $this->get_write_directory();
		$uploadsFolderWritable = $uploadDir && is_writable($uploadDir);
		$supportsZipArchives = extension_loaded('zip');
		$permalinksStructureDefined = strlen(get_option('permalink_structure'));

		if (!$uploadsFolderWritable || !$supportsZipArchives || !$permalinksStructureDefined) {
			$this->_view
				->setTemplate('system-requirements')
				->assign('uploadsFolderWritable', $uploadsFolderWritable)
				->assign('supportsZipArchives', $supportsZipArchives)
				->assign('permalinksStructureDefined', $permalinksStructureDefined)
				->assign('uploadsFolder', $uploadDir)
				->render();
		} else {

			do_action(self::HOOK . '-saveOptions');

			$this->_view
				->setTemplate('options-page')
				->assign('exportLog', $this->_exportLog)
				->assign('staticExportSettings', $this->_options->getOption('static-export-settings'))
				->assign('wpUploadsDir', wp_upload_dir()['baseurl'])
				->assign('wpPluginDir', plugins_url('/', __FILE__))
				->assign('onceAction', self::HOOK . '-options')
				->render();
		}
	}

	public function saveOptions() {
		if (!isset($_POST['action']) || 'generate' != $_POST['action']) {
			return;
		}

		if (!check_admin_referer(self::HOOK . '-options') || !current_user_can('manage_options')) {
			error_log('user didnt have permissions to change options');
			exit('You cannot change WP Static HTML Output Plugin options.');
		}

		$this->_options
			->setOption('static-export-settings', filter_input(INPUT_POST, 'staticExportSettings', FILTER_SANITIZE_URL))
			->save();

		$message = 'Options have been updated successfully.';

		$this->_view->setTemplate('message')
			->assign('message', $message)
			->render();
	}

	public function get_write_directory(){
		$outputDir = filter_input(INPUT_POST, 'outputDirectory');

		// Check to see if the directory exists
		if ( $outputDir && is_dir($outputDir)) {
			// Makes sure it's writable
			if( is_writable( $outputDir ) ){
				return $outputDir;
			}
		}

		// Default WP Upload Location
		$wp_upload_dir = wp_upload_dir();
		return $wp_upload_dir['path'];
	}

    public function githubFinaliseExport() {
        $client = new \Github\Client();
        $githubRepo = filter_input(INPUT_POST, 'githubRepo');
        $githubBranch = filter_input(INPUT_POST, 'githubBranch');
        $githubPersonalAccessToken = filter_input(INPUT_POST, 'githubPersonalAccessToken');

        # GH user and repo - split from repo field input
        list($githubUser, $githubRepo) = explode('/', $githubRepo);

        $client->authenticate($githubPersonalAccessToken, Github\Client::AUTH_HTTP_TOKEN);

        $reference = $client->api('gitData')->references()->show($githubUser, $githubRepo, 'heads/' . $githubBranch);

        $commit = $client->api('gitData')->commits()->show($githubUser, $githubRepo, $reference['object']['sha']);

        $commitSHA = $commit['sha'];
        $treeSHA = $commit['tree']['sha'];
        $treeURL = $commit['tree']['url'];

        $treeContents = [];

		$wpUploadsDir = wp_upload_dir()['basedir'];
        $githubGlobHashesAndPaths = $wpUploadsDir . '/WP-STATIC-EXPORT-GITHUB-GLOBS-PATHS';

        $contents = file($githubGlobHashesAndPaths);

        foreach($contents as $line) {
            // split line into blob hash and target path
            list($blobHash, $targetPath) = explode(',', $line);

            $treeContents[] = [
                'path' => $targetPath,
                'mode' => '100644',
                'type' => 'blob',
                'sha' => $blobHash
            ];
        }

        $treeData = [
            'base_tree' => $treeSHA,
            'tree' => $treeContents
        ];

        $this->_prependExportLog('GITHUB: Creating tree ...' . PHP_EOL);

        error_log(print_r($treeData, true));

        $newTree = $client->api('gitData')->trees()->create($githubUser, $githubRepo, $treeData);

        $this->_prependExportLog('GITHUB: Tree created');
        
        $commitData = ['message' => 'Static export with date time and info', 'tree' => $newTree['sha'], 'parents' => [$commitSHA]];
        $this->_prependExportLog('GITHUB: Creating commit ...');

        $commit = $client->api('gitData')->commits()->create($githubUser, $githubRepo, $commitData);


        $this->_prependExportLog('GITHUB: Updating head to reference commit ...');

        $referenceData = ['sha' => $commit['sha'], 'force' => true ]; //Force is default false
        try {
            $reference = $client->api('gitData')->references()->update(
                    $githubUser,
                    $githubRepo,
                    'heads/' . $githubBranch,
                    $referenceData);
        } catch (Exception $e) {
            $this->_prependExportLog($e);
            throw new Exception($e);
        }

    }

    // function runs for each line in export file
	public function githubExport() {
        $client = new \Github\Client();
        $githubRepo = filter_input(INPUT_POST, 'githubRepo');
        $githubPersonalAccessToken = filter_input(INPUT_POST, 'githubPersonalAccessToken');
        list($githubUser, $githubRepo) = explode('/', $githubRepo);

        $client->authenticate($githubPersonalAccessToken, Github\Client::AUTH_HTTP_TOKEN);

		$wpUploadsDir = wp_upload_dir()['basedir'];
        $_SERVER['githubFilesToExport'] = $wpUploadsDir . '/WP-STATIC-EXPORT-GITHUB-FILES-TO-EXPORT';

        // grab first line from filelist
        $githubFilesToExport = $_SERVER['githubFilesToExport'];
        $f = fopen($githubFilesToExport, 'r');
        $line = fgets($f);
        fclose($f);

        // remove first line from file (disabled while testing)
        $contents = file($githubFilesToExport, FILE_IGNORE_NEW_LINES);
        $filesRemaining = count($contents) - 1;
        error_log('remaining:');
        error_log($filesRemaining);
        $first_line = array_shift($contents);
        file_put_contents($githubFilesToExport, implode("\r\n", $contents));

        // create the blob
        // first part of line is file to read, second is target path in GH:
        list($fileToExport, $targetPath) = explode(',', $line);
        error_log($fileToExport);
        
        $this->_prependExportLog('GITHUB: Creating blob for ' . rtrim($targetPath));

        $encodedFile = chunk_split(base64_encode(file_get_contents($fileToExport)));

        $globHash = $client->api('gitData')->blobs()->create(
                $githubUser, 
                $githubRepo, 
                ['content' => $encodedFile, 'encoding' => 'base64']
                ); # utf-8 or base64

		$wpUploadsDir = wp_upload_dir()['basedir'];
        $githubGlobHashesAndPaths = $wpUploadsDir . '/WP-STATIC-EXPORT-GITHUB-GLOBS-PATHS';

        $globHashPathLine = $globHash['sha'] . ',' . $targetPath;
        file_put_contents($githubGlobHashesAndPaths, $globHashPathLine, FILE_APPEND | LOCK_EX);


        $this->_prependExportLog('GITHUB: ' . $filesRemaining . ' blobs remaining to create');
        
        echo $filesRemaining;
    }

	public function genArch() {
		$archiveUrl = $this->_generateArchive();

		if (is_wp_error($archiveUrl)) {
			$message = 'Error: ' . $archiveUrl->get_error_code;
		} else {
            $this->_prependExportLog('ZIP CREATED: Download a ZIP of your static site from: ' . $archiveUrl);
			$message = sprintf('Archive created successfully: <a href="%s">Download archive</a>', $archiveUrl);
			if ($this->_options->getOption('retainStaticFiles') == 1) {
				$message .= sprintf('<br />Static files retained at: %s/', str_replace(home_url(),'',substr($archiveUrl,0,-4)));
			}
		}

		#$this->_view->setTemplate('message')
		#	->assign('message', $message)
		#	->assign('exportLog', $this->_exportLog)
		#	->render();
        echo 'Archive has been generated';
	}

	protected function _generateArchive()
	{
		global $blog_id;
		set_time_limit(0);

		$uploadDir = $this->get_write_directory();
		$exporter = wp_get_current_user();
		

		$archiveName = $uploadDir . '/' . self::HOOK . '-' . $blog_id . '-' . time() . '-' . $exporter->user_login;
		$archiveDir = $archiveName . '/';


		$wpUploadsDir = wp_upload_dir()['basedir'];
		$_SERVER['exportStatus'] = $wpUploadsDir . '/WP-STATIC-EXPORT-STATUS';
		$_SERVER['exportLog'] = $wpUploadsDir . '/WP-STATIC-EXPORT-LOG';
		$_SERVER['githubFilesToExport'] = $wpUploadsDir . '/WP-STATIC-EXPORT-GITHUB-FILES-TO-EXPORT';

		if (!file_exists($archiveDir)) {
			wp_mkdir_p($archiveDir);
		}

		// empty status and log files before starting
		unlink($_SERVER['exportStatus']);
		unlink($_SERVER['exportLog']);

		$statusText = 'STARTING EXPORT';
		error_log(file_put_contents($_SERVER['exportStatus'], $statusText , FILE_APPEND | LOCK_EX), 0);


		$baseUrl = untrailingslashit(home_url());
		$newBaseUrl = untrailingslashit(filter_input(INPUT_POST, 'baseUrl', FILTER_SANITIZE_URL));
		$urlsQueue = array_unique(array_merge(
					array(trailingslashit($baseUrl)),
					$this->_getListOfLocalFilesByUrl(array(get_template_directory_uri())),
					explode("\n", filter_input(INPUT_POST, 'additionalUrls'))
					));

		$this->_exportLog = array();

		while (count($urlsQueue))
		{
			$currentUrl = array_shift($urlsQueue);

			$urlResponse = new StaticHtmlOutput_UrlRequest($currentUrl, filter_input(INPUT_POST, 'cleanMeta'));

			if ($urlResponse->checkResponse() == 'FAIL') {
				error_log('Failed to get this file');
				error_log($currentUrl);
                $logText = 'Failed to crawl file: ' . $currentUrl . "\n";
                file_put_contents($_SERVER['exportLog'], $logText , FILE_APPEND | LOCK_EX);
			} else {
				// Add current url to the list of processed urls
                // need to keep old exportLog intact for dependent function below
				$this->_exportLog[$currentUrl] = true;

                $logText = 'CRAWLED FILE: ' . $currentUrl;
                $this->_prependExportLog($logText);
			}

			// TODO: shifting this block into above conditional prevents index containing error
			//       but doesn't crawl all folders...
			//       alternatively, index.html contains 'F' from 'FAIL or 'Failed to get...'

			// TODO: this shouldnt be part of urlrequest, just general settings
			// add conditional logic here whether to do cleanup, vs in each request?
			$urlResponse->cleanup();

			// get all other urls from within this one and add to queue if not there
			foreach ($urlResponse->extractAllUrls($baseUrl) as $newUrl) {
				if (!isset($this->_exportLog[$newUrl]) && $newUrl != $currentUrl && !in_array($newUrl,$urlsQueue)) {
					//echo "Adding ".$newUrl." to the list<br />";
					$urlsQueue[] = $newUrl;
				}
			}

			$urlResponse->replaceBaseUlr($baseUrl, $newBaseUrl);
			$this->_saveUrlData($urlResponse, $archiveDir);
		}

		$tempZip = $archiveName . '.tmp';
		$zipArchive = new ZipArchive();
		if ($zipArchive->open($tempZip, ZIPARCHIVE::CREATE) !== true) {
			return new WP_Error('Could not create archive');
		}

		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($archiveDir));
		foreach ($iterator as $fileName => $fileObject) {
			$baseName = basename($fileName);
			if($baseName != '.' && $baseName != '..') {
				if (!$zipArchive->addFile(realpath($fileName), str_replace($archiveDir, '', $fileName))) {
					return new WP_Error('Could not add file: ' . $fileName);
				}
			}
		}

		$zipArchive->close();
		rename($tempZip, $archiveName . '.zip'); 

		if(filter_input(INPUT_POST, 'sendViaFTP') == 1) {		
			$statusText = 'STARTING FTP UPLOAD';
			file_put_contents($_SERVER['exportStatus'], $statusText , LOCK_EX);
			require_once(__DIR__.'/FTP/FtpClient.php');
			require_once(__DIR__.'/FTP/FtpException.php');
			require_once(__DIR__.'/FTP/FtpWrapper.php');

			$ftp = new \FtpClient\FtpClient();
			
			try {
				$ftp->connect(filter_input(INPUT_POST, 'ftpServer'));
				$ftp->login(filter_input(INPUT_POST, 'ftpUsername'), filter_input(INPUT_POST, 'ftpPassword'));
				$ftp->pasv(true);

				if (!$ftp->isdir(filter_input(INPUT_POST, 'ftpRemotePath'))) {
					$ftp->mkdir(filter_input(INPUT_POST, 'ftpRemotePath'), true);
				}

				$ftp->putAll($archiveName . '/', filter_input(INPUT_POST, 'ftpRemotePath'));
			} catch (Exception $e){
				// write error to log
				file_put_contents($_SERVER['exportLog'], $e , FILE_APPEND | LOCK_EX);
				throw new Exception($e);
			}

			// TODO: error handling when not connected/unable to put, etc
			unset($ftp);
		}

		if(filter_input(INPUT_POST, 'sendViaS3') == 1) {		
			$statusText = 'STARTING S3 UPLOAD';
			file_put_contents($_SERVER['exportStatus'], $statusText , LOCK_EX);
			require_once(__DIR__.'/aws/aws-autoloader.php');
			require_once(__DIR__.'/StaticHtmlOutput/MimeTypes.php');

			function UploadObject($S3, $Bucket, $Key, $Data, $ACL, $ContentType = "text/plain") {
				try {
					$Model = $S3->PutObject(array('Bucket'      => $Bucket,
								'Key'         => $Key,
								'Body'        => $Data,
								'ACL'         => $ACL,
								'ContentType' => $ContentType));
					return true;
				}
				catch (Exception $e) {
					file_put_contents($_SERVER['exportLog'], $e , FILE_APPEND | LOCK_EX);
					throw new Exception($e);
				}
			}

			function UploadDirectory($S3, $Bucket, $dir, $siteroot) {
				$files = scandir($dir);
				foreach($files as $item){
					if($item != '.' && $item != '..'){
						if(is_dir($dir.'/'.$item)) {
							UploadDirectory($S3, $Bucket, $dir.'/'.$item, $siteroot);
						} else if(is_file($dir.'/'.$item)) {
							$clean_dir = str_replace($siteroot, '', $dir.'/'.$item);

							$targetPath = $clean_dir;
							$f = file_get_contents($dir.'/'.$item);

							if($targetPath == '/index.html') {
							}

							UploadObject($S3, $Bucket, $targetPath, $f, 'public-read', GuessMimeType($item));
						} 
					}
				}
			}

			$S3 = Aws\S3\S3Client::factory(array(
						'version'=> '2006-03-01',
						'key'    => filter_input(INPUT_POST, 's3Key'),
						'secret' => filter_input(INPUT_POST, 's3Secret'),
						'region' => filter_input(INPUT_POST, 's3Region')
						)
					);

			$Bucket = filter_input(INPUT_POST, 's3Bucket');

			// Upload the directory to the bucket
			UploadDirectory($S3, $Bucket, $archiveName, $archiveName.'/');

			if(strlen(filter_input(INPUT_POST, 'cfDistributionId'))>12) {
				$CF = Aws\CloudFront\CloudFrontClient::factory(array(
							'version'		=> '2016-01-28',
							'key'           => filter_input(INPUT_POST, 's3Key'),
							'secret'        => filter_input(INPUT_POST, 's3Secret'),
							)
						);
				$result = $CF->createInvalidation(array(
							'DistributionId' => filter_input(INPUT_POST, 'cfDistributionId'),
							'Paths' => array ( 'Quantity' => 1, 'Items' => array('/*')),
																					'CallerReference' => time()
																					));
																					}
			}

			if(filter_input(INPUT_POST, 'sendViaDropbox') == 1) {
				$statusText = 'STARTING Dropbox UPLOAD';
				file_put_contents($_SERVER['exportStatus'], $statusText , LOCK_EX);

				// will exclude the siteroot when copying
				$siteroot = $archiveName . '/';
				$dropboxAppKey = filter_input(INPUT_POST, 'dropboxAppKey');
				$dropboxAppSecret = filter_input(INPUT_POST, 'dropboxAppSecret');
				$dropboxAccessToken = filter_input(INPUT_POST, 'dropboxAccessToken');
				$dropboxFolder = filter_input(INPUT_POST, 'dropboxFolder');


				$app = new DropboxApp($dropboxAppKey, $dropboxAppSecret, $dropboxAccessToken);
				$dbxClient = new Dropbox($app);

				function FolderToDropbox($dir, $dbxClient, $siteroot, $dropboxFolder){
					$files = scandir($dir);
					foreach($files as $item){
						if($item != '.' && $item != '..'){
							if(is_dir($dir.'/'.$item)) {
								FolderToDropbox($dir.'/'.$item, $dbxClient, $siteroot, $dropboxFolder);
							} else if(is_file($dir.'/'.$item)) {
								$clean_dir = str_replace($siteroot, '', $dir.'/'.$item);
								$targetPath =  $dropboxFolder . $clean_dir;


								try {
									$dropboxFile = new DropboxFile($dir.'/'.$item);
									$uploadedFile = $dbxClient->upload($dropboxFile, $targetPath, ['autorename' => true]);
								} catch (Exception $e) {
									file_put_contents($_SERVER['exportLog'], $e , FILE_APPEND | LOCK_EX);
									throw new Exception($e);

								}
							} 
						}
					}
				}

				FolderToDropbox($siteroot, $dbxClient, $siteroot, $dropboxFolder);
			}


					if(filter_input(INPUT_POST, 'sendViaGithub') == 1) {
						$statusText = 'GitHub: Process starting...';
						file_put_contents($_SERVER['exportStatus'], $statusText , LOCK_EX);

                        // empty the list of GH export files in preparation
                        $f = @fopen($_SERVER['githubFilesToExport'], "r+");
                        if ($f !== false) {
                            ftruncate($f, 0);
                            fclose($f);
                        }

                        // empty the list of GH blob hashes in preparation
                        $wpUploadsDir = wp_upload_dir()['basedir'];
                        $githubGlobHashesAndPaths = $wpUploadsDir . '/WP-STATIC-EXPORT-GITHUB-GLOBS-PATHS';
                        $f = @fopen($githubGlobHashesAndPaths, "r+");
                        if ($f !== false) {
                            ftruncate($f, 0);
                            fclose($f);
                        }
                    
                    // optional path within GH repo
					$githubPath = filter_input(INPUT_POST, 'githubPath');


					# 3 - Post file as blob

					$siteroot = $archiveName . '/';


					/* LIMIT DEPTH WHILE TESTING */
					$_SERVER['counter'] = 0;


				function FolderToGithub($dir, $siteroot, $githubPath){
					$files = scandir($dir);
					foreach($files as $item){


                        // exclude ., .. and .git folders
						if($item != '.' && $item != '..' && $item != '.git'){
                            

							if(is_dir($dir.'/'.$item)) {
								FolderToGithub($dir.'/'.$item, $siteroot, $githubPath);
							} else if(is_file($dir.'/'.$item)) {


                                // siteroot: /home/somewebs/public_html/wordpress/wp-content/uploads/2018/01/wp-static-html-output-1-1516441854-leon/
                                // targetPath: ~somewebs/wordpress/beautiful-moalboal-blog/index.html

                                // $_SERVER['REQUEST_URI']: /~somewebs/wordpress/wp-admin/admin-ajax.php

                                // if WP hosted on subdir, remove that from target path 

                                $subdir = str_replace('/wp-admin/admin-ajax.php', '', $_SERVER['REQUEST_URI']);
                                $subdir = ltrim($subdir, '/');
                                
								$clean_dir = str_replace($siteroot . '/', '', $dir.'/'.$item);
                                $clean_dir = str_replace($subdir, '', $clean_dir);

								$targetPath =  $githubPath . $clean_dir;
                                $targetPath = ltrim($targetPath, '/');

                                // add line containing file path and target GH path for subsequent processing
                                $githubExportLine = $dir .'/' . $item . ',' . $targetPath . "\n";
                                file_put_contents($_SERVER['githubFilesToExport'], $githubExportLine, FILE_APPEND | LOCK_EX);
							} 
						}
					}
				}


                $statusText = 'GitHub: Preparing GH export file list...';
                file_put_contents($_SERVER['exportStatus'], $statusText , LOCK_EX);

				FolderToGithub($siteroot, $siteroot, $githubPath);
			}

			if(filter_input(INPUT_POST, 'sendViaNetlify') == 1) {
				$statusText = 'STARTING Netlify UPLOAD';
				file_put_contents($_SERVER['exportStatus'], $statusText , LOCK_EX);
				// will exclude the siteroot when copying
				$siteroot = $archiveName . '/';
				$netlifySiteID = filter_input(INPUT_POST, 'netlifySiteID');
				$netlifyPersonalAccessToken = filter_input(INPUT_POST, 'netlifyPersonalAccessToken');


# get ZIP archive's path

# make Guzzle request to Netlify aka 
#	curl -H "Content-Type: application/zip" \
#	 -H "Authorization: Bearer my-api-access-token" \
#	 --data-binary "@website.zip" \
#	 https://api.netlify.com/api/v1/sites/mysite.netlify.com/deploys


				$client = new Client([
						// Base URI is used with relative requests
						'base_uri' => 'https://api.netlify.com'
				]);	


				try {
					$response = $client->request('POST', '/api/v1/sites/' . $netlifySiteID . '.netlify.com/deploys', [
							'multipart' => [
							[
							'name'     => 'required_for_guzzle_only',
							'contents' => fopen($archiveName . '.zip', 'r'),
							'headers'  => [
							'Content-Type' => 'application/zip',
							'Authorization' => 'Bearer ' . $netlifyPersonalAccessToken
							]
							]
							]
					]);
				} catch (Exception $e) {
					file_put_contents($_SERVER['exportLog'], $e , FILE_APPEND | LOCK_EX);
					throw new Exception($e);
				}
			
				error_log($response->getStatusCode(), 0);
				error_log(print_r($response, true), 0);



			}

            // TODO: need folder to do GH export, force keep for now

			// TODO: keep copy of last export folder for incremental addition

			$retainStaticFiles = filter_input(INPUT_POST, 'retainStaticFiles');

			// Remove temporary files unless user requested to keep or needed for FTP transfer
			//if ($retainStaticFiles != 1)		{
			if ($retainStaticFiles == 'banana')		{
				$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($archiveDir), RecursiveIteratorIterator::CHILD_FIRST);
				foreach ($iterator as $fileName => $fileObject) {

					// Remove file
					if ($fileObject->isDir()) {
						// Ignore special dirs
						$dirName = basename($fileName);
						if($dirName != '.' && $dirName != '..') {
							rmdir($fileName);
						}
					} else {
						unlink($fileName);
					}
				}
				rmdir($archiveDir);
			}	

			return str_replace(ABSPATH, trailingslashit(home_url()), $archiveName . '.zip');
	}

	protected function _getListOfLocalFilesByUrl(array $urls)
	{
		$files = array();

		foreach ($urls as $url) {
			$directory = str_replace(home_url('/'), ABSPATH, $url);

			// checking if url contains the WP site url at first position and is a directory
			if (stripos($url, home_url('/')) === 0 && is_dir($directory)) {
				$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
				foreach ($iterator as $fileName => $fileObject) {

					if (is_file($fileName)) {
						$pathinfo = pathinfo($fileName);
						if (isset($pathinfo['extension']) && !in_array($pathinfo['extension'], array('php', 'phtml', 'tpl'))) {
							array_push($files, home_url(str_replace(ABSPATH, '', $fileName)));
						}
					}
				}
			} else {
				// if is not empty, add full path to $files list
				if ($url != '') {
					array_push($files, $url);
				}
			}
		}

		return $files;
	}

    protected function _prependExportLog($text) {
		$wpUploadsDir = wp_upload_dir()['basedir'];
		$exportLog = $wpUploadsDir . '/WP-STATIC-EXPORT-LOG';
        // efficient way to prepend
        // from https://stackoverflow.com/a/29777782/1668057
        $src = fopen($exportLog, 'r+');
        $dest = fopen('php://temp', 'w');

        fwrite($dest,  date("Y-m-d h:i:s") . ' ' . $text . PHP_EOL);

        stream_copy_to_stream($src, $dest);
        rewind($dest);
        rewind($src);
        stream_copy_to_stream($dest, $src);

        fclose($src);
        fclose($dest);
    }

	protected function _saveUrlData(StaticHtmlOutput_UrlRequest $url, $archiveDir) {
		$urlInfo = parse_url($url->getUrl());
		$pathInfo = pathinfo(isset($urlInfo['path']) && $urlInfo['path'] != '/' ? $urlInfo['path'] : 'index.html');

		// Prepare file directory and create it if it doesn't exist
		$fileDir = $archiveDir . (isset($pathInfo['dirname']) ? $pathInfo['dirname'] : '');

		if (empty($pathInfo['extension']) && $pathInfo['basename'] == $pathInfo['filename']) {
			$fileDir .= '/' . $pathInfo['basename'];
			$pathInfo['filename'] = 'index';
		}

		if (!file_exists($fileDir)) {
			wp_mkdir_p($fileDir);
		}

		$fileExtension = ($url->isHtml() || !isset($pathInfo['extension']) ? 'html' : $pathInfo['extension']);
		$fileName = $fileDir . '/' . $pathInfo['filename'] . '.' . $fileExtension;
		$fileContents = $url->getResponseBody();
		// TODO: fix for unclear issue on PHP5.3
		if ($fileContents != '' && $fileContents != 'F') {
			file_put_contents($fileName, $fileContents);
		} else {
			error_log($fileName);
			error_log('response body was empty');
		}
	}
}
