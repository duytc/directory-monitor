<?php

namespace Tagcade\Bundle\AppBundle\Command;


use Exception;
use Pheanstalk\PheanstalkInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\LockHandler;
use Tagcade\Service\TagcadeRestClientInterface;
use Tagcade\Service\URPostFileResultInterface;
use ZipArchive;

class ImporterNewFilesCommand extends ContainerAwareCommand
{
    const DIR_MIN_DEPTH_LEVELS = 2;
    const DIR_SOURCE_MODULE_EMAIL_WEB_HOOK = 'email';
    const DIR_SOURCE_MODULE_FETCHER = 'fetcher';

    public static $SUPPORTED_DIR_SOURCE_MODULE_MAP = [
        self::DIR_SOURCE_MODULE_EMAIL_WEB_HOOK,
        self::DIR_SOURCE_MODULE_FETCHER
    ];

    /** @var LoggerInterface */
    protected $logger;
    protected $emailTemplate; // e.g pub$PUBLISHER_ID$.$TOKEN$@unified-report.dev => will be: pub2.28957425794274267073260979346@unifiedreport.dev
    protected $watchRoot;
    protected $archivedFiles;
    protected $ttr;

    protected $metaFrequency = [];

    /** @var TagcadeRestClientInterface */
    protected $restClient;

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setName('tc:ur:import-new-files')
            ->setDescription('Scan for relevant files in pre-configured directory and post files to unified report api system');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $container = $this->getContainer();
        $this->restClient = $container->get('tagcade_app.service.tagcade.rest_client');
        $this->logger = $container->get('logger');

        // create the lock
        $lock = new LockHandler('ur:post_files_to_unified_report_API');

        if (!$lock->lock()) {
            $this->logger->info(sprintf('%s: The command is already running in another process.', $this->getName()));
            return;
        }

        $this->emailTemplate = $container->getParameter('ur_email_template');
        if (strpos($this->emailTemplate, '$PUBLISHER_ID$') < 0 || strpos($this->emailTemplate, '$TOKEN$') < 0) {
            throw new \Exception(sprintf('ur_email_template %s is invalid config: missing $PUBLISHER_ID$ or $TOKEN$ macro', $this->emailTemplate));
        }

        $this->watchRoot = $this->getFileFullPath($container->getParameter('watch_root'));
        $this->archivedFiles = $this->getFileFullPath($container->getParameter('processed_archived_files'));

        if (!is_dir($this->watchRoot)) {
            if (!mkdir($this->watchRoot)) {
                throw new \Exception(sprintf('Can not create watchRoot directory %s', $this->watchRoot));
            }
        }

        if (!is_dir($this->archivedFiles)) {
            if (!mkdir($this->archivedFiles)) {
                throw new \Exception(sprintf('Can not create archivedFiles directory %s', $this->archivedFiles));
            }
        }

        if (!is_readable($this->watchRoot)) {
            throw new \Exception(sprintf('Watch root is not readable. The full path is %s', $this->watchRoot));
        }

        if (!is_writable($this->archivedFiles)) {
            throw new \Exception(sprintf('Archived path is not writable. The full path is %s', $this->watchRoot));
        }

        $ttr = (int)$container->getParameter('pheanstalk_ttr');
        if ($ttr < 1) {
            $ttr = PheanstalkInterface::DEFAULT_TTR;
        }

        $this->ttr = $ttr;

        $supportedExtensions = $container->getParameter('supported_extensions');
        if (!is_array($supportedExtensions)) {
            throw new \Exception('Invalid configuration of param supported_extensions');
        }

        $newFiles = $this->getNewFiles($supportedExtensions);

        $this->logger->info(sprintf('Found %d new files', count($newFiles)));

        /* post file to unified reports api */
        $this->postFilesToUnifiedReportApi($newFiles);

