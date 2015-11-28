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