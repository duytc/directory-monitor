<?php

namespace src;

class Worker
{
    const DIR_MIN_DEPTH_LEVELS = 2; // <adNetworkName>/<publisherId>, e/g: Pulse Point/2
    const DIR_IDX_AS_AD_NETWORK = 0; // <adNetworkName>, e/g: Pulse Point
    const DIR_IDX_AS_PUBLISHER = 1; // <publisherId>, e/g: 2

    public function doJob($dir, $filePath)
    {
        if (!$this->isFileValid($filePath)) {
            throw new \Exception(sprintf("File %s invalid!!!\n", $filePath));
        }

        // build commands importing file
        $dirs = explode('/', $dir);
        if(!is_array($dirs) || count($dirs) < self::DIR_MIN_DEPTH_LEVELS) {
            throw new \Exception("Expect dir has depth levels >= 2!!!\n");
        }

        $publisherId = filter_var($dirs[self::DIR_IDX_AS_PUBLISHER], FILTER_VALIDATE_INT);
        if (!$publisherId) {
            throw new \Exception(sprintf("Can not extract Publisher from dir %s!!!\n", $dir));
        }

        $adNetworkName = $dirs[self::DIR_IDX_AS_AD_NETWORK];
        if (empty($adNetworkName)) {
            throw new \Exception(sprintf("Can not extract AdNetwork from dir %s!!!\n", $dir));
        }

        $command = sprintf("php \"%s/app/console\" tc:unified-report:import --publisher %s --network \"%s\" \"%s\"",
            TAGCADE_UNIFIED_REPORT_IMPORT_MODULE, // make between "" because it may contain space...
            $publisherId,
            $adNetworkName,
            $filePath
        );

        echo sprintf("importing File %s with Publisher #%s and AdNetwork %s...\n", $filePath, $publisherId, $adNetworkName);

        echo sprintf("command %s\n", $command);

        // You may use status(), start(), and stop(). notice that start() method gets called automatically one time.
        $process = new Process($command);

        // Then you can start/stop/ check status of the job.
        if ($process->status()) {
            echo sprintf("The process is currently running with pid %d\n", $process->getPid());
        } else {
            echo "The process is not running.\n";
        }
    }

    private function isFileValid($filePath)
    {
        return file_exists($filePath);
    }
}