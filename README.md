Yii2 Cron job Manager
=====================
Create Cron jobs from browser, and look that run logs

Forked from vasadibt/yii2-cron with some modifications:

1. Now it can run every kind of script, not only Yii2 console commands
2. Added capability to have a log file in addition to DB logs
3. Removed the runquick capabilities


ChangeLog 27/Feb/2023
1. Add Manual Run button on cronjob page
2. Add auto purge logs (use module param purge_log_interval | default 3 months)
3. Fix some graphics in logs pages


Installation
------------

The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

Either run

```
php composer.phar require --prefer-dist sharkom/yii2-cron "*"
```

or add

```
"sharkom/yii2-cron": "*"
```

to the require section of your `composer.json` file.


### Migration

Run the following command in Terminal for database migration:

```
yii migrate/up --migrationPath=@sharkom/cron/migrations
```

Or use the [namespaced migration](http://www.yiiframework.com/doc-2.0/guide-db-migrations.html#namespaced-migrations) (requires at least Yii 2.0.10):

```php
// Add namespace to console config:
'controllerMap' => [
    'migrate' => [
        'class' => 'yii\console\controllers\MigrateController',
        'migrationPath' => [
            '@sharkom/cron/migrations',
        ],
    ],
],
```

Then run:
```
yii migrate/up
```

### Web Application Config

Turning on the Cron Job Manager Module in the web application:

Simple example:

```php
'modules' => [
    'cron' => [
        'class' => 'sharkom\cron\Module',
    ],
],
```

### Console Application Config

Turning on the Cron Job Manager Module in the console application:

Simple example:

```php
'modules' => [
    'cron' => [
        'class' => 'sharkom\cron\Module',
    ],
],
```

### Schedule Config

Set the server schedule to run the following command

On Linux:

Add to the crontab with the user who you want to run the script (possibly not root) with the `crontab -e` command or by editing the `/etc/crontab` file

```bash
* * * * * <your-application-folder>/yii cron/cron/run 2>&1
```

On Windows:

Open the task scheduler and create a new task