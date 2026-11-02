<?php

use yii\db\Migration;
use yii\db\Query;

/**
 * Programma la pulizia di commands_spool (retention 14 giorni, o purge_spool_days del
 * modulo cron) con il comando `cron/maintenance/purge-spool --optimize=1`.
 *
 * Tre rami:
 *  - job dispatcher installato (SoundOrgan): system job "Manutenzione: pulizia
 *    commands_spool" (console.run) con due schedule: ogni notte alle 2:45 e
 *    un'esecuzione unica (COUNT=1) due minuti dopo la migrazione, per la prima pulizia
 *    con ottimizzazione subito dopo il deploy ma fuori dal tempo delle migrazioni.
 *    L'esecuzione unica viene materializzata subito: il materializzatore notturno
 *    (2:00) non crea occorrenze nel passato e la perderebbe;
 *  - niente dispatcher ma tabella cron_job di yii2-cron (NormaFlow): cron_job
 *    giornaliero "45 2 * * *";
 *  - nessuno dei due: nessuna azione.
 *
 * SCELTE: catch_up_threshold_seconds = 3600 (se il dispatcher e' fermo durante il
 * deploy, l'esecuzione unica parte se riparte entro un'ora; oltre ci pensa la notte);
 * overlap_policy = skip; max_retries = 0 (il retry e' la notte dopo); timeout = 1800
 * (20 minuti di cancellazione piu' la ricostruzione); internal = 0 (visibile in
 * calendario). RRULE notturna con BYSECOND=0: senza, php-rrule erediterebbe i
 * secondi del dtstart.
 *
 * ENCODING di params: json_encode SINGOLO (vedi m260728_120041).
 *
 * Niente transazione in up() (non safeUp()): il Materializer sveglia il dispatcher via
 * Redis appena inserito l'evento, e dentro una transazione aperta il dispatcher non
 * lo vedrebbe. Ogni passo e' idempotente: job per nome, schedule per rrule, cron_job
 * per comando. Non fa mai fallire il deploy per la materializzazione: scrive un avviso.
 * La materializzazione avviene solo se la connessione della migrazione e'
 * Yii::$app->db (il Materializer legge la definition con ActiveRecord).
 */
class m261102_160100_schedule_purge_spool extends Migration
{
    public const JOB_NAME = 'Manutenzione: pulizia commands_spool';
    public const ROUTE = 'cron/maintenance/purge-spool';
    public const ARGS = ['--optimize=1'];
    public const RRULE_NOTTE = 'FREQ=DAILY;BYHOUR=2;BYMINUTE=45;BYSECOND=0';
    public const RRULE_UNICA = 'FREQ=DAILY;COUNT=1';
    public const CRON_SCHEDULE = '45 2 * * *';
    public const RITARDO_PRIMA_ESECUZIONE = 120;

    private const TYPE_CODE = 'console.run';
    private const TIMEZONE = 'Europe/Rome';
    private const DESCRIZIONE = 'Cancella a blocchi le righe concluse di commands_spool piu\' vecchie della retention '
        . '(14 giorni) e i loro file di log non piu\' usati e, se conviene, ricostruisce la tabella online.';

    public function up()
    {
        if ($this->jobDispatcherAvailable()) {
            $this->scheduleWithDispatcher();
            return true;
        }
        if ($this->cronJobAvailable()) {
            $this->scheduleWithCronJob();
            return true;
        }
        echo "    > ne' job dispatcher ne' yii2-cron: nessuna pulizia programmata per " . self::ROUTE . "\n";
        return true;
    }

    public function safeDown()
    {
        if ($this->jobDispatcherAvailable()) {
            $jobId = (int) $this->findJobId();
            if ($jobId) {
                // Gli scheduled_events del job spariscono in cascata con system_jobs.
                $this->delete('{{%schedule_definitions}}', ['system_job_id' => $jobId]);
                $this->delete('{{%system_jobs}}', ['id' => $jobId]);
            }
        }
        if ($this->cronJobAvailable()) {
            $this->delete('{{%cron_job}}', ['like', 'command', self::ROUTE]);
        }
        return true;
    }

