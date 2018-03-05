<?php

namespace Tagcade\Bundle\AppBundle\Command;

use Exception;
use Pheanstalk\PheanstalkInterface;
use PHPExcel;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tagcade\Service\RedLock;
use Tagcade\Service\RetryCycleService;
use Tagcade\Service\TagcadeRestClientInterface;
use Tagcade\Service\URPostFileResultInterface;
use ZipArchive;

class ImporterNewFilesCommand extends ContainerAwareCommand
{
    const DIR_MIN_DEPTH_LEVELS = 2;
    const DIR_SOURCE_MODULE_EMAIL_WEB_HOOK = 'email';
    const DIR_SOURCE_MODULE_FETCHER = 'fetcher';

    const EXTENSION_META = 'meta';
    const EXTENSION_LOCK = 'lock';
    const JOB_LOCK_TTL = (30 * 60 * 1000); // 30 minutes expiry time for lock

    public static $SUPPORTED_DIR_SOURCE_MODULE_MAP = [
        self::DIR_SOURCE_MODULE_EMAIL_WEB_HOOK,
        self::DIR_SOURCE_MODULE_FETCHER
    ];

    /** @var LoggerInterface */
    protected $logger;
    protected $emailTemplate; // e.g pub$PUBLISHER_ID$.$TOKEN$@unified-report.dev => will be: pub2.28957425794274267073260979346@unifiedreport.dev
    protected $watchRoot;
    protected $archivedFiles;
    protected $invalidFiles;
    protected $ttr;

    protected $metadataFrequency = [];

    /** @var TagcadeRestClientInterface */
    protected $restClient;
    /**
     * @var RedLock
     */
    protected $redLock;

    /**
     * @var RetryCycleService
     */
    protected $retryCycleService;
    protected $maxRetryFile;
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
        $this->redLock = $container->get('tagcade.service.red_lock');

        $this->restClient = $container->get('tagcade_app.service.tagcade.rest_client');
        $this->retryCycleService = $container->get('tagcade.service.retry_cycle_service');
        $this->logger = $container->get('logger');
        $this->maxRetryFile = intval($container->getParameter('tagcade.max_retry_file'));

        // create the lock
        $pid = getmypid();
        $lock = $this->redLock->lock('ur:post_files_to_unified_report_API', self::JOB_LOCK_TTL, [
            'pid' => $pid
        ]);

        if ($lock === false) {
            $this->logger->info(sprintf('%s: The command is already running in another process.', $this->getName()));
            return;
        }

        $this->emailTemplate = $container->getParameter('ur_email_template');
        if (strpos($this->emailTemplate, '$PUBLISHER_ID$') < 0 || strpos($this->emailTemplate, '$TOKEN$') < 0) {
            throw new \Exception(sprintf('ur_email_template %s is invalid config: missing $PUBLISHER_ID$ or $TOKEN$ macro', $this->emailTemplate));
        }

        $this->watchRoot = $this->getFileFullPath($container->getParameter('watch_root'));
        $this->archivedFiles = $this->getFileFullPath($container->getParameter('processed_archived_files'));
        $this->invalidFiles = $this->getFileFullPath($container->getParameter('invalid_archived_files'));

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

        $supportedExtensions = $container->getParameter('supported_extensions');
        if (!is_array($supportedExtensions)) {
            throw new \Exception('Invalid configuration of param supported_extensions');
        }

        $totalNewFiles = 0;
        $newFiles = $this->getNewFiles($supportedExtensions, $totalNewFiles);

        $this->logger->info(sprintf('Found %d new files', $totalNewFiles));

        /* post file to unified reports api */
        $this->postFilesToUnifiedReportApi($newFiles);