        $this->logger->info('Complete directory process');
    }

    /**
     * get File Full Path
     *
     * @param string $filePath
     * @return string
     */
    protected function getFileFullPath($filePath)
    {
        $symfonyAppDir = $this->getContainer()->getParameter('kernel.root_dir');
        $isRelativeToProjectRootDir = (strpos($filePath, './') === 0 || strpos($filePath, '/') !== 0);
        $dataPath = $isRelativeToProjectRootDir ? sprintf('%s/%s', rtrim($symfonyAppDir, '/app'), ltrim($filePath, './')) : $filePath;

        return $dataPath;
    }

    /**
     * get new files from watch directory
     *
     * @param array $supportedExtensions
     * @return array format as:
     *
     * [
     *     <file name> => [
     *         "file" => <file path>,
     *         "metadata" => <metadata file path>
     *     ],
     *     ...
     * ]
     *
     */
    protected function getNewFiles(array $supportedExtensions)
    {
        // process zip files
        $this->extractZipFilesIfAny();

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->watchRoot, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        // organize all files into pair file-metadataFile
        /**
         * @var array $organizedFileList , format as:
         * [
         *     <directoryHash> => [
         *         "file" => <file path>,
         *         "metadata" => <metadata file path>
         *     ],
         *     ...
         * ]
         */
        $organizedFileList = [];

        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            $fullFilePath = $file->getRealPath();
            if (!is_file($fullFilePath)) {
                continue;
            }

            if (!$this->supportFile($fullFilePath, $supportedExtensions)) {
                continue;
            }

            $directory = $file->getPath();

            // if current directory has lock file => skip all files under this directory
            $hasLockFile = $this->hasLockFileInFolder($directory);
            if ($hasLockFile) {
                continue;
            }

            /** Count number reports use same metadata */
            $metaDataFilePath = $this->getMetaDataFileFromFolder($directory);
            $metaHash = hash('md5', $metaDataFilePath);
            if (!array_key_exists($metaHash, $this->metaFrequency)) {
                $this->metaFrequency[$metaHash] = 0;
            }
            $this->metaFrequency[$metaHash] += 1;

            $md5 = hash('md5', $fullFilePath);
            $organizedFileList[$md5]['file'] = $fullFilePath;
            $organizedFileList[$md5]['metadata'] = $metaDataFilePath;
        }

        return $organizedFileList;
    }

    /**
     * check if file (with full path) is supported
     *
     * @param string $fileFullPath
     * @param array $supportedExtensions
     * @return bool
     */
    protected function supportFile($fileFullPath, array $supportedExtensions)
    {
        if (empty($fileFullPath)) {
            return false;
        }

        $ext = pathinfo($fileFullPath, PATHINFO_EXTENSION);

        if (!in_array($ext, $supportedExtensions)) {
            return false;
        }

        // special case: files may come from email-webhook that created metadata files for unsupported files
        // e.g: file=abc.jpg and metadata-file=abc.jpg.meta
        // so that we need know these metadata files and allow remove them
        if ($ext === 'meta') {
            $filenameWithoutExtension = pathinfo($fileFullPath, PATHINFO_FILENAME);

            // remove .meta from supported extension
            $supportedExtensionsWithoutMetaExtension = array_filter($supportedExtensions, function ($value) {
                return $value !== 'meta';
            });

            // try to get extension (fake) from filename
            // if filePath=abc.jpg.meta => filenameWithoutExtension=abc.jpg => fakeExt=jpg
            $fakeExt = pathinfo($filenameWithoutExtension, PATHINFO_EXTENSION);
            if (!empty($fakeExt) && !in_array($fakeExt, $supportedExtensionsWithoutMetaExtension)) {
                return false;
            }
        }

        return true;
    }

    /**
     * extract zip files if existed to current their places
     */
    protected function extractZipFilesIfAny()
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->watchRoot, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            $fileFullPath = $file->getRealPath();
            if (!is_file($fileFullPath)) {
                continue;
            }

            if (!in_array(pathinfo($fileFullPath, PATHINFO_EXTENSION), ['zip'])) {
                continue;
            }

            $zip = new ZipArchive();
            $openStatus = $zip->open($fileFullPath);
            if ($openStatus !== true) {
                continue;
            }

            $lastSlashPosition = strrpos($fileFullPath, '/');

            $extractedFolder = $this->getFolderToExtractFile($fileFullPath);
            $this->logger->info(sprintf('Extracting file %s to %s', $fileFullPath, $extractedFolder));

            $res = $zip->extractTo($extractedFolder);
            if ($res === FALSE) {
                $this->logger->error(sprintf('Failed to unzip the file %s', $fileFullPath));
            }

            $zip->close();

            // move to archive folder
            $this->logger->info(sprintf('Moving file %s to archived', $fileFullPath));

            $fileName = substr($fileFullPath, $lastSlashPosition + 1);
            $archived = sprintf('%s/%s', $this->archivedFiles, $fileName);
            rename($fileFullPath, $archived);

            // move metadata file to extracted folder if has
            $currentFolder = pathinfo($fileFullPath, PATHINFO_DIRNAME);
            $filesInCurrentDir = scandir($currentFolder);
            if (!is_array($filesInCurrentDir)) {
                continue;
            }

            foreach ($filesInCurrentDir as $fileInCurrentDir) {
                if (is_dir($fileInCurrentDir)) {
                    continue;
                }

                $ext = pathinfo($fileInCurrentDir, PATHINFO_EXTENSION);
                if ($ext !== 'meta') {
                    continue;
                }

                $fileInCurrentDirFullPath = sprintf('%s/%s', $currentFolder, $fileInCurrentDir);
                $metadataFileNewPath = sprintf('%s/%s', $extractedFolder, $fileInCurrentDir);
                rename($fileInCurrentDirFullPath, $metadataFileNewPath);
            }
        }
    }

    /**
     * get folder to extract file
     *
     * @param string $zipFile
     * @return string $zipFile_without_.zip-timestamp
     */
    protected function getFolderToExtractFile($zipFile)
    {
        $targetFile = rtrim($zipFile, '.zip');
        $newTargetFile = sprintf('%s-%s', $targetFile, (new \DateTime())->getTimestamp());

        if (!is_dir($targetFile)) {
            if (file_exists($newTargetFile)) {
                $i = 1;
                do {
                    $newTargetFile = sprintf('%s(%d)', $targetFile, $i);
                    $i++;
                } while (file_exists($newTargetFile));
            }

            mkdir($newTargetFile);
        }

        return $newTargetFile;
    }

    /**
     * post files to unified report api
     *
     * @param array $fileList
     */
    private function postFilesToUnifiedReportApi(array $fileList)
    {
        foreach ($fileList as $directoryHash => $value) {
            if (!array_key_exists('file', $value) || empty($value['file'])) {
                // report file does not exist, try to get metadata and find out if need download report file from metadata info
                // current this is for supporting Email webhook that has download link only in email body
                $metadataFilePath = array_key_exists('metadata', $value) ? $value['metadata'] : null;
                $metadata = $this->getMetaDataFromFilePath($metadataFilePath);

                if (!isset($metadata['reportFileUrl'])) {
                    // log or alert ...
                    $this->logger->info('Got only Metadata without report file url and not contain "reportFileUrl" data => skip');
                    continue;
                }

                // try process downloading file for email hook only ...
                $reportFileUrl = $metadata['reportFileUrl'];
                $downloadedFilePath = $this->downloadFileFromURL($reportFileUrl, $metadataFilePath);

                if (!$downloadedFilePath) {
                    continue;
                }

                $value['file'] = $downloadedFilePath;
            }

            $filePath = $value['file'];
            $fileRelativePath = trim(str_replace($this->watchRoot, '', $filePath), '/');

            // Extract network name and publisher id from file path
            $dirs = array_reverse(explode('/', $fileRelativePath));

            if (!is_array($dirs) || count($dirs) < self::DIR_MIN_DEPTH_LEVELS) {
                $this->logger->info(sprintf('Not a valid file location at %s. It should be under publisherId/partnerCNameOrToken...', $filePath));
                continue;
            }

            $dirSourceModule = array_pop($dirs); // dirSourceModule: fetcher or email
            if (empty($dirSourceModule)) {
                $this->logger->error(sprintf('Can not extract source module from file path %s!!!', $filePath));
                continue;
            }

            if (!in_array($dirSourceModule, self::$SUPPORTED_DIR_SOURCE_MODULE_MAP)) {
                $this->logger->error(sprintf('Not support for outside of source module %s!!!', implode(' and ', self::$SUPPORTED_DIR_SOURCE_MODULE_MAP)));
                continue;
            }

            $publisherId = filter_var(array_pop($dirs), FILTER_VALIDATE_INT);
            if (!$publisherId) {
                $this->logger->info(sprintf('Can not extract Publisher from file path %s!!!', $filePath));
                continue;
            }

            $partnerCNameOrToken = array_pop($dirs);
            if (empty($partnerCNameOrToken)) {
                $this->logger->error(sprintf('Can not extract PartnerCName or Token from file path %s!!!', $filePath));
                continue;
            }

            $metadataFilePath = array_key_exists('metadata', $value) ? $value['metadata'] : null;
            $metadata = $this->getMetaDataFromFilePath($metadataFilePath);

            /**@var URPostFileResultInterface $postResult */
            $postResult = null;

            /* post file to ur api for data sources */
            if ($dirSourceModule == self::DIR_SOURCE_MODULE_EMAIL_WEB_HOOK) {
                /* create email */
                $emailToken = $partnerCNameOrToken;
                $email = str_replace('$PUBLISHER_ID$', $publisherId, $this->emailTemplate);
                $email = str_replace('$TOKEN$', $emailToken, $email);

                /* get list data sources */
                $dataSourceIds = $this->getDataSourceIdsByEmail($publisherId, $email);
                if (!is_array($dataSourceIds)) {
                    $this->logger->warning(sprintf('No data sources found for this publisher %d and email %s', $publisherId, $email));
                    continue;
                }

                $postResult = $this->restClient->postFileToURApiForDataSourcesViaEmailWebHook($filePath, $metadata, $dataSourceIds);

                $this->logger->info(sprintf('email %s: %s', $email, $postResult->getMessage()));
            } else if ($dirSourceModule == self::DIR_SOURCE_MODULE_FETCHER) {
                // use metadata to filter data source
                $dataSourceIds = $this->getDataSourcesFromMetaData($metadata);

                if (!is_array($dataSourceIds) || count($dataSourceIds) < 1) {
                    unlink($filePath);
                    continue;
                }

                if (!is_array($dataSourceIds)) {
                    $this->logger->warning(sprintf('No data sources found for this publisher %d and partner cname %s', $publisherId, $partnerCNameOrToken));
                    continue;
                }

                $postResult = $this->restClient->postFileToURApiForDataSourcesViaFetcher($filePath, $metadata, $dataSourceIds);

                $this->logger->info(sprintf('fetcher partner %s: %s', $partnerCNameOrToken, $postResult->getMessage()));
            }

            if (!$postResult instanceof URPostFileResultInterface) {
                continue;
            }

            if ($postResult->getStatusCode() != 200) {
                $this->logger->warning(sprintf('Post file failure for %s, keep file to try again later', $filePath));
                continue;
            }

            /* move file to processed folder */
            $this->moveFileToProcessDir($filePath, $publisherId, $partnerCNameOrToken);

            //Check if one meta use for many report (Spring Serve)
            $metaHash = hash('md5', $metadataFilePath);
            if (!array_key_exists($metaHash, $this->metaFrequency)) {
                continue;
            }

            $this->metaFrequency[$metaHash] -= 1;

            if ($this->metaFrequency[$metaHash] > 0) {
                continue;
            }

            /* also move metadata file (if existed) to processed folder */
            if (file_exists($metadataFilePath) && is_readable($metadataFilePath)) {
                $this->moveFileToProcessDir($metadataFilePath, $publisherId, $partnerCNameOrToken);
            }
        }
    }

    /**
     * get DataSource Ids by email
     *
     * @param int $publisherId
     * @param string $email
     * @return array|bool false if no data source found
     */
    private function getDataSourceIdsByEmail($publisherId, $email)
    {
        /* get list data sources */
        try {
            $dataSources = $this->restClient->getListDataSourcesByEmail($publisherId, $email);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            return false;
        }

        if (!is_array($dataSources)) {
            return false;
        }

        /* convert to data source ids */
        $dataSourceIds = [];
        foreach ($dataSources as $dataSource) {
            if (!is_array($dataSource) || !array_key_exists('id', $dataSource)) {
                continue;
            }

            $dataSourceIds[] = $dataSource['id'];
        }

        return count($dataSourceIds) > 0 ? $dataSourceIds : false;
    }

    /**
     * move file to processed dir
     *
     * @param $filePath
     * @param $publisherId
     * @param $partnerCNameOrToken
     */
    private function moveFileToProcessDir($filePath, $publisherId, $partnerCNameOrToken)
    {
        $newFileToStore = $this->getProcessedFilePath($filePath, $publisherId, $partnerCNameOrToken, $this->archivedFiles);
        $this->logger->info(sprintf('Moving "%s" to "%s"', $filePath, $newFileToStore));
        rename($filePath, $newFileToStore);
    }

    /**
     * get Processed File Path
     *
     * @param $filePath
     * @param $publisherId
     * @param $partnerCName
     * @param $processedFolder
     * @return string
     */
    private function getProcessedFilePath($filePath, $publisherId, $partnerCName, $processedFolder)
    {
        $folder = join('/', array(
                $processedFolder,
                $publisherId,
                $partnerCName,
                sprintf('%s', (new \DateTime('today'))->format('Ymd')))
        );

        if (!file_exists($folder)) {
            mkdir($folder, 0755, true);
        }

        $pathInfo = pathinfo($filePath);

        return join('/', array($folder, $pathInfo['basename']));
    }

    /**
     * @param array $metaData
     * @return array|bool false if no data source found
     */
    private function getDataSourcesFromMetaData(array $metaData)
    {
        if (array_key_exists('dataSourceId', $metaData)) {
            return [$metaData['dataSourceId']];
        }

        return false;
    }

    /**
     * @param string $folder
     * @return string|bool return metadata file path, false if input invalid or metadata file error
     */
    private function getMetaDataFileFromFolder($folder)
    {
        if (!is_string($folder) || empty($folder)) {
            return false;
        }

        $subFiles = scandir($folder);

        $subFiles = array_map(function ($subFile) use ($folder) {
            return $folder . '/' . $subFile;
        }, $subFiles);

        $subFiles = array_filter($subFiles, function ($file) {
            return is_file($file);
        });

        foreach ($subFiles as $subFile) {
            $subFile = new \SplFileInfo($subFile);
            if ($subFile->getExtension() == 'meta') {
                $metadataFilePath = $subFile->getRealPath();

                // not need get content here. TODO: remove
                //$metadata = file_get_contents($metadataFilePath);
                //json_decode($metadata, true);
                //
                //if (json_last_error() !== JSON_ERROR_NONE) {
                //    return [];
                //}

                return $metadataFilePath;
            }
        }

        return false;
    }

    /**
     * @param $metadataFilePath
     * @return array
     */
    private function getMetaDataFromFilePath($metadataFilePath)
    {
        $metadata = [];
        if (is_string($metadataFilePath) && file_exists($metadataFilePath) && is_readable($metadataFilePath)) {
            $metadata = file_get_contents($metadataFilePath);
            $metadata = json_decode($metadata, true);

            if (json_last_error() !== JSON_ERROR_NONE || !$metadata) {
                $metadata = [];
            }
        }

        return $metadata;
    }

    /**
     * @param string $folder
     * @return bool
     */
    private function hasLockFileInFolder($folder)
    {
        if (empty($folder) || !is_string($folder)) {
            return false;
        }

        $subFiles = scandir($folder);

        $subFiles = array_map(function ($subFile) use ($folder) {
            return $folder . '/' . $subFile;
        }, $subFiles);

        $subFiles = array_filter($subFiles, function ($file) {
            return is_file($file);
        });

        foreach ($subFiles as $subFile) {
            $subFile = new \SplFileInfo($subFile);
            if ($subFile->getExtension() == 'lock') {
                return true;
            }
        }

        return false;
    }

    /**
     * Download File From URL
     *
     * @param string $url
     * @param string $metaDataFilePath
     * @return string|false string as downloaded file path, false if not download success
     */
    private function downloadFileFromURL($url, $metaDataFilePath)
    {
        if (empty($url) || empty($metaDataFilePath) || !is_string($url) || !is_string($metaDataFilePath)) {
            return false;
        }

        $extension = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
        $newFileName = str_replace('.meta', "", $metaDataFilePath);
        $newFileName = sprintf('%s.%s', $newFileName, $extension);

        try {
            $file = fopen($url, 'rb');
            if ($file) {
                $newFile = fopen($newFileName, 'wb');
                if ($newFile) {
                    while (!feof($file)) {
                        fwrite($newFile, fread($file, 1024 * 8), 1024 * 8);
                    }
                }
            } else {
                return false;
            }

            if ($file) {
                fclose($file);
            }

            if ($newFile) {
                fclose($newFile);
            }

            return $newFileName;
        } catch (Exception $e) {
            return false;
        }
    }
}