    private function scheduleWithDispatcher(): void
    {
        $now = time();
        $jobId = (int) $this->findJobId();
        if ($jobId) {
            echo "    > job '" . self::JOB_NAME . "' gia' presente (id {$jobId})\n";
        } else {
            $this->insert('{{%system_jobs}}', [
                'job_type_code' => self::TYPE_CODE,
                'name' => self::JOB_NAME,
                'description' => self::DESCRIZIONE,
                'params' => json_encode(['route' => self::ROUTE, 'args' => self::ARGS], JSON_UNESCAPED_SLASHES),
                'timeout' => 1800,
                'max_retries' => 0,
                'lane' => 'default',
                'overlap_policy' => 'skip',
                'catch_up_threshold_seconds' => 3600,
                'notify_on_failure_to' => 'dev@sharkom.net',
                'enabled' => 1,
                'internal' => 0,
                'simulate_in_dev' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $jobId = (int) $this->db->getLastInsertID();
            echo "    + job '" . self::JOB_NAME . "' creato (id {$jobId})\n";
        }

        $adesso = new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE));
        if (!$this->hasDefinition($jobId, self::RRULE_NOTTE)) {
            $this->insertDefinition($jobId, self::RRULE_NOTTE, $adesso, $now);
        }
        if ($this->hasDefinition($jobId, self::RRULE_UNICA)) {
            echo "    > esecuzione unica gia' programmata per job_id={$jobId}\n";
            return;
        }
        $definitionId = $this->insertDefinition(
            $jobId,
            self::RRULE_UNICA,
            $adesso->modify('+' . self::RITARDO_PRIMA_ESECUZIONE . ' seconds'),
            $now
        );
        $this->materialize($definitionId);
    }

    private function insertDefinition(int $jobId, string $rrule, DateTimeImmutable $dtstart, int $now): int
    {
        $this->insert('{{%schedule_definitions}}', [
            'system_job_id' => $jobId,
            'rrule' => $rrule,
            'dtstart' => $dtstart->format('Y-m-d H:i:s'),
            'until' => null,
            'timezone' => self::TIMEZONE,
            'enabled' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $id = (int) $this->db->getLastInsertID();
        echo "    + schedule {$rrule} (dtstart {$dtstart->format('Y-m-d H:i:s')}) per job_id={$jobId}\n";
        return $id;
    }

    private function materialize(int $definitionId): void
    {
        $manuale = 'lanciare a mano: php yii ' . self::ROUTE . ' ' . implode(' ', self::ARGS);
        $class = '\\sharkom\\jobDispatcher\\services\\Materializer';
        if (!class_exists($class)) {
            echo "    ! Materializer del dispatcher non disponibile: esecuzione unica non pianificata, {$manuale}\n";
            return;
        }
        // Il Materializer legge la definition con ActiveRecord, cioe' su Yii::$app->db:
        // con `migrate --db=<altro>` scriverebbe sul DB sbagliato.
        if ($this->db !== Yii::$app->db) {
            echo "    > connessione diversa da Yii::\$app->db: esecuzione unica non materializzata, {$manuale}\n";
            return;
        }
        try {
            $report = (new $class(null, $this->db))->rematerializeForDefinition($definitionId, 1);
        } catch (\Throwable $e) {
            echo "    ! materializzazione dell'esecuzione unica non riuscita: {$e->getMessage()}; {$manuale}\n";
            return;
        }
        if (!empty($report->errors)) {
            echo "    ! materializzazione dell'esecuzione unica non riuscita: " . implode('; ', $report->errors) . "; {$manuale}\n";
            return;
        }
        echo "    + esecuzione unica pianificata ({$report->eventsInserted} evento)\n";
    }

    private function scheduleWithCronJob(): void
    {
        $exists = (new Query())->from('{{%cron_job}}')->where(['like', 'command', self::ROUTE])->exists($this->db);
        if ($exists) {
            echo "    > cron_job per " . self::ROUTE . " gia' presente\n";
            return;
        }
        $this->insert('{{%cron_job}}', [
            'name' => self::JOB_NAME,
            'schedule' => self::CRON_SCHEDULE,
            'command' => PHP_BINARY . ' ' . $this->yiiScript() . ' ' . self::ROUTE . ' ' . implode(' ', self::ARGS),
            'logfile' => '',
            'active' => 1,
        ]);
        echo "    + cron_job '" . self::JOB_NAME . "' (" . self::CRON_SCHEDULE . ")\n";
    }

    private function yiiScript(): string
    {
        $path = Yii::getAlias('@vendor/../yii');
        $real = realpath($path);
        return $real !== false ? $real : $path;
    }

    private function findJobId()
    {
        return (new Query())
            ->select('id')->from('{{%system_jobs}}')->where(['name' => self::JOB_NAME])->scalar($this->db);
    }

    private function hasDefinition(int $jobId, string $rrule): bool
    {
        return (new Query())
            ->from('{{%schedule_definitions}}')
            ->where(['system_job_id' => $jobId, 'rrule' => $rrule])
            ->exists($this->db);
    }

    protected function jobDispatcherAvailable(): bool
    {
        $tables = $this->db->schema->getTableNames('', true);
        return in_array('system_jobs', $tables, true)
            && in_array('schedule_definitions', $tables, true)
            && in_array('system_job_types', $tables, true)
            && (new Query())
                ->from('{{%system_job_types}}')->where(['code' => self::TYPE_CODE])->exists($this->db);
    }

    protected function cronJobAvailable(): bool
    {
        return $this->db->getTableSchema('{{%cron_job}}', true) !== null;
    }
}
