<?php

namespace Tagcade\Bundle\AppBundle\Command;


use Pheanstalk\PheanstalkInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tagcade\Service\Excel2CSVConverterInterface;
use ZipArchive;

class CreateImporterJobCommand extends ContainerAwareCommand
{
    const DIR_MIN_DEPTH_LEVELS = 2;

    /**
     * @var LoggerInterface
     */
    protected $logger;
    protected $tube;
    protected $watchRoot;
    protected $archivedFiles;
    protected $ttr;


    protected function configure()
    {
        $this
            ->setName('tc:create-importer-job')
            ->setDescription('Scan for relevant files in pre-configured directory and create beantalkd importing job for importer module')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $container = $this->getContainer();
        $converter = $container->get('tagcade_directory_monitor.service.excel_converter_service');
        $this->logger = $container->get('logger');
        $this->tube = $container->getParameter('unified_report_files_tube');

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
            $ttr = \Pheanstalk\PheanstalkInterface::DEFAULT_TTR;
        }

        $this->ttr = $ttr;

        $duplicateFileCount = 0;
        $supportedExtensions = $container->getParameter('supportedExtensions');
        if (!is_array($supportedExtensions)) {
            throw new \Exception('Invalid configuration of param supportedExtensions');
        }

        $newFiles = $this->getNewFiles($duplicateFileCount, $supportedExtensions, $converter);

        $this->logger->info(sprintf('Found %d new files and other %d duplications', count($newFiles), $duplicateFileCount));

        $this->createJob($newFiles, $this->tube, $ttr, $output);

        $this->logger->info('Complete directory process');
    }

    protected function getFileFullPath($filePath)
    {
        $symfonyAppDir = $this->getContainer()->getParameter('kernel.root_dir');
        $isRelativeToProjectRootDir = (strpos($filePath, './') === 0 || strpos($filePath, '/') !== 0);
        $dataPath = $isRelativeToProjectRootDir ? sprintf('%s/%s', rtrim($symfonyAppDir, '/app'), ltrim($filePath, './')) : $filePath;

        return $dataPath;
    }

    protected function getNewFiles(&$duplicateFileCount = 0, $supportedExtensions = ['csv'], Excel2CSVConverterInterface $converter)
    {
        // process zip files
        $this->extractZipFilesIfAny();

        // get all files include the ones in zip
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->watchRoot, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $converter->convert($files);

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
            }
            else {
                $duplicateFileCount ++;
            }
        }

        return $fileList;
    }

    protected function supportFile($fileFullPath, array $supportedExtensions = ['csv'])
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
                    $i ++;
                }
                while(file_exists($newTargetFile));
            }

            mkdir($newTargetFile);
        }

        return $newTargetFile;
    }

    protected function createJob(array $fileList, $tube, $ttr)
    {
        /**
         * @var PheanstalkInterface $pheanstalk
         */
        $pheanstalk = $this->getContainer()->get('leezy.pheanstalk.primary');
        foreach ($fileList as $md5 => $filePath) {
            $fileRelativePath =  trim(str_replace($this->watchRoot, '', $filePath), '/');
            // Extract network name and publisher id from file path
            $dirs = array_reverse(explode('/', $fileRelativePath));

            if(!is_array($dirs) || count($dirs) < self::DIR_MIN_DEPTH_LEVELS) {
                $this->logger->info(sprintf('Not a valid file location at %s. It should be under networkName/publisherId/...', $filePath));
                continue;
            }

            $publisherId = filter_var(array_pop($dirs), FILTER_VALIDATE_INT);
            if (!$publisherId) {
                $this->logger->info(sprintf("Can not extract Publisher from file path %s!!!\n", $filePath));
                continue;
            }

            $partnerCName = array_pop($dirs);
            if (empty($partnerCName)) {
                $this->logger->error(sprintf("Can not extract PartnerCName from file path %s!!!\n", $filePath));
                continue;
            }

            $dates = array_pop($dirs);
            $dates = explode('-', $dates);
            if (count($dates) < 3) {
                $this->logger->error(sprintf('Invalid folder containing csv file. It should has format Ymd-Ymd-Ymd (execution date, report start date, report end date). The file was %s', $filePath));
                continue;
            }

            $reportStartDate = \DateTime::createFromFormat('Ymd', $dates[1]);
            $reportEndDate = \DateTime::createFromFormat('Ymd', $dates[2]);

            if (!$reportStartDate instanceof \DateTime || !$reportEndDate instanceof \DateTime) {
                $this->logger->error(sprintf('Not a valid path structure to file. Expect to have structure /path/to/publishers/{id}/{partnerCName}/YYYYMMDD-YYYYMMDD-YYYYMMDD. The file is %s', $filePath));
                continue;
            }

            $importData = ['filePath' => $filePath, 'publisher' => $publisherId, 'partnerCName' => $partnerCName];
            if ($reportStartDate->format('Ymd') == $reportEndDate->format('Ymd')) {
                $importData['date'] = $reportStartDate->format('Y-m-d');
            }

            $pheanstalk
                ->useTube($tube)
                ->put(
                    json_encode($importData),
                    \Pheanstalk\PheanstalkInterface::DEFAULT_PRIORITY,
                    \Pheanstalk\PheanstalkInterface::DEFAULT_DELAY,
                    $ttr
                )
            ;

            $this->logger->info(sprintf('Job is created for file %s', $filePath));
        }
    }
}