<?php

declare(strict_types=1);

namespace sharkom\cron\services;

use DateTimeImmutable;
use InvalidArgumentException;
use sharkom\devhelper\LogHelper;
use sharkom\devhelper\maintenance\RetentionReport;
use sharkom\devhelper\maintenance\TableRetention;
use Yii;
use yii\db\Connection;
use yii\db\Query;

/**
 * Retention di commands_spool: cancella a blocchi le righe concluse (completed <> 0,
 * quindi anche -1) create prima della soglia o senza created_at; mai le righe
 * pendenti o in esecuzione (completed = 0) ne' quelle con completed NULL.
 *
 * File di log: logs_file e' condiviso tra le esecuzioni dello stesso job
 * (es. command_cron_job_22.log, /var/log/offers2mandates.log). Un file si rimuove solo
 * se sta dentro la cartella dei log attesa (@runtime/logs, la stessa dello spooler,
 * confronto sui realpath: niente `..` ne' symlink verso fuori) e se nessuna riga
 * rimasta in tabella lo usa ancora.
 */
class SpoolRetentionService
{
    public const TABLE = 'commands_spool';
    public const DEFAULT_DAYS = 14;
    public const LOG_CATEGORY = 'cron_retention';
    /**
     * Righe per blocco. Una riga di commands_spool pesa in media ~74 KB (logs, errors):
     * con 5.000 righe sarebbero ~370 MB di binlog per transazione, con 500 ~37 MB.
     */
    public const BATCH_SIZE = 500;

    /** Righe pendenti precedenti alla soglia dell'ultima run(): contate, mai cancellate. */
    public int $pendingOld = 0;

    private Connection $db;
    private TableRetention $retention;
    private string $logDir;

    public function __construct(?Connection $db = null, ?TableRetention $retention = null, ?string $logDir = null)
    {
        $this->db = $db ?? Yii::$app->db;
        if ($retention === null) {
            $retention = new TableRetention($this->db, self::LOG_CATEGORY);
            $retention->purger->batchSize = self::BATCH_SIZE;
        }
        $this->retention = $retention;
        $this->logDir = $logDir ?? Yii::getAlias('@runtime/logs');
    }

    /**
     * Giorni di retention dai parametri del modulo cron: purge_spool_days (giorni) oppure,
     * deprecato, purge_log_interval (mesi, convertito in mesi x 30).
     *
     * @return array{0:int, 1:?string} giorni e avviso di deprecazione
     */
    public static function resolveDays(array $params): array
    {
        return SpoolFallbackPurger::resolveDays($params);
    }

    public function run(int $days, bool $optimize, int $maxSeconds, ?DateTimeImmutable $now = null): RetentionReport
    {
        if ($days < 1) {
            throw new InvalidArgumentException("Giorni di retention non validi: {$days}");
        }
        $cutoff = ($now ?? new DateTimeImmutable())->modify("-{$days} days")->format('Y-m-d H:i:s');

        $candidates = [];
        $report = $this->retention->run(
            self::TABLE,
            ['and', ['<>', 'completed', 0], ['or', ['<', 'created_at', $cutoff], ['created_at' => null]]],
            'created_at',
            $cutoff,
            $maxSeconds,
            $optimize,
            ['logs_file'],
            function (array $rows) use (&$candidates): void {
                foreach ($rows as $row) {
                    $file = (string) ($row['logs_file'] ?? '');
                    if ($file !== '' && $this->isInsideLogDir($file)) {
                        $candidates[$file] = true;
                    }
                }
            }
        );
        $report->filesRemoved = $this->removeUnreferencedFiles(array_keys($candidates));
        $this->pendingOld = SpoolFallbackPurger::countPendingOld($this->db, $cutoff);

        LogHelper::log($report->isError() ? 'error' : 'info', $this->summary($report), self::LOG_CATEGORY);
        return $report;
    }

    /** Riepilogo del report piu' le righe pendenti oltre la soglia (le pendenti orfane si vedono). */
    public function summary(RetentionReport $report): string
    {
        return $report->summary() . ", righe pendenti precedenti alla soglia {$this->pendingOld} (non cancellate)";
    }

    /** True se $path e' un file regolare dentro la cartella dei log (realpath). */
    public function isInsideLogDir(string $path): bool
    {
        $dir = realpath($this->logDir);
        $real = realpath($path);
        if ($dir === false || $real === false || !is_file($real)) {
            return false;
        }
        $prefix = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return strncmp($real, $prefix, strlen($prefix)) === 0;
    }

    /**
     * @param string[] $files percorsi come scritti in logs_file
     */
    private function removeUnreferencedFiles(array $files): int
    {
        $removed = 0;
        foreach (array_chunk($files, 500) as $chunk) {
            $stillUsed = (new Query())
                ->select('logs_file')->distinct()
                ->from(self::TABLE)
                ->where(['logs_file' => $chunk])
                ->column($this->db);
            foreach (array_diff($chunk, $stillUsed) as $file) {
                // Ricontrollo al momento della rimozione e cancello il percorso risolto.
                $real = realpath($file);
                if ($real !== false && $this->isInsideLogDir($real) && @unlink($real)) {
                    $removed++;
                } else {
                    LogHelper::log('warning', "commands_spool: file di log non rimosso: {$file}", self::LOG_CATEGORY);
                }
            }
        }
        return $removed;
    }
}
