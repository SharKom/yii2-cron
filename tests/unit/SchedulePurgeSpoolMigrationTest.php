<?php

declare(strict_types=1);

namespace sharkom\cron\tests\unit;

use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Yii;
use yii\db\Connection;
use yii\db\Query;
use yii\db\Transaction;

require_once dirname(__DIR__, 2) . '/migrations/m261102_160100_schedule_purge_spool.php';

/**
 * Migrazione che programma cron/maintenance/purge-spool: tre rami
 * (dispatcher, cron_job di yii2-cron, nessuno), idempotenza e down.
 * Tutto in una transazione annullata in _after, su soundorgan_test.
 *
 * Il DB di test seminato da tests/setup-test-db.sh ha gia' il job (migrazione
 * applicata), un DB nuovo no: _before toglie, dentro la transazione, il job con
 * schedule ed eventi e il cron_job della migrazione, cosi' ogni test parte da un
 * DB senza job qualunque sia il seed, e l'annullamento in _after rimette le righe
 * seminate. Il caso «job gia' presente» (forma del seed: job e due schedule,
 * nessun evento) ha un test suo, che lo crea esplicitamente. Meglio che
 * riconoscere il job del seed: i controlli su dtstart, materializzazione e
 * cron_job valgono solo per righe create dal test, e il seed puo' cambiare.
 */
final class SchedulePurgeSpoolMigrationTest extends Unit
{
    private const MIGRAZIONE = \m261102_160100_schedule_purge_spool::class;

    private ?Transaction $transazione = null;
    private $logLevel;

    protected function _before(): void
    {
        $this->logLevel = Yii::$app->params['LogLevel'] ?? null;
        Yii::$app->params['LogLevel'] = 'error';
        $this->transazione = Yii::$app->db->beginTransaction();
        $this->senzaJobGiaPresente(Yii::$app->db);
        ob_start();
    }

    protected function _after(): void
    {
        ob_end_clean();
        if ($this->transazione !== null && $this->transazione->isActive) {
            $this->transazione->rollBack();
        }
        Yii::$app->params['LogLevel'] = $this->logLevel;
    }

    /**
     * Toglie job, schedule, eventi e cron_job della migrazione se ci sono gia'
     * (DB di test seminato). Da chiamare dentro una transazione che poi si annulla.
     * Cancellazioni esplicite, senza contare sulle cascate delle chiavi esterne.
     */
    private function senzaJobGiaPresente(Connection $db): void
    {
        if ($db->getTableSchema('system_jobs', true) !== null) {
            $jobIds = (new Query())->select('id')->from('system_jobs')
                ->where(['name' => (self::MIGRAZIONE)::JOB_NAME])->column($db);
            if ($jobIds !== []) {
                foreach (['scheduled_events', 'schedule_definitions'] as $tabella) {
                    if ($db->getTableSchema($tabella, true) !== null) {
                        $db->createCommand()->delete($tabella, ['system_job_id' => $jobIds])->execute();
                    }
                }
                $db->createCommand()->delete('system_jobs', ['id' => $jobIds])->execute();
            }
        }
        if ($db->getTableSchema('cron_job', true) !== null) {
            $db->createCommand()->delete('cron_job', ['like', 'command', (self::MIGRAZIONE)::ROUTE])->execute();
        }
    }

