<?php

namespace Tagcade\Bundle\AppBundle\Command;


use Pheanstalk\PheanstalkInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tagcade\Entity\Core\QueuedFile;
use Tagcade\Repository\Core\QueuedFileRepositoryInterface;
use ZipArchive;

class CreateImporterJobCommand extends ContainerAwareCommand
{
    const DIR_MIN_DEPTH_LEVELS = 2;

    protected function configure()
    {
        $this
            ->setName('tc:create-importer-job')
            ->setDescription('Scan for relevant files in pre-configured directory and create beantalkd importing job for importer module')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $logger = $this->getContainer()->get('logger');
        $tube = $this->getContainer()->getParameter('unified_report_files_tube');
        $watchRoot = $this->getContainer()->getParameter('watch_root');
        $processedArchivedFiles = $this->getContainer()->getParameter('processed_archived_files');

        if (!file_exists($watchRoot) || !is_dir($watchRoot) ||
            !file_exists($processedArchivedFiles) || !is_dir($processedArchivedFiles)
        ) {
            $logger->error(sprintf('either %s or %s does not exist', $watchRoot, $processedArchivedFiles));
            throw new \InvalidArgumentException(sprintf('either %s or %s or %s does not exist', $watchRoot, $processedArchivedFiles));
        }

        $ttr = (int)$this->getContainer()->getParameter('pheanstalk_ttr');
        if ($ttr < 1) {
            $ttr = \Pheanstalk\PheanstalkInterface::DEFAULT_TTR;
        }

        /**
         * @var QueuedFileRepositoryInterface $queuedFileRepository
         */
        $queuedFileRepository = $this->getContainer()->get('tagcade_app.repository.queued_file');

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($watchRoot, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        // process zip files
        $this->processZipFiles($files, $watchRoot, $processedArchivedFiles, $logger);

        $fileList = [];
        $duplicateFileCount = 0;
        foreach($files as $file) {
            /** @var \SplFileInfo $file */
            $fileFullPath = $file->getRealPath();
            if (!is_file($fileFullPath)) {
                continue;
            }

            $md5 = hash_file('md5', $fileFullPath);
            $queuedFile = $queuedFileRepository->findByHash($md5);
            if ($queuedFile instanceof QueuedFile) {
                continue;
            }

            if (!array_key_exists($md5, $fileList)) {
                $fileList[$md5] = $fileFullPath;
            }
            else {
                $duplicateFileCount ++;
            }
        }

        $output->writeln(sprintf('Found %d new files and other %d duplications', count($fileList), $duplicateFileCount));

        $this->createJob($fileList, $tube, $ttr, $output);
    }

    protected function processZipFiles($files, $watchRoot, $processedArchivedFiles, LoggerInterface $logger)
    {
        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            $fileFullPath = $file->getRealPath();
            if (!is_file($fileFullPath)) {
                continue;
            }

            if ($this->endsWith($fileFullPath, '.zip') === FALSE) {
                continue;
            }

            // Extract network name and publisher id from file path
            $logger->info(sprintf('processing zip file %s', $fileFullPath));

            $fileRelativePath =  trim(str_replace($watchRoot, '', $fileFullPath), '/');
            $dirs = array_reverse(explode('/', $fileRelativePath));
            if(!is_array($dirs) || count($dirs) < self::DIR_MIN_DEPTH_LEVELS) {
                $logger->info(sprintf('Not a valid file location at %s. It should be under networkName/publisherId/...', $fileFullPath));
                continue;
            }

            $partnerCName = array_pop($dirs);
            $publisherId = filter_var(array_pop($dirs), FILTER_VALIDATE_INT);
            $dates = array_pop($dirs);

            $extractTo = join('/', array($watchRoot, $partnerCName, $publisherId, $dates));
            $res = $this->unzip($fileFullPath, $extractTo);

            if ($res === FALSE) {
                $logger->error(sprintf('Failed to unzip the file %s', $fileFullPath));
            }

            $newName = join('/', array ($processedArchivedFiles, $partnerCName, $publisherId, $dates));

            if (file_exists($newName) === FALSE) {
                mkdir($newName, $mode = 0777, $recursive = true);
            }

            rename($fileFullPath, join('/', array($newName, $file->getBasename())));
        }
    }

    protected function createJob(array $fileList, $tube, $ttr, OutputInterface $output)
    {
        $watchRoot = $this->getContainer()->getParameter('watch_root');
        /**
         * @var QueuedFileRepositoryInterface $queuedFileRepository
         */
        $queuedFileRepository = $this->getContainer()->get('tagcade_app.repository.queued_file');
        /**
         * @var PheanstalkInterface $pheanstalk
         */
        $pheanstalk = $this->getContainer()->get('leezy.pheanstalk.primary');
        foreach ($fileList as $md5 => $filePath) {
            $fileRelativePath =  trim(str_replace($watchRoot, '', $filePath), '/');
            // Extract network name and publisher id from file path
            $dirs = array_reverse(explode('/', $fileRelativePath));

            if(!is_array($dirs) || count($dirs) < self::DIR_MIN_DEPTH_LEVELS) {
                $output->writeln(sprintf('Not a valid file location at %s. It should be under networkName/publisherId/...', $filePath));
                continue;
            }

            $partnerCName = array_pop($dirs);
            if (empty($partnerCName)) {
                $output->writeln(sprintf("Can not extract PartnerCName from file path %s!!!\n", $filePath));
                continue;
            }

            $publisherId = filter_var(array_pop($dirs), FILTER_VALIDATE_INT);
            if (!$publisherId) {
                $output->writeln(sprintf("Can not extract Publisher from file path %s!!!\n", $filePath));
                continue;
            }

            $dates = array_pop($dirs);
            $dates = explode('-', $dates);
            if (count($dates) < 3) {
                throw new \Exception('Invalid folder containing csv file. It should has format Ymd-Ymd-Ymd (execution date, report start date, report end date)');
            }

            $fetchExeucutionDate = \DateTime::createFromFormat('Ymd', $dates[0]);
            $reportStartDate = \DateTime::createFromFormat('Ymd', $dates[1]);
            $reportEndDate = \DateTime::createFromFormat('Ymd', $dates[2]);

            $importData = ['filePath' => $filePath, 'publisher' => $publisherId, 'partnerCName' => $partnerCName];
            if ($dates[1] == $dates[2]) {
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

            try {
                $queuedFileRepository->createNew($md5, $filePath);
            }
            catch(\Exception $e) {
                $output->writeln($e->getMessage());
            }
        }
    }

    protected function isValidDateString($dateString)
    {
        $dateParts = date_parse($dateString);

        return ($dateParts["error_count"] == 0 && checkdate($dateParts["month"], $dateParts["day"], $dateParts["year"]));
    }

    protected function unzip($filePath, $extractTo)
    {
        $zip = new ZipArchive;
        $res = $zip->open($filePath);
        if ($res === TRUE) {
            $res = $zip->extractTo($extractTo);
            $zip->close();
        }

        return $res;
    }

    protected function endsWith($string, $needle)
    {
        $strLen = strlen($string);
        $needleLen = strlen($needle);
        if ($needleLen > $strLen) return false;

        return substr_compare($string, $needle, $strLen - $needleLen, $needleLen) === 0;
    }
}