        $this->redLock->unlock($lock);
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
     * @param $newFiles
     * @return array format as:
     *
     * [
     * [
     * "file" => <file path>,
     * "metadata" => <metadata file path>
     * ],
     * ...
     * ]
     */
    protected function getNewFiles(array $supportedExtensions, &$newFiles)
    {
        // process zip files
        $this->extractZipFilesIfAny();

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->watchRoot, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        // build fileList for each directory
        // notice: may be many report files with only one metadata in directory (e.g Spring Serve for account report)
        /**
         * @var array $fileList , format as:
         * [
         *     <directoryHash> => [
         *         "files" => [<file path>, ...],
         *         "metadata" => <metadata file path>
         *     ],
         *     ...
         * ]
         */
        $fileList = [];

        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            $fullFilePath = $file->getRealPath();
            if (!is_file($fullFilePath)) {
                continue;
            }

            $newFiles++;
            if (!$this->supportFile($fullFilePath, $supportedExtensions)) {
                continue;
            }

            $directory = $file->getPath();

            // if current directory has lock file => skip all files under this directory
            $hasLockFile = $this->hasLockFileInFolder($directory);
            if ($hasLockFile) {
                continue;
            }

            $directoryHash = hash('md5', $directory);

            /**
             * very important: may metadata file or report file comes before each other
             * so that very careful when build $organizedFileList
             */

            if ($file->getExtension() === self::EXTENSION_META) {
                // if metadata file
                if (!array_key_exists($directoryHash, $fileList) || !array_key_exists('files', $fileList[$directoryHash])) {
                    // init if not yet have key 'file' in $organizedFileList
                    // this is needed for case standalone metadata file
                    $fileList[$directoryHash]['files'] = [];
                }

                if (!array_key_exists($directoryHash, $fileList) || !array_key_exists('metadata', $fileList[$directoryHash])) {
                    // init if not yet have key 'metadata' in $organizedFileList
                    // use directly path instead of getting by getMetaDataFileFromFolder()
                    $fileList[$directoryHash]['metadata'] = $fullFilePath;
                }
            } else {
                // if report file
                $reportFilePath = $fullFilePath;

                $metaDataFilePath = (array_key_exists($directoryHash, $fileList) && array_key_exists('metadata', $fileList[$directoryHash]))
                    ? $fileList[$directoryHash]['metadata'] // metadata is already in $organizedFileList
                    : $this->getMetaDataFileFromFolder($directory); // metadata IS NOT already in $organizedFileList

                if (!empty($metaDataFilePath)) {
                    // Count number reports use same metadata
                    $metadataHash = $this->getMetadataHash($metaDataFilePath);
                    if (!array_key_exists($metadataHash, $this->metadataFrequency)) {
                        $this->metadataFrequency[$metadataHash] = 0;
                    }
                    $this->metadataFrequency[$metadataHash] += 1;
                }

                if (!array_key_exists($directoryHash, $fileList) || !array_key_exists('files', $fileList[$directoryHash])) {
                    $fileList[$directoryHash]['files'] = []; // init if not yet have key 'file' in $organizedFileList
                }
                $fileList[$directoryHash]['files'][] = $reportFilePath;
                $fileList[$directoryHash]['metadata'] = $metaDataFilePath;
            }
        }