    private function migrazione(
        bool $conDispatcher = true,
        bool $conCron = true,
        ?Connection $db = null
    ): \m261102_160100_schedule_purge_spool {
        return new class($conDispatcher, $conCron, $db ?? Yii::$app->db) extends \m261102_160100_schedule_purge_spool {
            private bool $conDispatcher;
            private bool $conCron;

            public function __construct(bool $conDispatcher, bool $conCron, Connection $db)
            {
                $this->conDispatcher = $conDispatcher;
                $this->conCron = $conCron;
                parent::__construct(['db' => $db]);
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

    /**
     * Il ramo dispatcher si prova solo dove il job dispatcher c'e' (SoundOrgan): sul core
     * NormaFlow mancano la classe e le tabelle e quei test vengono saltati.
     */
    private function richiedeDispatcher(): void
    {
        if (!class_exists(\sharkom\jobDispatcher\models\SystemJob::class)) {
            $this->markTestSkipped('job dispatcher non installato (classe SystemJob assente)');
        }
        $tabelle = Yii::$app->db->schema->getTableNames('', true);
        foreach (['system_jobs', 'system_job_types', 'schedule_definitions', 'scheduled_events'] as $tabella) {
            if (!in_array($tabella, $tabelle, true)) {
                $this->markTestSkipped("job dispatcher non installato (tabella {$tabella} assente)");
            }
        }
    }

    private function job(?Connection $db = null): ?array
    {
        if (($db ?? Yii::$app->db)->getTableSchema('system_jobs') === null) {
            return null;
        }
        $job = (new Query())->from('system_jobs')->where(['name' => (self::MIGRAZIONE)::JOB_NAME])->one($db);
        return $job === false ? null : $job;
    }

    private function definizioni(int $jobId, ?Connection $db = null): array
    {
        return (new Query())->from('schedule_definitions')->where(['system_job_id' => $jobId])->indexBy('rrule')->all($db);
    }

    private function cronJob(): array
    {
        return (new Query())->from('cron_job')->where(['like', 'command', (self::MIGRAZIONE)::ROUTE])->all();
    }

    public function testConDispatcherCreaJobNotturnoEdEsecuzioneUnicaMaterializzata(): void
    {
        $this->richiedeDispatcher();
        $prima = time();

        $this->migrazione()->up();

        $job = $this->job();
        $this->assertNotNull($job);
        $this->assertSame('console.run', $job['job_type_code']);
        // params con un solo json_encode: la colonna e' json, il QueryBuilder aggiunge il
        // secondo livello (JSON_TYPE=STRING come le righe esistenti). JobRunner la legge
        // con ActiveRecord (Yii toglie un livello) e un solo json_decode.
        $this->assertSame(
            'STRING',
            (new Query())->select('JSON_TYPE(params)')->from('system_jobs')->where(['id' => $job['id']])->scalar()
        );
        $rawParams = \sharkom\jobDispatcher\models\SystemJob::findOne($job['id'])->getAttribute('params');
        $this->assertSame(
            ['route' => 'cron/maintenance/purge-spool', 'args' => ['--optimize=1']],
            json_decode($rawParams, true)
        );
        $this->assertSame(3600, (int) $job['catch_up_threshold_seconds']);
        $this->assertSame(1800, (int) $job['timeout']);
        $this->assertSame('skip', $job['overlap_policy']);

        $definizioni = $this->definizioni((int) $job['id']);
        $this->assertEqualsCanonicalizing(
            ['FREQ=DAILY;BYHOUR=2;BYMINUTE=45;BYSECOND=0', 'FREQ=DAILY;COUNT=1'],
            array_keys($definizioni)
        );
        $this->assertStringContainsString('BYSECOND=0', (self::MIGRAZIONE)::RRULE_NOTTE);

        $unica = $definizioni[(self::MIGRAZIONE)::RRULE_UNICA];
        $dtstart = (new DateTimeImmutable($unica['dtstart'], new DateTimeZone('Europe/Rome')))->getTimestamp();
        $this->assertGreaterThanOrEqual($prima + 115, $dtstart);
        $this->assertLessThanOrEqual(time() + 125, $dtstart);

        // La schedule unica e' gia' materializzata: senza, il materializzatore notturno la perderebbe.
        $eventi = (new Query())->from('scheduled_events')->where(['schedule_definition_id' => $unica['id']])->all();
        $this->assertCount(1, $eventi);
        $this->assertSame('pending', $eventi[0]['status']);
        $this->assertSame($unica['dtstart'], $eventi[0]['run_at']);

        // La notturna resta al materializzatore delle 2:00.
        $notte = $definizioni[(self::MIGRAZIONE)::RRULE_NOTTE];
        $this->assertSame(
            0,
            (int) (new Query())->from('scheduled_events')->where(['schedule_definition_id' => $notte['id']])->count()
        );

        $this->assertSame([], $this->cronJob(), 'con il dispatcher non si crea il cron_job');
    }

    public function testIdempotente(): void
    {
        $this->richiedeDispatcher();
        $this->migrazione()->up();
        $this->migrazione()->up();

        $this->assertSame(1, (int) (new Query())->from('system_jobs')->where(['name' => (self::MIGRAZIONE)::JOB_NAME])->count());
        $job = $this->job();
        $this->assertCount(2, $this->definizioni((int) $job['id']));
        $this->assertSame(1, (int) (new Query())->from('scheduled_events')->where(['system_job_id' => $job['id']])->count());
    }

    public function testConJobGiaPresenteComeNelSeedNonDuplicaNeRiprogramma(): void
    {
        $this->richiedeDispatcher();
        // Forma del DB seminato: job e due schedule, l'esecuzione unica gia'
        // consumata (nessun evento). Rieseguire la migrazione riconosce il job e non
        // crea ne' job, ne' schedule, ne' una nuova esecuzione unica.
        $this->migrazione()->up();
        $job = $this->job();
        Yii::$app->db->createCommand()->delete('scheduled_events', ['system_job_id' => $job['id']])->execute();
        $definizioniPrima = $this->definizioni((int) $job['id']);

        $this->migrazione()->up();

        $this->assertSame(1, (int) (new Query())->from('system_jobs')->where(['name' => (self::MIGRAZIONE)::JOB_NAME])->count());
        $this->assertSame($job, $this->job());
        $this->assertSame($definizioniPrima, $this->definizioni((int) $job['id']));
        $this->assertSame(0, (int) (new Query())->from('scheduled_events')->where(['system_job_id' => $job['id']])->count());
        $this->assertSame([], $this->cronJob());
    }

    public function testConConnessioneDiversaDaQuellaDellAppNonMaterializza(): void
    {
        $this->richiedeDispatcher();
        // Stesso DB di test, ma un oggetto Connection diverso da Yii::$app->db
        // (come `migrate --db=<altro>`): il Materializer scriverebbe sul DB dell'app.
        // La transazione del test ha gia' cancellato le righe del seed e le tiene
        // bloccate: si annulla prima, e la pulizia si rifa' nella transazione
        // dell'altra connessione (altrimenti attenderebbe i lock).
        $this->transazione->rollBack();
        $altra = new Connection([
            'dsn' => Yii::$app->db->dsn,
            'username' => Yii::$app->db->username,
            'password' => Yii::$app->db->password,
            'charset' => Yii::$app->db->charset,
        ]);
        $transazione = $altra->beginTransaction();
        try {
            $this->senzaJobGiaPresente($altra);
            $this->assertNull($this->job($altra));
            $this->migrazione(true, true, $altra)->up();

            $job = $this->job($altra);
            $this->assertNotNull($job);
            $definizioni = $this->definizioni((int) $job['id'], $altra);
            $this->assertCount(2, $definizioni);
            $this->assertSame(
                0,
                (int) (new Query())->from('scheduled_events')->where(['system_job_id' => $job['id']])->count('*', $altra)
            );
        } finally {
            $transazione->rollBack();
            $altra->close();
        }
    }

    public function testDownRimuoveJobESchedule(): void
    {
        $this->richiedeDispatcher();
        $this->migrazione()->up();
        $jobId = (int) $this->job()['id'];

        $this->assertNotFalse($this->migrazione()->down());

        $this->assertNull($this->job());
        $this->assertSame([], $this->definizioni($jobId));
        $this->assertSame(0, (int) (new Query())->from('scheduled_events')->where(['system_job_id' => $jobId])->count());
    }

    public function testSenzaDispatcherCreaIlCronJobGiornaliero(): void
    {
        $this->migrazione(false, true)->up();
        $this->migrazione(false, true)->up();

        $righe = $this->cronJob();
        $this->assertCount(1, $righe);
        $this->assertSame('45 2 * * *', $righe[0]['schedule']);
        $this->assertSame(1, (int) $righe[0]['active']);
        $this->assertStringStartsWith(PHP_BINARY . ' ', $righe[0]['command']);
        $this->assertStringEndsWith('yii cron/maintenance/purge-spool --optimize=1', $righe[0]['command']);
        $this->assertNull($this->job());

        $this->assertNotFalse($this->migrazione(false, true)->down());
        $this->assertSame([], $this->cronJob());
    }

    public function testSenzaDispatcherNeCronNessunaAzione(): void
    {
        $this->assertTrue($this->migrazione(false, false)->up());

        $this->assertNull($this->job());
        $this->assertSame([], $this->cronJob());
        $this->assertNotFalse($this->migrazione(false, false)->down());
    }

    public function testDispatcherRilevatoAncheConElencoDelleTabelleVecchioInMemoria(): void
    {
        $this->richiedeDispatcher();
        // Migration::init() aggiorna lo schema, ma tra la costruzione e up() l'elenco delle
        // tabelle in memoria puo' essere vecchio: senza refresh il dispatcher non si vedrebbe.
        $migrazione = $this->migrazione();
        $schema = Yii::$app->db->getSchema();
        $elenco = new \ReflectionProperty(\yii\db\Schema::class, '_tableNames');
        $prima = $elenco->getValue($schema);
        $elenco->setValue($schema, ['' => ['cron_job']]);
        try {
            $migrazione->up();
        } finally {
            $elenco->setValue($schema, $prima);
        }

        $this->assertNotNull($this->job(), 'il ramo dispatcher legge l\'elenco delle tabelle aggiornato');
        $this->assertSame([], $this->cronJob());
    }
}
