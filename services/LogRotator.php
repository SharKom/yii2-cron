<?php

declare(strict_types=1);

namespace sharkom\cron\services;

use sharkom\devhelper\LogHelper;

/**
 * Rotazione dei file di log dei comandi dello spooler.
 *
 * Prima di aprire il log di un comando: se il file supera la soglia (parametro del modulo
 * cron log_rotate_max_mb, default 50 MB) viene spostato e compresso in `<file>.1.gz`; le
 * copie precedenti scalano fino a `<file>.<copie>.gz` (log_rotate_keep, default 5) e la
 * piu' vecchia si elimina. log_rotate_max_mb <= 0 disattiva la rotazione.
 *
 * Concorrenza: accanto al log c'e' `<file>.lock`. Chi scrive (lo spooler, per tutta la
 * durata del comando) tiene un lock condiviso (lockForWriting); la rotazione prende il
 * lock esclusivo SENZA attendere: se qualcuno scrive o sta gia' ruotando, si rinuncia e
 * si riprova al comando successivo. Il file si sposta con rename atomico, quindi chi
 * apre il percorso dopo trova un file nuovo; i byte aggiunti durante la compressione da
 * scrittori fuori dal protocollo (append brevi, es. error_log) vengono comunque letti
 * fino alla fine prima di chiudere il .gz.
 *
 * Nessuna eccezione esce da qui: in caso di problemi si logga un avviso e il comando
 * continua a scrivere sul file (al limite non ruotato). Se la compressione fallisce il
 * file torna al suo posto; se nel frattempo e' stato ricreato resta accanto come
 * `<file>.rotating.<pid>` (nessuna riga persa).
 */
class LogRotator
{
    public const DEFAULT_MAX_MB = 50;
    public const DEFAULT_KEEP = 5;
    public const LOG_CATEGORY = 'cron_log_rotation';
    /** Blocco di lettura/scrittura della compressione: mai il file intero in memoria. */
    public const CHUNK_BYTES = 1048576;

    /** Soglia in byte; <= 0 disattiva la rotazione. */
    public int $maxBytes;
    /** Numero di copie compresse da tenere (almeno 1). */
    public int $keep;

    public function __construct(int $maxBytes = self::DEFAULT_MAX_MB * 1048576, int $keep = self::DEFAULT_KEEP)
    {
        $this->maxBytes = $maxBytes;
        $this->keep = max(1, $keep);
    }

    /**
     * Dai parametri del modulo cron: log_rotate_max_mb (MB, default 50) e
     * log_rotate_keep (copie, default 5).
     */
    public static function fromParams(array $params): self
    {
        $maxMb = isset($params['log_rotate_max_mb']) && $params['log_rotate_max_mb'] !== ''
            ? (float) $params['log_rotate_max_mb'] : self::DEFAULT_MAX_MB;
        $keep = isset($params['log_rotate_keep']) && $params['log_rotate_keep'] !== ''
            ? (int) $params['log_rotate_keep'] : self::DEFAULT_KEEP;
        return new self((int) round($maxMb * 1048576), $keep);
    }

    /** Percorso della copia ruotata numero $n. */
    public static function rotatedPath(string $file, int $n): string
    {
        return $file . '.' . $n . '.gz';
    }

    /** True se $path e' una copia ruotata (`*.N.gz`). */
    public static function isRotatedCopy(string $path): bool
    {
        return preg_match('/\.\d+\.gz$/', $path) === 1;
    }

