<?php

declare(strict_types=1);

namespace sharkom\cron\tests\unit;

use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Yii;
use yii\db\Query;
use yii\db\Transaction;

require_once dirname(__DIR__, 2) . '/migrations/m261102_160100_schedule_purge_spool.php';

/**
 * Migrazione che programma cron/maintenance/purge-spool: tre rami, idempotenza e down.
 */
final class SchedulePurgeSpoolMigrationTest extends Unit
{
    private const MIGRAZIONE = \m261102_160100_schedule_purge_spool::class;

    private ?Transaction $transazione = null;

    protected function _before(): void
    {
        $this->transazione = Yii::$app->db->beginTransaction();
        ob_start();
    }

    protected function _after(): void
    {
        ob_end_clean();
        if ($this->transazione !== null && $this->transazione->isActive) {
            $this->transazione->rollBack();
        }
    }

    private function migrazione(bool $conDispatcher = true, bool $conCron = true): \m261102_160100_schedule_purge_spool
    {
        return new class($conDispatcher, $conCron) extends \m261102_160100_schedule_purge_spool {
            private bool $conDispatcher;
            private bool $conCron;

            public function __construct(bool $conDispatcher, bool $conCron)
            {
                $this->conDispatcher = $conDispatcher;
                $this->conCron = $conCron;
                parent::__construct(['db' => Yii::$app->db]);
            }

            protected function jobDispatcherAvailable(): bool
            {
                return $this->conDispatcher && parent::jobDispatcherAvailable();
            }

            protected function cronJobAvailable(): bool
            {
                return $this->conCron && parent::cronJobAvailable();
            }
        };
    }

    private function job(): ?array
    {
        $job = (new Query())->from('system_jobs')->where(['name' => (self::MIGRAZIONE)::JOB_NAME])->one();
        return $job === false ? null : $job;
    }

    private function definizioni(int $jobId): array
    {
        return (new Query())->from('schedule_definitions')->where(['system_job_id' => $jobId])->indexBy('rrule')->all();
    }

    private function cronJob(): array
    {
        return (new Query())->from('cron_job')->where(['like', 'command', (self::MIGRAZIONE)::ROUTE])->all();
    }

    public function testConDispatcherCreaJobNotturnoEdEsecuzioneUnicaMaterializzata(): void
    {
        $prima = time();

        $this->migrazione()->up();

        $job = $this->job();
        $this->assertNotNull($job);
        // params e' una colonna JSON: a DB c'e' una stringa JSON che contiene il JSON
        // (convenzione delle righe esistenti, vedi m260728_120041). Si legge come il
        // dispatcher: typecast della colonna di Yii, poi un solo json_decode.
        $params = Yii::$app->db->getTableSchema('system_jobs')->getColumn('params')->phpTypecast($job['params']);
        $this->assertSame(
            ['route' => 'cron/maintenance/purge-spool', 'args' => ['--optimize=1']],
            json_decode($params, true)
        );
        $this->assertSame(3600, (int) $job['catch_up_threshold_seconds']);
        $this->assertSame(1800, (int) $job['timeout']);

        $definizioni = $this->definizioni((int) $job['id']);
        $this->assertEqualsCanonicalizing(
            ['FREQ=DAILY;BYHOUR=2;BYMINUTE=45;BYSECOND=0', 'FREQ=DAILY;COUNT=1'],
            array_keys($definizioni)
        );
        $unica = $definizioni['FREQ=DAILY;COUNT=1'];
        $dtstart = (new DateTimeImmutable($unica['dtstart'], new DateTimeZone('Europe/Rome')))->getTimestamp();
        $this->assertGreaterThanOrEqual($prima + 115, $dtstart);
        $this->assertLessThanOrEqual(time() + 125, $dtstart);

        $eventi = (new Query())->from('scheduled_events')->where(['schedule_definition_id' => $unica['id']])->all();
        $this->assertCount(1, $eventi);
        $this->assertSame($unica['dtstart'], $eventi[0]['run_at']);
        $this->assertSame([], $this->cronJob());
    }

    public function testIdempotenteEDown(): void
    {
        $this->migrazione()->up();
        $this->migrazione()->up();
        $job = $this->job();
        $this->assertCount(2, $this->definizioni((int) $job['id']));
        $this->assertSame(1, (int) (new Query())->from('scheduled_events')->where(['system_job_id' => $job['id']])->count());

        $this->migrazione()->down();

        $this->assertNull($this->job());
        $this->assertSame([], $this->definizioni((int) $job['id']));
    }

    public function testSenzaDispatcherCreaIlCronJobGiornaliero(): void
    {
        $this->migrazione(false, true)->up();
        $this->migrazione(false, true)->up();

        $righe = $this->cronJob();
        $this->assertCount(1, $righe);
        $this->assertSame('45 2 * * *', $righe[0]['schedule']);
        $this->assertStringStartsWith(PHP_BINARY . ' ', $righe[0]['command']);
        $this->assertStringEndsWith('yii cron/maintenance/purge-spool --optimize=1', $righe[0]['command']);

        $this->migrazione(false, true)->down();
        $this->assertSame([], $this->cronJob());
    }

    public function testSenzaDispatcherNeCronNessunaAzione(): void
    {
        $this->assertTrue($this->migrazione(false, false)->up());
        $this->assertNull($this->job());
        $this->assertSame([], $this->cronJob());
    }
}
