<?php

require 'vendor/autoload.php';
require_once 'config.php';

use Concerto\DirectoryMonitor\RecursiveMonitor;
use React\EventLoop\Factory as EventLoopFactory;
use Pheanstalk\Pheanstalk;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

$pheanstalk = new Pheanstalk(PHEANSTALK_HOST);

$loop = EventLoopFactory::create();
$monitor = new RecursiveMonitor($loop, TAGCADE_UNIFIED_REPORT_MONITOR_DIRECTORY_ROOT);
// create a log channel
/**
 * @var \Psr\Log\LoggerInterface $logger
 */
$logger = new Logger('unified-report-directory-monitor-watcher');
$logger->pushHandler((new StreamHandler(LOG_STREAM, LOG_LEVEL))->setFormatter(new LineFormatter(null, null, true, true)));

/* Ignore all temp files: */
$monitor->ignore('\___jb_');

/* Create an instance of RecursiveMonitor and listen to the events you need: */
$monitor->on('create', function ($path, $root) use($pheanstalk, $logger) {
    $fullPath = sprintf('%s/%s', $root, $path);

    if (!file_exists($fullPath) || !is_file($fullPath)) { // might be a folder creation
        return;
    }

    $logger->info(sprintf("New file is detected %s\n", $fullPath));

    $pheanstalk
        ->useTube('unified-report-files')
        ->put(
            json_encode(['rootDir' => $root, 'filePath' => $path]),
            \Pheanstalk\PheanstalkInterface::DEFAULT_PRIORITY,
            \Pheanstalk\PheanstalkInterface::DEFAULT_DELAY,
            JOB_TIME_TO_RUN
        );
});

$loop->run();