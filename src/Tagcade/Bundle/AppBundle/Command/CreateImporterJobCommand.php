<?php

namespace Tagcade\Bundle\AppBundle\Command;


use Pheanstalk\PheanstalkInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
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
        $this->logger = $this->getContainer()->get('logger');
        $this->tube = $this->getContainer()->getParameter('unified_report_files_tube');
        $this->watchRoot = $this->getContainer()->getParameter('watch_root');
        $this->archivedFiles = $this->getContainer()->getParameter('processed_archived_files');

        if (!file_exists($this->watchRoot) || !is_dir($this->watchRoot) ||
            !file_exists($this->archivedFiles) || !is_dir($this->archivedFiles)
        ) {
            $this->logger->error(sprintf('either %s or %s does not exist', $this->watchRoot, $this->archivedFiles));
            throw new \InvalidArgumentException(sprintf('either %s or %s or %s does not exist', $this->watchRoot, $this->archivedFiles));
        }

        $ttr = (int)$this->getContainer()->getParameter('pheanstalk_ttr');
        if ($ttr < 1) {
            $ttr = \Pheanstalk\PheanstalkInterface::DEFAULT_TTR;
        }

        $this->ttr = $ttr;

        $duplicateFileCount = 0;
        $newFiles = $this->getNewFiles($duplicateFileCount);

        $this->logger->info(sprintf('Found %d new files and other %d duplications', count($newFiles), $duplicateFileCount));

        $this->createJob($newFiles, $this->tube, $ttr, $output);
    }

    protected function getNewFiles(&$duplicateFileCount = 0)
    {
        // process zip files
        $this->extractZipFilesIfAny();

        // get all files include the ones in zip
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

            $zip = new ZipArchive();
            $openStatus = $zip->open($fileFullPath);
            if ($openStatus !== true) {
                continue;
            }

            $lastSlashPosition = strrpos($fileFullPath, '/');
            $pathToExtract = substr($fileFullPath, 0, $lastSlashPosition);
            $res = $zip->extractTo($pathToExtract);
            if ($res === FALSE) {
                $this->logger->error(sprintf('Failed to unzip the file %s', $fileFullPath));
            }

            $zip->close();

            // move to archive folder
            rename($fileFullPath, sprintf('%s/%s', $this->archivedFiles, substr($fileFullPath, $lastSlashPosition + 1)));
        }
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

            $importData = ['filePath' => $filePath, 'publisher' => $publisherId, 'partnerCName' => $partnerCName];
            if ($reportStartDate == $reportEndDate) {
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