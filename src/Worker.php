<?php

namespace src;

class Worker {

    public function __construct()
    {
    }

    public function doJob($path)
    {
        echo "JOB done! " . $path . "\n";

        // You may use status(), start(), and stop(). notice that start() method gets called automatically one time.
        $process = new Process('top');

        // Then you can start/stop/ check status of the job.
        //$process->stop();
        //$process->start();
        if ($process->status()){
            echo sprintf("The process is currently running with pid %d\n", $process->getPid());
        }else{
            echo "The process is not running.\n";
        }
    }
}