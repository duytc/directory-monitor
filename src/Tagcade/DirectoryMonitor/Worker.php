<?php

namespace Tagcade\DirectoryMonitor;

use Psr\Log\LoggerInterface;

class Worker
{
    const DIR_MIN_DEPTH_LEVELS = 2;
    /**
     * @var LoggerInterface
     */
    private $logger;

    function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     *
     * @param $rootDir
     * @param $filePath
     * @throws \Exception
     */
    public function doJob($rootDir, $filePath)
    {
        $fullPath = sprintf('%s/%s', $rootDir, $filePath);

        if (!file_exists($fullPath)) {
            throw new \Exception(sprintf("File %s invalid!!!\n", $filePath));
        }

        // Extract network name and publisher id from file path
        $dirs = array_reverse(explode('/', $filePath));
        if(!is_array($dirs) || count($dirs) < self::DIR_MIN_DEPTH_LEVELS) {
            throw new \Exception("Expect dir has depth levels >= 2!!!\n");
        }

        $adNetworkName = array_pop($dirs);
        if (empty($adNetworkName)) {
            throw new \Exception(sprintf("Can not extract AdNetwork from file path %s!!!\n", $filePath));
        }

        $publisherId = filter_var(array_pop($dirs), FILTER_VALIDATE_INT);
        if (!$publisherId) {
            throw new \Exception(sprintf("Can not extract Publisher from file path %s!!!\n", $filePath));
        }

        $command = sprintf("php \"%s/app/console\" tc:unified-report:import --publisher %s --network \"%s\" \"%s\"",
            TAGCADE_UNIFIED_REPORT_IMPORT_MODULE, // make between "" because it may contain space...
            $publisherId,
            $adNetworkName,
            $fullPath
        );

        $this->logger->info(sprintf("command %s\n", $command));

        $this->executeCommand($command, false);

    }

    /**
     * @param $command
     * @param bool $background
     * @return bool|int
     */
    protected function executeCommand($command, $background = true)
    {
        $command = true === $background ? 'nohup ' . $command . ' > /dev/null 2>&1 & echo $!' : $command;
        exec($command, $op);
        if (false === $background) {
            return true;
        }

        $pid = (int)$op[0];

        return $pid;
    }

}