<?php

require 'vendor/autoload.php';
require 'src/Worker.php';
require 'src/Process.php';

use Concerto\DirectoryMonitor\RecursiveMonitor;
use React\EventLoop\Factory as EventLoopFactory;
use src\Worker;

$loop = EventLoopFactory::create();
$monitor = new RecursiveMonitor($loop, __DIR__ . '/data');

/* You can also ignore files using regular expressions: */
// Ignore hidden files:
//$monitor->ignore('/\.');

// Ignore the temp folder:
//$monitor->ignore('^/tmp');

// Ignore all .cache files:
//$monitor->ignore('\.cache$');

// Ignore all temp files:
$monitor->ignore('\___jb_');

/* Create an instance of RecursiveMonitor and listen to the events you need: */
/* ----------------------Fired on any Inotify event: ------------------------*/
//$monitor->on('notice', function ($path, $root) {
//    echo "Notice: {$path} in {$root}\n";
//});

$monitor->on('create', function ($path, $root) {
    echo "Created: {$path} in {$root}\n";
    (new Worker())->doJob("dir: " . $root . ", file: " . $path);
});

//$monitor->on('delete', function ($path, $root) {
//    echo "Deleted: {$path} in {$root}\n";
//});

//$monitor->on('modify', function ($path, $root) {
//    echo "Modified: {$path} in {$root}\n";
//});

//$monitor->on('write', function ($path, $root) {
//    echo "Wrote: {$path} in {$root}\n";
//});

/* And you can notice previously ignored files: */
// Notice compiled templates:
//$monitor->notice('^/tmp/templates');

$loop->run();