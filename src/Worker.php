<?php

namespace src;

class Worker
{
    public function doJob($dir, $filePath)
    {
        if (!$this->isFileValid($filePath)) {
            throw new \Exception(sprintf("File %s invalid!!!\n", $filePath));
        }

        // build commands importing file
        $publisherId = filter_var(basename($dir), FILTER_VALIDATE_INT);
        if (!$publisherId) {
            throw new \Exception(sprintf("Can not extract publisherId from dir %s!!!\n", $dir));
        }

        $command = sprintf('php %s/app/console tc:unified-report:import --publisher %s %s', TAGCADE_UNIFIED_REPORT_IMPORT_MODULE, $publisherId, $filePath);

        echo sprintf("importing File %s with Publisher #%s ...\n", $filePath, $publisherId);

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