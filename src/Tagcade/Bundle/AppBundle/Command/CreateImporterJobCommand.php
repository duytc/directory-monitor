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
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $tube = $this->getContainer()->getParameter('unified_report_files_tube');
        $watchRoot = $this->getContainer()->getParameter('watch_root');
        if (!file_exists($watchRoot) || !is_dir($watchRoot)) {
            throw new \InvalidArgumentException(sprintf('The folder %s does not exist', $watchRoot));
        }

        $ttr = (int)$this->getContainer()->getParameter('pheanstalk_ttr');
        if ($ttr < 1) {
            $ttr = \Pheanstalk\PheanstalkInterface::DEFAULT_TTR;
        }

        $filesAndFolders = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($watchRoot, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $fileList = [];
        foreach($filesAndFolders as $fd) {
            /** @var \SplFileInfo $fd */
            $fileFullPath = $fd->getRealPath();
            if (!is_file($fileFullPath)) {
                continue;
            }

            $md5 = hash_file('md5', $fileFullPath);
            if (!array_key_exists($md5, $fileList)) {
                $fileList[$md5] = $fileFullPath;
            }
        }

        $this->createJob($fileList, $tube, $ttr, $output);
    }

    protected function createJob(array $fileList, $tube, $ttr, OutputInterface $output)
    {
        /**
         * @var PheanstalkInterface $pheanstalk
         */
        $pheanstalk = $this->getContainer()->get('leezy.pheanstalk.primary');
        foreach ($fileList as $md5 => $filePath) {

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