        // organize into pairs file-metadata
        // notice: may be report file and metadata stand alone
        /**
         * @var array $organizedFileList , format as:
         * [
         *     [
         *         "file" => <file path>,
         *         "metadata" => <metadata file path>
         *     ],
         *     ...
         * ]
         */
        $organizedFileList = [];
        foreach ($fileList as $directoryHash => $item) {
            if (!array_key_exists('files', $item) || !array_key_exists('metadata', $item)) {
                continue;
            }

            $reportFilePaths = $item['files'];
            $metaDataFilePath = $item['metadata'];

            if (!is_array($reportFilePaths)) {
                continue;
            }

            foreach ($reportFilePaths as $reportFilePath) {
                $organizedFileList[] = [
                    'file' => $reportFilePath,
                    'metadata' => $metaDataFilePath,
                ];
            }

            /** For case alone metadata from email web hook */
            if (count($reportFilePaths) < 1) {
                $organizedFileList[] = [
                    'file' => null,
                    'metadata' => $metaDataFilePath,
                ];
            }
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
        if ($ext === self::EXTENSION_META) {
            $filenameWithoutExtension = pathinfo($fileFullPath, PATHINFO_FILENAME);

            // remove .meta from supported extension
            $supportedExtensionsWithoutMetaExtension = array_filter($supportedExtensions, function ($value) {
                return $value !== self::EXTENSION_META;
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
                if ($ext !== self::EXTENSION_META) {
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
        foreach ($fileList as $index => $value) {
            if (!array_key_exists('file', $value) || empty($value['file'])) {
                // report file does not exist, try to get metadata and find out if need download report file from metadata info
                // current this is for supporting Email webhook that has download link only in email body
                $metadataFilePath = array_key_exists('metadata', $value) ? $value['metadata'] : null;
                $metadata = $this->getMetaDataFromFilePath($metadataFilePath);

                if (!isset($metadata['reportFileUrl'])) {
                    // log or alert ...
                    $this->logger->info('Got only Metadata without report file url and not contain "reportFileUrl" data => skip');
                    $this->moveFileToInvalidDir($metadataFilePath, $metadata);
                    continue;
                }

                // try process downloading file for email hook only ...
                $reportFileUrl = $metadata['reportFileUrl'];
                try {
                    $downloadedFilePath = $this->downloadFileFromURL($reportFileUrl, $metadataFilePath);
                } catch (\Exception $exception) {
                    $downloadedFilePath = null;
                }

                if (!$downloadedFilePath) {
                    $this->logger->info(sprintf('can not download report from "%s"', $reportFileUrl));
                    if ($this->retryCycleService->getRetryCycleForFile($metadataFilePath) >= $this->maxRetryFile) {
                        $this->moveFileToInvalidDir($metadataFilePath, $metadata);
                        $this->retryCycleService->removeRetryCycleKey($metadataFilePath);
                        continue;
                    }

                    $this->retryCycleService->increaseRetryCycleForFile($metadataFilePath);
                    continue;
                }

                $value['file'] = $downloadedFilePath;
            }

            $filePath = $value['file'];

            $this->logger->info(sprintf("Starting to process file %s", $filePath));

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

                try {
                    $postResult = $this->restClient->postFileToURApiForDataSourcesViaEmailWebHook($filePath, $metadata, $dataSourceIds);
                    $postFail = $postResult->getStatusCode() != 200;
                } catch (Exception $e) {
                    $this->logger->warning(sprintf('Post file failure for %s, keep file to try again later', $filePath));
                    $this->logger->error($e);
                    $postFail = true;
                }

                if ($postFail) {
                    if ($this->retryCycleService->getRetryCycleForFile($filePath) >= $this->maxRetryFile) {
                        $this->moveFileToProcessDir($filePath, $publisherId, $partnerCNameOrToken);
                        $this->moveMetadataFileToProcessDir($metadataFilePath, $publisherId, $partnerCNameOrToken);
                        $this->retryCycleService->removeRetryCycleKey($filePath);
                        continue;
                    }

                    $this->retryCycleService->increaseRetryCycleForFile($filePath);
                    continue;
                }

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

                $empty = $this->checkFileIsEmptyOrNot($filePath);
                if ($empty) {
                    // file is empty
                    $this->logger->warning(sprintf('Due to file(%s) is empty. So this file did not upload to UR, move file to Process Dir', $filePath));
                } else {
                    try {
                        $postResult = $this->restClient->postFileToURApiForDataSourcesViaFetcher($filePath, $metadata, $dataSourceIds);
                        $postFail = $postResult->getStatusCode() != 200;
                    } catch (Exception $e) {
                        $this->logger->warning(sprintf('Post file failure for %s, keep file to try again later', $filePath));
                        $this->logger->error($e);
                        $postFail = true;
                    }

                    // log file to figure out
                    $this->logger->info(sprintf('metadata file is %s, contents: %s', $metadataFilePath, json_encode($metadata)));
                    $this->logger->info(sprintf('fetcher partner %s: %s', $partnerCNameOrToken, $postResult->getMessage()));

                    if ($postFail) {
                        $this->logger->warning(sprintf('Post file failure for %s, keep file to try again later', $filePath));
                        $this->logger->error($postResult->getMessage());

                        if ($this->retryCycleService->getRetryCycleForFile($filePath) >= $this->maxRetryFile) {
                            $this->moveFileToProcessDir($filePath, $publisherId, $partnerCNameOrToken);
                            $this->retryCycleService->removeRetryCycleKey($filePath);
                            $this->moveMetadataFileToProcessDir($metadataFilePath, $publisherId, $partnerCNameOrToken);
                            continue;
                        }

                        $this->retryCycleService->increaseRetryCycleForFile($filePath);
                        continue;
                    }
                }
            }

            /* move file to processed folder */
            $this->moveFileToProcessDir($filePath, $publisherId, $partnerCNameOrToken);
            $this->moveMetadataFileToProcessDir($metadataFilePath, $publisherId, $partnerCNameOrToken);
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

    private function moveMetadataFileToProcessDir($metadataFilePath, $publisherId, $partnerCNameOrToken)
    {
        /*
         * also move metadata file (if needed and existed) to processed folder
         * notice: one meta may be used for many report files (e.g Spring Serve for account report)
         */
        $isNeedRemoveMetadataFile = true;
        $metadataHash = $this->getMetadataHash($metadataFilePath);
        if (array_key_exists($metadataHash, $this->metadataFrequency)) {
            $this->metadataFrequency[$metadataHash]--;

            if ($this->metadataFrequency[$metadataHash] > 0) {
                $isNeedRemoveMetadataFile = false; // not need remove if still have report files relate to this metadata file
            }
        }

        if ($isNeedRemoveMetadataFile && file_exists($metadataFilePath) && is_readable($metadataFilePath)) {
            $this->moveFileToProcessDir($metadataFilePath, $publisherId, $partnerCNameOrToken);
        }
    }

    /**
     * @param $filePath
     * @param $metadata
     * @internal param $publisherId
     * @internal param $partnerCNameOrToken
     */
    private function moveFileToInvalidDir($filePath, $metadata)
    {
        if (array_key_exists('publisherId', $metadata) && array_key_exists('integrationCName', $metadata)) {
            $newFileToStore = $this->getProcessedFilePath($filePath, $metadata['publisherId'], $metadata['integrationCName'], $this->invalidFiles);
            $this->logger->info(sprintf('Moving "%s" to "%s"', $filePath, $newFileToStore));
            rename($filePath, $newFileToStore);
        } else {
            try {
                unlink($filePath);
            } catch (\Exception $exception) {
                $this->logger->error($exception->getMessage());
            }
        }
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
            if ($subFile->getExtension() == self::EXTENSION_META) {
                return $subFile->getRealPath();
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
            if ($subFile->getExtension() == self::EXTENSION_LOCK) {
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

        $url = trim($url);

        // ensure there is a http or https protocol, this may cause problems with ftp links but we haven't seen any yet
        if (strpos($url, 'http') !== 0) {
            $url = 'http://' . $url;
        }

        $extension = $this->getExtensionOfReportFromURL($url);

        /** Quit when could not detect extension from URL */
        if (empty($extension)) {
            return false;
        }

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
            $this->logger->error(sprintf('Failed to download report file "%s"', $url));
            return false;
        }
    }

    /**
     * get Metadata Hash from file path
     * @param string $metadataFilePath
     * @return string
     */
    private function getMetadataHash($metadataFilePath)
    {
        return hash('md5', $metadataFilePath);
    }

    /**
     * @param $url
     * @return string|null|false
     */
    private function getExtensionOfReportFromURL($url)
    {
        $extension = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);

        if (!empty($extension)) {
            return $extension;
        }

        $headers = $this->getHeaders($url);

        $contentType = preg_replace('/;.*/', '', $headers['content_type']);

        switch ($contentType) {
            case 'text/csv':
            case 'text/csv;charset=UTF-8':
                $extension = 'csv';
                break;
            case 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':
                $extension = 'xls';
                break;
            case 'text/xls':
                $extension = 'xls';
                break;
            case 'text/xlsx':
                $extension = 'xlsx';
                break;
            case 'text/json':
                $extension = 'json';
                break;
            case 'text/zip':
                $extension = 'zip';
                break;
            default:
                return false;
        }

        return $extension;
    }

    /**
     * Get Headers function
     * @param string $url
     * @return array
     */
    private function getHeaders($url)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_exec($ch);
        $headers = curl_getinfo($ch);
        curl_close($ch);

        return $headers;
    }

    /**
     * check file is empty or not
     * @param string $filePath
     * @return boolean
     */
    private function checkFileIsEmptyOrNot($filePath)
    {
        $fileTypeSupport = ['xls', 'xlsx', 'csv'];
        $fileTypeSupportExcel = ['xls', 'xlsx'];
        $fileType = pathinfo($filePath, PATHINFO_EXTENSION);

        if (!in_array($fileType, $fileTypeSupport)) {
            return true;
        }

        $empty = false;
        if (in_array($fileType, $fileTypeSupportExcel)) {
            $objPHPExcel = new PHPExcel();
            $content = $objPHPExcel->getActiveSheet()->toArray();
            $empty = !is_array($content) || empty($content) || (count($content) === 1 && !isset($content[0]) || !isset($content[0][0]));
        }

        if ($fileType == 'csv') {
            $content = file($filePath);
            $empty = !is_array($content) || empty($content) || (count($content) === 1 && empty($content[0]) || $content[0] == "\n");
        }

        return $empty;
    }
}