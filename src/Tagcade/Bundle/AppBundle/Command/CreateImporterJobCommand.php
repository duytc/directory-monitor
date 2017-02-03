<?php

namespace Tagcade\Bundle\AppBundle\Command;


use Pheanstalk\PheanstalkInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tagcade\Service\TagcadeRestClientInterface;
use ZipArchive;

class CreateImporterJobCommand extends ContainerAwareCommand
{
    const DIR_MIN_DEPTH_LEVELS = 2;
    const DIR_VIA_MODULE_EMAIL_WEB_HOOK = 'email';
    const DIR_VIA_MODULE_FETCHER = 'fetcher';

    public static $SUPPORTED_DIR_VIA_MODULE_MAP = [
        self::DIR_VIA_MODULE_EMAIL_WEB_HOOK,
        self::DIR_VIA_MODULE_FETCHER
    ];

    /** @var LoggerInterface */
    protected $logger;
    protected $emailTemplate; // e.g pub$PUBLISHER_ID$.$TOKEN$@unified-report.dev => will be: pub2.28957425794274267073260979346@unifiedreport.dev
    protected $watchRoot;
    protected $archivedFiles;
    protected $ttr;

    /** @var TagcadeRestClientInterface */
    protected $restClient;

    protected function configure()
    {
        $this
            ->setName('tc:create-importer-job')
            ->setDescription('Scan for relevant files in pre-configured directory and post files to unified report api system');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $container = $this->getContainer();
        $this->restClient = $container->get('tagcade_app.service.tagcade.rest_client');
        $this->logger = $container->get('logger');
        $this->emailTemplate = $container->getParameter('ur_email_template');
        if (strpos($this->emailTemplate, '$PUBLISHER_ID$') < 0 || strpos($this->emailTemplate, '$TOKEN$') < 0) {
            throw new \Exception(sprintf('ur_email_template %s is invalid config: missing $PUBLISHER_ID$ or $TOKEN$ macro', $this->emailTemplate));
        }

        $this->watchRoot = $this->getFileFullPath($container->getParameter('watch_root'));
        $this->archivedFiles = $this->getFileFullPath($container->getParameter('processed_archived_files'));

        if (!is_dir($this->watchRoot)) {
            mkdir($this->watchRoot);
        }

        if (!is_dir($this->archivedFiles)) {
            mkdir($this->archivedFiles);
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

        $duplicateFileCount = 0;
        $supportedExtensions = $container->getParameter('supported_extensions');
        if (!is_array($supportedExtensions)) {
            throw new \Exception('Invalid configuration of param supported_extensions');
        }

        $newFiles = $this->getNewFiles($duplicateFileCount, $supportedExtensions);

        $this->logger->info(sprintf('Found %d new files and other %d duplications', count($newFiles), $duplicateFileCount));

        /* post file to unified reports api */
        $this->postFilesToUnifiedReportApi($newFiles);

        $this->logger->info('Complete directory process');
    }

    protected function getFileFullPath($filePath)
    {
        $symfonyAppDir = $this->getContainer()->getParameter('kernel.root_dir');
        $isRelativeToProjectRootDir = (strpos($filePath, './') === 0 || strpos($filePath, '/') !== 0);
        $dataPath = $isRelativeToProjectRootDir ? sprintf('%s/%s', rtrim($symfonyAppDir, '/app'), ltrim($filePath, './')) : $filePath;

        return $dataPath;
    }

    protected function getNewFiles(&$duplicateFileCount = 0, $supportedExtensions = ['csv', 'xls', 'xlsx'])
    {
        // process zip files
        $this->extractZipFilesIfAny();

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->watchRoot, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $fileList = [];
        $duplicateFileCount = 0;
        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            $fileFullPath = $file->getRealPath();
            if (!is_file($fileFullPath)) {
                continue;
            }

            if (!$this->supportFile($fileFullPath, $supportedExtensions)) {
                continue;
            }

            $md5 = hash_file('md5', $fileFullPath);
            if (!array_key_exists($md5, $fileList)) {
                $fileList[$md5] = $fileFullPath;
            } else {
                $duplicateFileCount++;
            }
        }

        return $fileList;
    }

    protected function supportFile($fileFullPath, array $supportedExtensions = ['csv', 'xls', 'xlsx'])
    {
        if (empty($fileFullPath)) {
            return false;
        }

        foreach ($supportedExtensions as $ext) {
            $expectPositionOfExt = strlen($fileFullPath) - strlen($ext);
            $foundPositionOfExt = strrpos($fileFullPath, $ext);
            if ($foundPositionOfExt !== false && $expectPositionOfExt === $foundPositionOfExt) {
                return true;
            }
        }

        return false;
    }

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

