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
 ```
path/to/directory/publishers/{publisherId}/{partner canonical name}/{execution date}-{start-date}-{end-date}
```

Execution date: is the date fetcher run to download csv file

Start date: is the report start date

End date: is the report end date


3. Example of creating job for importer code base
---
```
php app/console tc:ur:import-new-files
```

All new discovered files will be pushed into a queue for the worker to read and process.

4. Example of cleaning imported files job
---

```
php app/console tc:remove-imported-files
```

This will remove all imported file from queue and also database

5. Example of removing incompatible files
---

```
php app/console tc:ur:remove-incompatible-files
```
Now we only accept extension .csv .xls .xlsx .json
Other extensions will be ignored and can be remove with above command


