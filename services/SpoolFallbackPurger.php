<?php

declare(strict_types=1);

namespace sharkom\cron\services;

use DateTimeImmutable;
use InvalidArgumentException;
use Yii;
use yii\db\Connection;
use yii\db\Query;

/**
 * Pulizia semplice di commands_spool per i core senza le classi di manutenzione di
 * yii2-dev-helper (sharkom\devhelper\maintenance: oggi il core NormaFlow, che usa il
 * branch normaflow di dev-helper).
 *
 * Stessa protezione di SpoolRetentionService: cancella a blocchi (500 righe) solo le righe concluse
 * (completed <> 0, quindi mai le pendenti/in esecuzione ne' quelle con completed NULL)
 * create prima della soglia o senza created_at. Niente ottimizzazione della tabella e
 * niente rimozione dei file di log.
 *
 * Non usa nulla di sharkom\devhelper\maintenance: deve funzionare proprio quando manca.
 * Usata da cron/maintenance/purge-spool e, al massimo una volta al giorno (file
 * marcatore in @runtime), da cron/cron/run; mai quando le classi di manutenzione ci sono.
 */
class SpoolFallbackPurger
{
    public const TABLE = 'commands_spool';
    public const LOG_CATEGORY = 'cron_retention';
    public const DEFAULT_DAYS = 14;
    public const MAINTENANCE_CLASS = '\\sharkom\\devhelper\\maintenance\\TableRetention';
    public const MARKER_FILE = '@runtime/cron-spool-purge-fallback.date';

    /** Righe per blocco: ~74 KB a riga, 500 righe ~37 MB di binlog per transazione. */
    public int $batchSize = 500;
    /** Pausa tra i blocchi, in millisecondi. */
    public int $pauseMs = 100;

    private Connection $db;

    public function __construct(?Connection $db = null)
    {
        $this->db = $db ?? Yii::$app->db;
    }

    /** True se le classi di manutenzione di yii2-dev-helper sono installate. */
    public static function maintenanceAvailable(): bool
    {
        return class_exists(self::MAINTENANCE_CLASS);
    }

    /**
     * Giorni di retention dai parametri del modulo cron: purge_spool_days (giorni) oppure,
     * deprecato, purge_log_interval (mesi, convertito in mesi x 30).
     *
     * @return array{0:int, 1:?string} giorni e avviso di deprecazione
     */
    public static function resolveDays(array $params): array
    {
        if (isset($params['purge_spool_days'])) {
            return [(int) $params['purge_spool_days'], null];
        }
        if (isset($params['purge_log_interval'])) {
            $days = (int) $params['purge_log_interval'] * 30;
            return [$days, "Parametro purge_log_interval deprecato: usare purge_spool_days (convertito in {$days} giorni)"];
        }
        return [self::DEFAULT_DAYS, null];
    }

    /**
     * @param int $maxSeconds tempo massimo; 0 o negativo = nessun limite
     * @return array{deleted:int, batches:int, stoppedByTime:bool, cutoff:string}
     */
    public function purge(int $days, int $maxSeconds = 0, ?DateTimeImmutable $now = null): array
    {
        if ($days < 1) {
            throw new InvalidArgumentException("Giorni di retention non validi: {$days}");
        }
        $cutoff = ($now ?? new DateTimeImmutable())->modify("-{$days} days")->format('Y-m-d H:i:s');
        $condition = ['and', ['<>', 'completed', 0], ['or', ['<', 'created_at', $cutoff], ['created_at' => null]]];
        $result = ['deleted' => 0, 'batches' => 0, 'stoppedByTime' => false, 'cutoff' => $cutoff];
        $start = microtime(true);

        while (true) {
            if ($maxSeconds > 0 && microtime(true) - $start >= $maxSeconds) {
                $result['stoppedByTime'] = true;
                break;
            }
            $ids = (new Query())
                ->select('id')
                ->from(self::TABLE)
                ->where($condition)
                ->orderBy(['created_at' => SORT_ASC, 'id' => SORT_ASC])
                ->limit($this->batchSize)
                ->column($this->db);
            if ($ids === []) {
                break;
            }
            $result['deleted'] += $this->db->createCommand()
                ->delete(self::TABLE, ['id' => array_map('intval', $ids)])
                ->execute();
            $result['batches']++;
            if (count($ids) < $this->batchSize) {
                break;
            }
            if ($this->pauseMs > 0) {
                usleep($this->pauseMs * 1000);
            }
        }

        return $result;
    }

    /**
     * Come purge(), ma al massimo una volta al giorno: il file marcatore contiene la data
     * dell'ultima pulizia. Se un altro processo sta gia' pulendo (lock sul marcatore) o
     * oggi e' gia' stato fatto, non fa nulla e restituisce null.
     *
     * @return array{deleted:int, batches:int, stoppedByTime:bool, cutoff:string}|null
     */
    public function purgeOncePerDay(string $markerFile, int $days, int $maxSeconds = 0, ?DateTimeImmutable $now = null): ?array
    {
        $now = $now ?? new DateTimeImmutable();
        $handle = @fopen($markerFile, 'c+');
        if ($handle === false) {
            return null;
        }
        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                return null;
            }
            if (trim((string) stream_get_contents($handle)) === $now->format('Y-m-d')) {
                return null;
            }
            // Segnata prima di pulire: se la pulizia fallisce si riprova domani, non a ogni minuto.
            self::writeDate($handle, $now);
            return $this->purge($days, $maxSeconds, $now);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** Segna la pulizia di oggi (dopo un'esecuzione esplicita del comando). */
    public static function markDone(string $markerFile, ?DateTimeImmutable $now = null): void
    {
        $handle = @fopen($markerFile, 'c+');
        if ($handle === false) {
            return;
        }
        if (flock($handle, LOCK_EX)) {
            self::writeDate($handle, $now ?? new DateTimeImmutable());
            flock($handle, LOCK_UN);
        }
        fclose($handle);
    }

    /** @param resource $handle */
    private static function writeDate($handle, DateTimeImmutable $now): void
    {
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, $now->format('Y-m-d') . "\n");
        fflush($handle);
    }
}
