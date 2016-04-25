This code base enables two commands. One is to scan directory and files to be imported and create importing job for importer code base.
 Second command is to remove imported files. You can use this command as a cron job task to remove files that were imported yesterday.

1. Clone new repository
---
```
git clone path_to_this
```

2. update composer package dependencies and configuration
---
```
composer update --prefer-dist
```

Make sure the watched directory has structure is: 
    path/to/directory/{publisherId}/{partner canonical name}/{execution date}-{start-date}-{end-date}

Execution date: is the date fetcher run to download csv file

Start date: is the report start date

End date: is the report end date


3. Example of creating job for importer code base
---
```
php app/console tc:create-importer-job
```
This command will fetch the root data directory to discover if there's new report file. Push that file into queue with
it's parameters accompanied. Those parameters are extracted from the file's absolute path which respect the hierarchy order :

```
{root directory}/{Partner Canonical Name}/{Publisher Id}/{date}
```
All new discovered files will be pushed into a queue for the worker to read and process.

4. Example of cleaning imported files job
---

```
php app/console tc:remove-imported-files
```

This will remove all imported file from queue and also database

