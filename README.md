This code base enables two commands. One is to scan directory and files to be imported and create importing job for importer code base.
 Second command is to remove imported files. You can use this command as a cron job task to remove files that were imported yesterday.

1. Clone new repository
---
```
git clone path_to_this
```

2. update composer package dependencies
---
```
composer update --prefer-dist
```

3. Example of creating job for importer code base
---
```
php app/console tc:unified-report:directory-monitor:create-job
```

4. Example of cleaning imported files job
---
```
php app/console tc:unified-report:directory-monitor:remove-imported-files
```

