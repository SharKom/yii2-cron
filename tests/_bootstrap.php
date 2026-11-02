<?php
defined('YII_DEBUG') or define('YII_DEBUG', true);
defined('YII_ENV') or define('YII_ENV', 'test');

require_once __DIR__ . '/../../../../vendor/autoload.php';
require_once __DIR__ . '/../../../../vendor/yiisoft/yii2/Yii.php';

// I test usano SEMPRE il DB di test isolato `soundorgan_test` definito in
// common/config/test-local.php, mai il DB vero: MYSQL_DATABASE (che nel container
// punta al DB dell'applicazione) viene volutamente ignorata.
// Lanciare sempre sotto il lock comune:
//   podman exec -u www-data -w /var/www/html/vendor/sharkom/yii2-cron/tests soundorgan-php \
//     flock /tmp/soundorgan_test.lock /var/www/html/vendor/bin/codecept run unit
$testLocal = require __DIR__ . '/../../../../common/config/test-local.php';
$dbConfig = $testLocal['components']['db'];
// Regex esatta come common/tests/_db_guard.php: `soundorgan_test_x` non passa.
if (!preg_match('/(^|;|:)dbname=soundorgan_test(;|$)/', (string)($dbConfig['dsn'] ?? ''))) {
    throw new RuntimeException('Bootstrap test yii2-cron: common/config/test-local.php non punta al DB di test soundorgan_test.');
}

$vendor = realpath(__DIR__ . '/../../../../vendor');
// Runtime fuori dal pacchetto e per utente (vedi tests/bootstrap.php di yii2-dev-helper).
$runtime = sys_get_temp_dir() . '/yii2-cron-tests-' . (function_exists('posix_geteuid') ? (string)posix_geteuid() : 'pid' . getmypid());
if (!is_dir($runtime)) {
    @mkdir($runtime, 0777, true);
}

new \yii\console\Application([
    'id' => 'yii2-cron-tests',
    'basePath' => __DIR__,
    'runtimePath' => $runtime,
    'vendorPath' => $vendor,
    'aliases' => [
        '@sharkom/cron' => dirname(__DIR__),
        '@vendor' => $vendor,
    ],
    'params' => [
        // LogHelper in console stampa a video: nei test solo gli errori.
        'LogLevel' => 'error',
    ],
    'modules' => [
        'cron' => ['class' => \sharkom\cron\Module::class],
    ],
    'components' => [
        'db' => $dbConfig,
    ],
]);
