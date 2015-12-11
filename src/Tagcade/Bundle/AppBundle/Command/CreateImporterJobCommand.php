<?php

namespace Tagcade\Bundle\AppBundle\Command;


use Pheanstalk\PheanstalkInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;

class CreateImporterJobCommand extends ContainerAwareCommand
{
    const DIR_MIN_DEPTH_LEVELS = 2;

    protected function configure()
    {
        $this
            ->setName('tc:unified-report:directory-monitor:create-job')
            ->addArgument('tube', InputArgument::REQUIRED, 'Name of the Pheanstalk tube to be watched')
            ->addOption('watchRoot', 'wr', InputOption::VALUE_REQUIRED, 'Folder root where files will be scanned to create job')
            ->addOption('timeToRun', 'ttr', InputOption::VALUE_OPTIONAL, 'Custom time to run for pheanstalk job')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $tube = $input->getArgument('tube');
        $watchRoot = $input->getOption('watchRoot');
        if (!file_exists($watchRoot)) {
            throw new \InvalidArgumentException('Expect an existing folder root to scan');
        }


        $filesAndFolders = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(TAGCADE_UNIFIED_REPORT_MONITOR_DIRECTORY_ROOT, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $fileList = [];
        $dirList = [];

        foreach($filesAndFolders as $fd) {
            /** @var \SplFileInfo $fd */
            $fileFullPath = $fd->getRealPath();
            if (is_file($fileFullPath)) {
                $fileList[] = $fileFullPath;
            }else if (is_dir($fileFullPath)) {
                $dirList[] = $fileFullPath;
            }
        }

        $ttr = (int)$input->getOption('timeToRun');
        if ($ttr < 1) {
            $ttr = \Pheanstalk\PheanstalkInterface::DEFAULT_TTR;
        }

        $this->createJob($fileList, $tube, $ttr, $output);

    }

    protected function createJob(array $fileList, $tube, $ttr, OutputInterface $output)
    {
        /**
         * @var PheanstalkInterface $pheanstalk
         */
        $pheanstalk = $this->getContainer()->get('leezy.pheanstalk.primary');
        foreach ($fileList as $filePath) {

            // Extract network name and publisher id from file path
            $dirs = array_reverse(explode('/', $filePath));
            if(!is_array($dirs) || count($dirs) < self::DIR_MIN_DEPTH_LEVELS) {
                $output->writeln(sprintf('Not a valid file location at %s. It should be under networkName/publisherId/...', $filePath));
                continue;
            }

            $adNetworkName = array_pop($dirs);
            if (empty($adNetworkName)) {
                $output->writeln(sprintf("Can not extract AdNetwork from file path %s!!!\n", $filePath));
                continue;
            }

            $publisherId = filter_var(array_pop($dirs), FILTER_VALIDATE_INT);
            if (!$publisherId) {
                $output->writeln(sprintf("Can not extract Publisher from file path %s!!!\n", $filePath));
                continue;
            }

            $pheanstalk
                ->useTube($tube)
                ->put(
                    json_encode(['filePath' => $filePath]),
                    \Pheanstalk\PheanstalkInterface::DEFAULT_PRIORITY,
                    \Pheanstalk\PheanstalkInterface::DEFAULT_DELAY,
                    $ttr
                )
            ;
        }
    }
}