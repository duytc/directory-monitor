<?php

require 'vendor/autoload.php';
require_once 'config.php';

use Pheanstalk\Pheanstalk;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Tagcade\DirectoryMonitor\Worker;
use Monolog\Formatter\LineFormatter;

$pheanstalk = new Pheanstalk(PHEANSTALK_HOST); // local
// create a log channel
/**
 * @var \Psr\Log\LoggerInterface $logger
 */
$logger = new Logger('unified-report-directory-monitor-worker');
$logger->pushHandler((new StreamHandler(LOG_STREAM, LOG_LEVEL))->setFormatter(new LineFormatter(null, null, true, true)));


// Set the start time
$startTime = time();
$endTime = $startTime + WORKER_TIME_LIMIT;

while (true) {
    if (time() > $endTime) {
        // exit worker gracefully, supervisord will restart it
        break;
    }

    $job = $pheanstalk
        ->watch('unified-report-files')
        ->ignore('default')
        ->reserve();

    if (!$job instanceof \Pheanstalk\Job) {
        continue;
    }

    $rawData = $job->getData();
    if (!is_string($rawData)) {
        continue;
    }

    $data = json_decode($rawData, true);

    if (false === $data || (!array_key_exists('filePath', $data) || !array_key_exists('rootDir', $data))) {
        $logger->error(sprintf('Received an invalid payload %s', $rawData));
        $pheanstalk->bury($job);
        continue;
    }

    $dir = $data['rootDir'];
    $filePath = $data['filePath'];

    $fullPath = sprintf('%s/%s', $dir, $filePath);

    if (!file_exists($fullPath) || !is_file($fullPath)) { // might be a folder creation
        $logger->error(sprintf('File to be imported does not exists at %s', $fullPath));
        $pheanstalk->bury($job);
        continue;
    }

    try {

        $logger->info(sprintf('Received job (ID: %s) with payload %s', $job->getId(), $rawData));

        (new Worker($logger))->doJob($dir, $filePath);

        $logger->info(sprintf('Job (ID: %s) with payload %s has been completed', $job->getId(), $rawData));

        $pheanstalk->delete($job);
    }
    catch(\Exception $e) {
        $logger->error(
            sprintf(
                'Job (ID: %s) with payload %s failed with an exception: %s',
                $job->getId(),
                $rawData,
                $e->getMessage()
            )
        );
        $pheanstalk->bury($job);
    }

}

