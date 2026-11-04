<?php

declare(strict_types=1);

namespace sharkom\cron\tests\unit;

use Codeception\Test\Unit;
use sharkom\cron\services\LogRotator;
use yii\helpers\FileHelper;

/**
 * Rotazione dei log dei comandi dello spooler: solo file in una cartella temporanea
 * creata dalla prova, nessun DB e nessun log reale.
 */
final class LogRotatorTest extends Unit
{
    private const SOGLIA = 1000;

    private string $cartella;
    private string $log;

    protected function _before(): void
    {
        $this->cartella = sys_get_temp_dir() . '/log-rotator-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir($this->cartella, 0777, true);
        $this->log = $this->cartella . '/command_cron_job_5.log';
    }

    protected function _after(): void
    {
        LogRotator::$ownershipChanger = null;
        @chmod($this->cartella, 0777);
        FileHelper::removeDirectory($this->cartella);
    }

    private function scrivi(string $contenuto): void
    {
        file_put_contents($this->log, $contenuto);
    }

    private function contenuto(int $byte, string $etichetta): string
    {
        return substr(str_repeat("[{$etichetta}] riga di log del comando\n", (int) ceil($byte / 20) + 1), 0, $byte);
    }

    private function decomprimi(string $gz): string
    {
        $this->assertFileExists($gz);
        return gzdecode((string) file_get_contents($gz));
    }

    public function testIFileNatiDaRootPrendonoIlProprietarioDellaCartella(): void
    {
        // Lo spooler gira come root nel container: .lock e copia compressa vanno
        // riallineati, altrimenti un processo www-data non scriverebbe piu' il log.
        $toccati = [];
        LogRotator::$ownershipChanger = function (string $path) use (&$toccati) {
            $toccati[] = basename($path);
        };
        $this->scrivi($this->contenuto(self::SOGLIA + 10, 'a'));

        $this->assertTrue((new LogRotator(self::SOGLIA, 5))->rotateIfNeeded($this->log));

        $this->assertContains('command_cron_job_5.log.lock', $toccati);
        $this->assertContains('command_cron_job_5.log.1.gz', $toccati);
    }

    public function testSenzaRootIlProprietarioNonSiTocca(): void
    {
        // Da www-data (come girano i test) alignOwnership non deve fare niente ne' fallire.
        $this->scrivi('x');
        $prima = fileowner($this->log);

        LogRotator::alignOwnership($this->log);

        $this->assertSame($prima, fileowner($this->log));
    }

    public function testSottoSogliaNessunaRotazione(): void
    {
        $this->scrivi($this->contenuto(self::SOGLIA - 1, 'a'));

        $this->assertFalse((new LogRotator(self::SOGLIA, 5))->rotateIfNeeded($this->log));

        $this->assertSame(self::SOGLIA - 1, filesize($this->log));
        $this->assertFileDoesNotExist(LogRotator::rotatedPath($this->log, 1));
    }

    public function testOltreSogliaRuotaInGzConContenutoIdentico(): void
    {
        $originale = $this->contenuto(self::SOGLIA + 500, 'b');
        $this->scrivi($originale);

        $this->assertTrue((new LogRotator(self::SOGLIA, 5))->rotateIfNeeded($this->log));

        $this->assertFileDoesNotExist($this->log, 'lo spooler ricrea il file');
        $this->assertSame($originale, $this->decomprimi(LogRotator::rotatedPath($this->log, 1)));
        $this->assertSame([], glob($this->log . '.rotating.*'));
        $this->assertSame([], glob($this->log . '.*.tmp.*'));
    }

    public function testFileGrandeCompressoABlocchi(): void
    {
        // Piu' blocchi di lettura (CHUNK_BYTES = 1 MB).
        $originale = $this->contenuto(LogRotator::CHUNK_BYTES * 2 + 12345, 'c');
        $this->scrivi($originale);

        $this->assertTrue((new LogRotator(self::SOGLIA, 5))->rotateIfNeeded($this->log));

        $this->assertSame(md5($originale), md5($this->decomprimi(LogRotator::rotatedPath($this->log, 1))));
    }

