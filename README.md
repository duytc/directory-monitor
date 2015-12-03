Composer install concerto/directory-monitor
===

1. Install pear
---
```
sudo apt-get install php-pear
```

2. Install php5-dev
---
```
sudo apt-get install php5-dev
```

3. Install inotify extension
---
Go to [http://pecl.php.net/package/inotify](http://pecl.php.net/package/inotify) and download inotify-0.1.6.tgz, then install using via pear:
```
sudo pear install <path-to-inotify-0.1.6.tgz>
```

4. Config php.ini
---
Add line ```extension=inotify.so``` to files php.ini (in ```/etc/php5/apache2/php.ini``` and ``` /etc/php5/cli/php.ini ```) to enable inotify.so library.

5. Composer install concerto/directory-monitor
---
Add package to composer.json if not yet existed: 
```
"require": {
    "concerto/directory-monitor": "0.*"
}
```
then install: 
```
composer install
```

6. Config
---
Create config.php (base on config.php.dist).
In config.php, we set "TAGCADE_UNIFIED_REPORT_IMPORT_MODULE" as path to root folder of module tagcade-unified-report-importer (for calling command ```php app/console tc:unified-report:import ...```);

and set "TAGCADE_UNIFIED_REPORT_MONITOR_DIRECTORY_ROOT" as path to folder contains all report files, in which, the directory structure is ```<Ad Network Name>/<Publisher Id>/<All sub-directories and report files>```. 

For example: if we set ```TAGCADE_UNIFIED_REPORT_MONITOR_DIRECTORY_ROOT = /home/tagcade-report/report-data/```

the path ```/home/tagcade-report/data-report/Pulse Point/2/report.csv``` contains report.csv for Ad Network "Pulse Point" and Publisher ID "2"

the path ```/home/tagcade-report/data-report/Pulse Point/2/20151130/report_2.csv``` contains report.csv in sub-directory for Ad Network "Pulse Point" and Publisher ID "2".