    /**
     * Ruota $file se supera la soglia. Mai eccezioni.
     *
     * @return bool true se il file e' stato ruotato
     */
    public function rotateIfNeeded(string $file): bool
    {
        if (!$this->overThreshold($file)) {
            return false;
        }
        $lock = null;
        try {
            $lock = @fopen($file . '.lock', 'c');
            if ($lock === false) {
                $lock = null;
                $this->warn("lock non disponibile per {$file}: rotazione saltata");
                return false;
            }
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                // Un altro processo scrive o sta ruotando: si riprova la prossima volta.
                return false;
            }
            // Ricontrollo sotto lock: un altro processo puo' averlo appena ruotato.
            if (!$this->overThreshold($file)) {
                return false;
            }
            return $this->rotate($file);
        } catch (\Throwable $e) {
            $this->warn("rotazione di {$file} non riuscita: " . $e->getMessage());
            return false;
        } finally {
            if (is_resource($lock)) {
                @flock($lock, LOCK_UN);
                @fclose($lock);
            }
        }
    }

    /**
     * Lock condiviso per chi scrive sul log: finche' e' tenuto nessuno ruota il file.
     * Attende la fine di una rotazione in corso. Mai eccezioni.
     *
     * @return resource|null da passare a releaseWriteLock()
     */
    public function lockForWriting(string $file)
    {
        try {
            $dir = dirname($file);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $lock = @fopen($file . '.lock', 'c');
            if ($lock === false) {
                return null;
            }
            if (!flock($lock, LOCK_SH)) {
                fclose($lock);
                return null;
            }
            return $lock;
        } catch (\Throwable $e) {
            $this->warn("lock di scrittura su {$file} non riuscito: " . $e->getMessage());
            return null;
        }
    }

    /** @param resource|null $lock */
    public function releaseWriteLock($lock): void
    {
        if (is_resource($lock)) {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    private function overThreshold(string $file): bool
    {
        if ($this->maxBytes <= 0) {
            return false;
        }
        clearstatcache(true, $file);
        if (!is_file($file)) {
            return false;
        }
        $size = @filesize($file);
        return $size !== false && $size >= $this->maxBytes;
    }

    /** Sotto lock esclusivo: sposta, comprime, scala le copie. */
    private function rotate(string $file): bool
    {
        $pid = (string) getmypid();
        $moved = $file . '.rotating.' . $pid;
        $gzTmp = $file . '.1.gz.tmp.' . $pid;

        if (!@rename($file, $moved)) {
            $this->warn("impossibile spostare {$file}: rotazione saltata");
            return false;
        }

        $ok = false;
        try {
            $ok = $this->compress($moved, $gzTmp);
        } catch (\Throwable $e) {
            $this->warn("compressione di {$file} non riuscita: " . $e->getMessage());
            $ok = false;
        }
        if (!$ok) {
            @unlink($gzTmp);
            $this->restore($moved, $file);
            return false;
        }

        // Scala le copie: la piu' vecchia si elimina, .N.gz -> .(N+1).gz.
        $oldest = self::rotatedPath($file, $this->keep);
        if (file_exists($oldest)) {
            @unlink($oldest);
        }
        for ($n = $this->keep - 1; $n >= 1; $n--) {
            $from = self::rotatedPath($file, $n);
            if (file_exists($from)) {
                @rename($from, self::rotatedPath($file, $n + 1));
            }
        }
        if (!@rename($gzTmp, self::rotatedPath($file, 1))) {
            $this->warn("impossibile creare " . self::rotatedPath($file, 1) . ": il log resta in {$moved}");
            @unlink($gzTmp);
            $this->restore($moved, $file);
            return false;
        }
        @unlink($moved);
        return true;
    }

    /** Rimette a posto il file spostato; se il percorso e' stato ricreato lo lascia accanto. */
    private function restore(string $moved, string $file): void
    {
        if (!file_exists($file) && @rename($moved, $file)) {
            return;
        }
        $this->warn("rotazione annullata: il log precedente resta in {$moved}");
    }

    /**
     * Comprime $source in $target a blocchi. Legge fino alla fine anche i byte aggiunti
     * durante la compressione. Sostituibile nei test.
     */
    protected function compress(string $source, string $target): bool
    {
        $in = @fopen($source, 'rb');
        if ($in === false) {
            return false;
        }
        $out = @gzopen($target, 'wb6');
        if ($out === false) {
            fclose($in);
            return false;
        }
        $ok = true;
        try {
            while (true) {
                $chunk = fread($in, self::CHUNK_BYTES);
                if ($chunk === false) {
                    $ok = false;
                    break;
                }
                if ($chunk === '') {
                    // Fine file: ricontrollo se qualcuno ha aggiunto byte nel frattempo.
                    clearstatcache(true, $source);
                    $size = @filesize($source);
                    if ($size === false || $size <= ftell($in)) {
                        break;
                    }
                    continue;
                }
                if (gzwrite($out, $chunk) !== strlen($chunk)) {
                    $ok = false;
                    break;
                }
            }
        } finally {
            fclose($in);
            if (!gzclose($out)) {
                $ok = false;
            }
        }
        return $ok;
    }

    private function warn(string $message): void
    {
        try {
            LogHelper::log('warning', 'log dei comandi: ' . $message, self::LOG_CATEGORY);
        } catch (\Throwable $e) {
            // Il logging non deve mai fermare il comando.
        }
    }
}
