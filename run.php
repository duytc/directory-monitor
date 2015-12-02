<?php

require 'vendor/autoload.php';
require 'src/Worker.php';
require 'src/Process.php';
require 'config.php';

use Concerto\DirectoryMonitor\RecursiveMonitor;
use React\EventLoop\Factory as EventLoopFactory;
use src\Worker;

$loop = EventLoopFactory::create();
$monitor = new RecursiveMonitor($loop, TAGCADE_UNIFIED_REPORT_MONITOR_DIRECTORY_ROOT);

/* Ignore all temp files: */
$monitor->ignore('\___jb_');

/* Create an instance of RecursiveMonitor and listen to the events you need: */
$monitor->on('create', function ($path, $root) {
    echo "Got new file: {$path} in {$root}\n";

    $fileName = basename($path); // path: pulse-point/2/report.csv, // fileName: report.csv
    $dirAfterRoot = substr($path, 0, strpos($path, $fileName) - 1); // not include '/' at the end, dirAfterRoot: pulse-point/2
    $filePath = $root . '/' . $dirAfterRoot . '/' . $fileName;

    echo sprintf("-fileName: %s\n-dirAfterRoot: %s\n-filePath: %s\n", $fileName, $dirAfterRoot, $filePath);

    try {
        (new Worker())->doJob($dirAfterRoot, $filePath);
    } catch (Exception $e) {
        echo sprintf("Exception while importing file %s in %s: %s\n", $path, $root, $e->getMessage());
    }
});

$loop->run();