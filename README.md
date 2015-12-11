
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

