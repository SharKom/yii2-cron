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
 * se:
 *  - sta dentro la cartella dei log attesa (@runtime/logs, la stessa dello spooler,
 *    confronto sui realpath: niente `..` ne' symlink verso fuori);
 *  - nessuna riga rimasta in tabella lo usa ancora, confrontando i realpath dei
 *    logs_file distinti e non il testo (lo stesso file e' scritto sia come
 *    /var/www/html/console/runtime/logs/... sia attraverso il symlink risolto);
 *  - non e' stato modificato nelle ultime 24 ore (RECENT_SECONDS);
 *  - non e' una copia ruotata `*.N.gz` dello spooler (LogRotator): quelle non sono orfani.
 * Cancellazione: rename in `<file>.purge.<pid>`, ricontrollo (ancora usato o modificato
 * di recente) e poi unlink, oppure rename indietro.
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
    /** Un file di log modificato da meno di cosi' (secondi) non si tocca. */
    public const RECENT_SECONDS = 86400;

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
        $now = $now ?? new DateTimeImmutable();
        $cutoff = $now->modify("-{$days} days")->format('Y-m-d H:i:s');

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
                    if ($file !== '' && !LogRotator::isRotatedCopy($file) && $this->isInsideLogDir($file)) {
                        $candidates[$file] = true;
                    }
                }
            }
        );
        $report->filesRemoved = $this->removeUnreferencedFiles(array_keys($candidates), $now->getTimestamp());
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
    private function removeUnreferencedFiles(array $files, int $now): int
    {
        // realpath => percorso come scritto (per i messaggi)
        $byReal = [];
        foreach ($files as $file) {
            $real = realpath($file);
            if ($real !== false && !LogRotator::isRotatedCopy($real) && $this->isInsideLogDir($real)) {
                $byReal[$real] = $file;
            }
        }
        if ($byReal === []) {
            return 0;
        }

        $removed = 0;
        $used = $this->usedRealPaths();
        foreach ($byReal as $real => $file) {
            if (isset($used[$real]) || $this->isRecent($real, $now)) {
                continue;
            }
            if ($this->purgeFile($real, $now)) {
                $removed++;
            } else {
                LogHelper::log('warning', "commands_spool: file di log non rimosso: {$file}", self::LOG_CATEGORY);
            }
        }
        return $removed;
    }

    /**
     * Rename in `<file>.purge.<pid>`, ricontrollo e unlink; se nel frattempo il file e'
     * tornato in uso (o e' stato modificato) lo rimette al suo posto.
     */
    private function purgeFile(string $real, int $now): bool
    {
        $parked = $real . '.purge.' . getmypid();
        if (!@rename($real, $parked)) {
            return false;
        }
        clearstatcache(true, $parked);
        $used = $this->usedRealPaths();
        if (!isset($used[$real]) && !$this->isRecent($parked, $now)) {
            if (@unlink($parked)) {
                return true;
            }
        }
        if (file_exists($real)) {
            // Ricreato nel frattempo (es. dallo spooler): la copia precedente resta accanto.
            LogHelper::log('warning', "commands_spool: {$real} ricreato durante la pulizia, il contenuto precedente resta in {$parked}", self::LOG_CATEGORY);
        } elseif (!@rename($parked, $real)) {
            LogHelper::log('warning', "commands_spool: impossibile rimettere a posto {$parked}", self::LOG_CATEGORY);
        }
        return false;
    }

    /** True se il file e' stato modificato nelle ultime RECENT_SECONDS rispetto a $now. */
    private function isRecent(string $path, int $now): bool
    {
        clearstatcache(true, $path);
        $mtime = @filemtime($path);
        // Senza mtime leggibile si sta dalla parte della prudenza.
        return $mtime === false || $mtime > $now - self::RECENT_SECONDS;
    }

    /**
     * Insieme (chiavi) dei percorsi risolti dei logs_file distinti rimasti in tabella.
     * Un file che non esiste piu' (es. appena rinominato in .purge.<pid>) si risolve con
     * il realpath della cartella piu' il nome.
     *
     * @return array<string, true>
     */
    private function usedRealPaths(): array
    {
        $query = (new Query())
            ->select('logs_file')->distinct()
            ->from(self::TABLE)
            ->where(['and', ['not', ['logs_file' => null]], ['<>', 'logs_file', '']]);
        $used = [];
        foreach ($query->batch(self::BATCH_SIZE, $this->db) as $rows) {
            foreach ($rows as $row) {
                $resolved = self::resolvePath((string) $row['logs_file']);
                if ($resolved !== null) {
                    $used[$resolved] = true;
                }
            }
        }
        return $used;
    }

    private static function resolvePath(string $path): ?string
    {
        $real = realpath($path);
        if ($real !== false) {
            return $real;
        }
        $dir = realpath(dirname($path));
        return $dir === false ? null : rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($path);
    }
}
