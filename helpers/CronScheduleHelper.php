<?php
namespace sharkom\cron\helpers;

use Cron\CronExpression;
use DateTimeImmutable;
use sharkom\cron\models\CronJob;
use Yii;

class CronScheduleHelper
{
    private const CACHE_TTL = 1800;
    private const CACHE_PREFIX = 'cron_schedule_status:';
    private const KEYS_INDEX = 'cron_schedule_status:_commands';

    /** @var callable|null test-only: sostituisce il lookup DB */
    private static $jobFinderOverride = null;

    public static function status(string $command): CronScheduleStatus
    {
        $raw = self::lookupCached($command);
        $schedule = $raw['schedule'];
        $state = CronState::from($raw['state']);

        $nextRun = null;
        $prevRun = null;

        if ($schedule !== null) {
            try {
                $cron = CronExpression::factory($schedule);
                $nextRun = DateTimeImmutable::createFromMutable($cron->getNextRunDate());
                $prevRun = DateTimeImmutable::createFromMutable($cron->getPreviousRunDate());
            } catch (\Throwable $e) {
                self::logWarning("Cron {$command} ha schedule non valida: {$schedule}");
                $state = CronState::MISCONFIGURED;
            }
        }

        if ($state === CronState::NOT_CONFIGURED || $state === CronState::MISCONFIGURED) {
            self::logWarning("Cron {$command} in stato {$state->value}");
        }

        return new CronScheduleStatus($state, $schedule, $nextRun, $prevRun);
    }

    public static function findJob(string $command): ?CronJob
    {
        $raw = self::lookupCached($command);
        return $raw['job_id'] ? CronJob::findOne($raw['job_id']) : null;
    }

    public static function invalidateCache(string $command): void
    {
        $cache = self::getCache();
        if ($cache !== null) {
            $cache->delete(self::CACHE_PREFIX . $command);
        }
    }

    /**
     * Invalida tutte le chiavi di cache del helper. Chiamato da CronJob::afterSave/afterDelete.
     * Usa l'indice delle chiavi note (self::KEYS_INDEX) per sapere quali alias invalidare —
     * necessario perché Yii Redis cache hasha le chiavi e uno scan diretto non le trova.
     */
    public static function invalidateAll(): void
    {
        $cache = self::getCache();
        if ($cache === null) {
            return;
        }
        $commands = $cache->get(self::KEYS_INDEX) ?: [];
        foreach ($commands as $cmd) {
            $cache->delete(self::CACHE_PREFIX . $cmd);
        }
        $cache->delete(self::KEYS_INDEX);
    }

    private static function rememberKey(string $command): void
    {
        $cache = self::getCache();
        if ($cache === null) {
            return;
        }
        $commands = $cache->get(self::KEYS_INDEX) ?: [];
        if (!in_array($command, $commands, true)) {
            $commands[] = $command;
            $cache->set(self::KEYS_INDEX, $commands, 0);
        }
    }

    private static function lookupCached(string $command): array
    {
        $key = self::CACHE_PREFIX . $command;
        $cache = self::getCache();

        if ($cache !== null) {
            $cached = $cache->get($key);
            if ($cached !== false) {
                return $cached;
            }
        }

        $job = self::findJobRaw($command);
        $raw = self::resolveState($job);

        if ($cache !== null) {
            $cache->set($key, $raw, self::CACHE_TTL);
            self::rememberKey($command);
        }

        return $raw;
    }

    private static function findJobRaw(string $command)
    {
        if (self::$jobFinderOverride !== null) {
            return (self::$jobFinderOverride)($command);
        }
        return CronJob::find()->where(['like', 'command', $command])->one();
    }

    private static function resolveState($job): array
    {
        if ($job === null) {
            return ['state' => CronState::NOT_CONFIGURED->value, 'schedule' => null, 'job_id' => null];
        }
        $schedule = $job->schedule ?? null;
        $active = (bool)($job->active ?? false);
        $jobId = $job->id ?? null;

        if (empty($schedule)) {
            return ['state' => CronState::MISCONFIGURED->value, 'schedule' => null, 'job_id' => $jobId];
        }
        if (!$active) {
            return ['state' => CronState::INACTIVE->value, 'schedule' => $schedule, 'job_id' => $jobId];
        }
        return ['state' => CronState::ACTIVE->value, 'schedule' => $schedule, 'job_id' => $jobId];
    }

    private static function getCache()
    {
        if (!class_exists('Yii', false) || Yii::$app === null) {
            return null;
        }
        try {
            return Yii::$app->cache ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function logWarning(string $message): void
    {
        if (!class_exists('Yii', false) || Yii::$app === null) {
            return;
        }
        try {
            if (class_exists(\sharkom\devhelper\LogHelper::class)) {
                \sharkom\devhelper\LogHelper::log('warning', $message, 'cron-schedule');
            } else {
                Yii::warning($message, 'cron-schedule');
            }
        } catch (\Throwable $e) {
            // test env senza Yii bootstrap: silenzia
        }
    }

    // ==== Test-only utilities ====
    public static function setJobFinderForTests(?callable $finder): void
    {
        self::$jobFinderOverride = $finder;
    }

    public static function resetInternalState(): void
    {
        self::$jobFinderOverride = null;
    }
}