            $targetFile = $this->getFolderToExtractFile($fileFullPath);
            $this->logger->info(sprintf('Extracting file %s to %s', $fileFullPath, $targetFile));

            $res = $zip->extractTo($targetFile);
            if ($res === FALSE) {
                $this->logger->error(sprintf('Failed to unzip the file %s', $fileFullPath));
            }

            $zip->close();

            // move to archive folder
            $this->logger->info(sprintf('Moving file %s to archived', $fileFullPath));

            $fileName = substr($fileFullPath, $lastSlashPosition + 1);
            $archived = sprintf('%s/%s', $this->archivedFiles, $fileName);
            rename($fileFullPath, $archived);
        }
    }

    protected function getFolderToExtractFile($zipFile)
    {
        $targetFile = rtrim($zipFile, '.zip');
        $newTargetFile = $targetFile;

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
        foreach ($fileList as $md5 => $filePath) {
            $fileRelativePath = trim(str_replace($this->watchRoot, '', $filePath), '/');

            // Extract network name and publisher id from file path
            $dirs = array_reverse(explode('/', $fileRelativePath));

            if (!is_array($dirs) || count($dirs) < self::DIR_MIN_DEPTH_LEVELS) {
                $this->logger->info(sprintf('Not a valid file location at %s. It should be under networkName/publisherId/...', $filePath));
                continue;
            }

            $dirViaModule = array_pop($dirs);
            if (empty($dirViaModule)) {
                $this->logger->error(sprintf("Can not extract viaModule from file path %s!!!\n", $filePath));
                continue;
            }

            if (!in_array($dirViaModule, self::$SUPPORTED_DIR_VIA_MODULE_MAP)) {
                $this->logger->error(sprintf("Not support for outside of viaModule %s!!!\n", implode(' and ', self::$SUPPORTED_DIR_VIA_MODULE_MAP)));
                continue;
            }

            $publisherId = filter_var(array_pop($dirs), FILTER_VALIDATE_INT);
            if (!$publisherId) {
                $this->logger->info(sprintf("Can not extract Publisher from file path %s!!!\n", $filePath));
                continue;
            }

            $partnerCNameOrToken = array_pop($dirs);
            if (empty($partnerCNameOrToken)) {
                $this->logger->error(sprintf("Can not extract PartnerCName or Token from file path %s!!!\n", $filePath));
                continue;
            }

            /* post file to ur api for data sources */
            if ($dirViaModule == self::DIR_VIA_MODULE_EMAIL_WEB_HOOK) {
                /* create email */
                $emailToken = $partnerCNameOrToken;
                $email = str_replace('$PUBLISHER_ID$', $publisherId, $this->emailTemplate);
                $email = str_replace('$TOKEN$', $emailToken, $email);

                /* get list data sources */
                $dataSourceIds = $this->getDataSourceIdsByEmail($publisherId, $email);

                $postResult = $this->restClient->postFileToURApiForDataSourcesViaEmailWebHook($filePath, $dataSourceIds);

                $this->logger->info(sprintf('email %s: %s', $email, $postResult));
            } else if ($dirViaModule == self::DIR_VIA_MODULE_FETCHER) {
                /* get list data sources */
                $dataSourceIds = $this->getDataSourceIdsByIntegration($publisherId, $partnerCNameOrToken);
                if (!is_array($dataSourceIds)) {
                    $this->logger->warning(sprintf('No data sources found for this publisher %d and partner cname %s', $publisherId, $partnerCNameOrToken));
                    continue;
                }

                $postResult = $this->restClient->postFileToURApiForDataSourcesViaFetcher($filePath, $dataSourceIds);

                $this->logger->info(sprintf('fetcher partner %s: %s', $partnerCNameOrToken, $postResult));
            }

            /* move file to processed folder */
            $newFileToStore = $this->getProcessedFilePath($filePath, $publisherId, $partnerCNameOrToken, $this->archivedFiles);
            $this->logger->info(sprintf('Moving "%s" to "%s"', $filePath, $newFileToStore));
            rename($filePath, $newFileToStore);
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
        $dataSources = $this->restClient->getListDataSourcesByEmail($publisherId, $email);

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
     * get DataSource Ids
     *
     * @param int $publisherId
     * @param string $partnerCName
     * @return array|bool false if no data source found
     */
    private function getDataSourceIdsByIntegration($publisherId, $partnerCName)
    {
        /* get list data sources */
        $dataSources = $this->restClient->getListDataSourcesByIntegration($publisherId, $partnerCName);

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
}