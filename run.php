<?php

require 'vendor/autoload.php';
require 'src/Worker.php';
require 'src/Process.php';

use Concerto\DirectoryMonitor\RecursiveMonitor;
use React\EventLoop\Factory as EventLoopFactory;
use src\Worker;

$loop = EventLoopFactory::create();
$monitor = new RecursiveMonitor($loop, __DIR__ . '/data');

/* Ignore all temp files: */
$monitor->ignore('\___jb_');

/* Create an instance of RecursiveMonitor and listen to the events you need: */
$monitor->on('create', function ($path, $root) {
    echo "Got new file: {$path} in {$root}\n";

    $fileName = basename($path);
    $dir = $root . '/' . substr($path, 0, strpos($path, $fileName) - 1); // not include '/' at the end
    $filePath = $dir . '/' . $fileName;

    (new Worker())->doJob($dir, $filePath);
});

$loop->run();