    public function testCatenaFinoACinqueConEliminazioneDellaSesta(): void
    {
        $rotatore = new LogRotator(self::SOGLIA, 5);
        $contenuti = [];
        for ($i = 1; $i <= 6; $i++) {
            $contenuti[$i] = $this->contenuto(self::SOGLIA + $i, "giro {$i}");
            $this->scrivi($contenuti[$i]);
            $this->assertTrue($rotatore->rotateIfNeeded($this->log), "giro {$i}");
        }

        // .1.gz e' l'ultimo giro (6), .5.gz il secondo; il primo e' stato eliminato.
        for ($n = 1; $n <= 5; $n++) {
            $this->assertSame($contenuti[7 - $n], $this->decomprimi(LogRotator::rotatedPath($this->log, $n)), ".{$n}.gz");
        }
        $this->assertFileDoesNotExist(LogRotator::rotatedPath($this->log, 6));
    }

    public function testErroreDiCompressioneNonImpedisceLaScrittura(): void
    {
        $originale = $this->contenuto(self::SOGLIA + 10, 'd');
        $this->scrivi($originale);
        $rotatore = new class(self::SOGLIA, 5) extends LogRotator {
            protected function compress(string $source, string $target): bool
            {
                throw new \RuntimeException('disco pieno (simulato)');
            }
        };

        $this->assertFalse($rotatore->rotateIfNeeded($this->log));

        $this->assertSame($originale, file_get_contents($this->log), 'il file torna al suo posto');
        $this->assertFileDoesNotExist(LogRotator::rotatedPath($this->log, 1));
        $this->assertSame([], glob($this->log . '.*.tmp.*'));
        $fh = fopen($this->log, 'ab');
        $this->assertNotFalse($fh);
        fwrite($fh, "riga dopo l'errore\n");
        fclose($fh);
        $this->assertSame($originale . "riga dopo l'errore\n", file_get_contents($this->log));
    }

    public function testCartellaNonScrivibileNonImpedisceLaScrittura(): void
    {
        $originale = $this->contenuto(self::SOGLIA + 10, 'e');
        $this->scrivi($originale);
        $rotatore = new LogRotator(self::SOGLIA, 5);
        // Lock gia' presente, cartella in sola lettura: il rename fallisce.
        $lock = $rotatore->lockForWriting($this->log);
        $rotatore->releaseWriteLock($lock);
        chmod($this->cartella, 0555);
        if (is_writable($this->cartella)) {
            $this->markTestSkipped('utente con permessi di scrittura ovunque (root)');
        }

        $this->assertFalse($rotatore->rotateIfNeeded($this->log));

        $fh = fopen($this->log, 'ab');
        $this->assertNotFalse($fh);
        fwrite($fh, "ancora scrivibile\n");
        fclose($fh);
        $this->assertSame($originale . "ancora scrivibile\n", file_get_contents($this->log));
    }

    public function testNessunaRotazioneMentreUnAltroScrive(): void
    {
        $this->scrivi($this->contenuto(self::SOGLIA + 10, 'f'));
        $rotatore = new LogRotator(self::SOGLIA, 5);

        $lock = $rotatore->lockForWriting($this->log);
        $this->assertNotNull($lock);
        $this->assertFalse((new LogRotator(self::SOGLIA, 5))->rotateIfNeeded($this->log), 'lock condiviso tenuto da chi scrive');
        $this->assertFileExists($this->log);

        $rotatore->releaseWriteLock($lock);
        $this->assertTrue($rotatore->rotateIfNeeded($this->log));
    }

    public function testParametriDelModulo(): void
    {
        $default = LogRotator::fromParams([]);
        $this->assertSame(50 * 1048576, $default->maxBytes);
        $this->assertSame(5, $default->keep);

        $personalizzato = LogRotator::fromParams(['log_rotate_max_mb' => 10, 'log_rotate_keep' => 3]);
        $this->assertSame(10 * 1048576, $personalizzato->maxBytes);
        $this->assertSame(3, $personalizzato->keep);

        $this->assertSame(1, LogRotator::fromParams(['log_rotate_keep' => 0])->keep);

        $disattivato = LogRotator::fromParams(['log_rotate_max_mb' => 0]);
        $this->scrivi($this->contenuto(self::SOGLIA, 'g'));
        $this->assertFalse($disattivato->rotateIfNeeded($this->log));
    }

    public function testCopieRuotateRiconosciute(): void
    {
        $this->assertTrue(LogRotator::isRotatedCopy('/x/command_cron_job_5.log.1.gz'));
        $this->assertTrue(LogRotator::isRotatedCopy('/x/a.log.12.gz'));
        $this->assertFalse(LogRotator::isRotatedCopy('/x/a.log'));
        $this->assertFalse(LogRotator::isRotatedCopy('/x/a.gz'));
    }
}
