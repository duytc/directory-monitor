<?php

require 'vendor/autoload.php';
require_once 'config.php';

use Pheanstalk\Pheanstalk;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

$pheanstalk = new Pheanstalk(PHEANSTALK_HOST);

/**
 * @var \Psr\Log\LoggerInterface $logger
 */
$logger = new Logger('unified-report-directory-monitor-watcher');
$logger->pushHandler((new StreamHandler(LOG_STREAM, LOG_LEVEL))->setFormatter(new LineFormatter(null, null, true, true)));

$detectedFiles = [];

while (true) {
    sleep(1); // avoid cpu consumption
    // TODO reset $detectedFiles daily to save memory

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(TAGCADE_UNIFIED_REPORT_MONITOR_DIRECTORY_ROOT, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    $newFiles = [];
    foreach ($files as $file) {
        /** @var SplFileInfo $file */
        $fileFullPath = $file->getRealPath();
        $hashMd5 = hash('md5',$fileFullPath);
        if (!array_key_exists($hashMd5, $detectedFiles)) {

            $detectedFiles[$hashMd5] = $fileFullPath;

            if ($file->isFile()) {
                $newFiles[] = $fileFullPath;
            }
        }
        else if(true === $file->isDir()) { // detect modified date to see if it is from yesterday then we can remove the folder

            $fileRelativePath =  trim(str_replace(TAGCADE_UNIFIED_REPORT_MONITOR_DIRECTORY_ROOT, '', $fileFullPath), '/');
            $subFolders = explode('/', $fileRelativePath);
            if(count($subFolders) < 3) { // not remove publisher id folder
                continue;
            }

            $modifiedDate = date_create_from_format('Ymd', date('Ymd', $file->getMTime()));
            $modifiedDateString = $modifiedDate->format('Ymd');
            $today = (new DateTime('today'))->setTime(0,0,0);

            $interval = $today->diff($modifiedDate);
            if (!$interval instanceof DateInterval) {
                $logger->warning(sprintf('Could not compare date %s and %', $modifiedDateString, $today->format('Ymd')));
                continue; // some failure
            }

            if (false !== $interval->days && $interval->days > 0) {
                // remove this folder from yesterday
                // should check if folder exists. There might be a case parent folder is deleted earlier and then child folder come in in the loop
                if (file_exists($fileFullPath)) {
                    exec(sprintf('rm -rf %s', $fileFullPath));
                    $logger->info(sprintf('removing folder %s that was last modified before today, on %s', $fileFullPath, $modifiedDateString));
                }
            }
        }
    }

    $newFileCount = count($newFiles);
    if ($newFileCount < 1) {
        continue;
    }

    $logger->info(sprintf('found new %d files', $newFileCount));

    foreach($newFiles as $filePath) {

        $logger->info(sprintf("New file is detected %s",  $filePath));

        $root = TAGCADE_UNIFIED_REPORT_MONITOR_DIRECTORY_ROOT;
        $path = trim(str_replace($root, '', $filePath), '/');

        $pheanstalk
            ->useTube(PHEANSTALK_JOB_TUBE)
            ->put(
                json_encode(['rootDir' => $root, 'filePath' => $path]),
                \Pheanstalk\PheanstalkInterface::DEFAULT_PRIORITY,
                \Pheanstalk\PheanstalkInterface::DEFAULT_DELAY,
                PHEANSTALK_TIME_TO_RUN
            )
        ;
    }
}
