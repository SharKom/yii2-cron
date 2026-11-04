<?php

declare(strict_types=1);

namespace sharkom\cron\tests\unit;

use Codeception\Test\Unit;
use DateTimeImmutable;
use InvalidArgumentException;
use sharkom\cron\services\SpoolRetentionService;
use sharkom\devhelper\maintenance\OptimizeResult;
use Yii;
use yii\db\Query;
use yii\db\Transaction;
use yii\helpers\FileHelper;

/**
 * Retention di commands_spool su soundorgan_test, in una transazione annullata.
 * Cartella dei log e cartella "fuori" sono temporanee e create dalla prova.
 */
final class SpoolRetentionServiceTest extends Unit
{
    private const ADESSO = '2026-11-02 03:00:00';
    private const VECCHIA = '2026-10-01 10:00:00';
    private const RECENTE = '2026-10-30 10:00:00';

    private ?Transaction $transazione = null;
    private string $base;
    private string $cartellaLog;
    private string $cartellaFuori;

    protected function _before(): void
    {
        $this->base = sys_get_temp_dir() . '/spool-retention-' . getmypid() . '-' . bin2hex(random_bytes(3));
        $this->cartellaLog = $this->base . '/logs';
        $this->cartellaFuori = $this->base . '/fuori';
        mkdir($this->cartellaLog, 0777, true);
        mkdir($this->cartellaFuori, 0777, true);
        $this->transazione = Yii::$app->db->beginTransaction();
    }

    protected function _after(): void
    {
        if ($this->transazione !== null && $this->transazione->isActive) {
            $this->transazione->rollBack();
        }
        FileHelper::removeDirectory($this->base);
    }

    /** @param int|null $completed 0 pendente, 1 concluso, -1 errore, null */
    private function riga(?string $creata, ?int $completed, ?string $file = null): int
    {
        Yii::$app->db->createCommand()->insert('commands_spool', [
            'command' => 'echo test',
            'provenience' => 'test_retention',
            'provenience_id' => 0,
            'logs_file' => $file,
            'created_at' => $creata,
            'completed' => $completed,
        ])->execute();
        return (int) Yii::$app->db->getLastInsertID();
    }

    /** File di log con mtime $modificato (default: vecchio, fuori dalle 24 ore). */
    private function file(string $cartella, string $nome, string $modificato = self::VECCHIA): string
    {
        $percorso = $cartella . '/' . $nome;
        file_put_contents($percorso, "log\n");
        touch($percorso, strtotime($modificato));
        return $percorso;
    }

    private function esiste(int $id): bool
    {
        return (new Query())->from('commands_spool')->where(['id' => $id])->exists();
    }

    private function servizio(): SpoolRetentionService
    {
        return new SpoolRetentionService(Yii::$app->db, null, $this->cartellaLog);
    }

    public function testCancellaSoloRigheConcluseOltreLaRetention(): void
    {
        $conclusa = $this->riga(self::VECCHIA, 1);
        $inErrore = $this->riga(self::VECCHIA, -1);
        $pendente = $this->riga(self::VECCHIA, 0);
        $completedNull = $this->riga(self::VECCHIA, null);
        $senzaData = $this->riga(null, 1);
        $senzaDataPendente = $this->riga(null, 0);
        $recente = $this->riga(self::RECENTE, 1);

        $this->servizio()->run(14, false, 60, new DateTimeImmutable(self::ADESSO));

        $this->assertFalse($this->esiste($conclusa));
        $this->assertFalse($this->esiste($inErrore));
        $this->assertTrue($this->esiste($pendente), 'mai le righe pendenti o in esecuzione');
        $this->assertTrue($this->esiste($completedNull));
        $this->assertFalse($this->esiste($senzaData));
        $this->assertTrue($this->esiste($senzaDataPendente));
        $this->assertTrue($this->esiste($recente));
    }

    public function testRimuoveSoloFileDentroLaCartellaENonPiuUsati(): void
    {
        $solo = $this->file($this->cartellaLog, 'solo.log');
        $condiviso = $this->file($this->cartellaLog, 'condiviso.log');
        $diPendente = $this->file($this->cartellaLog, 'pendente.log');
        $fuori = $this->file($this->cartellaFuori, 'fuori.log');
        $risalita = $this->file($this->cartellaFuori, 'risalita.log');
        $bersaglioLink = $this->file($this->cartellaFuori, 'bersaglio.log');
        $link = $this->cartellaLog . '/link.log';
        symlink($bersaglioLink, $link);

        $this->riga(self::VECCHIA, 1, $solo);
        $this->riga(self::VECCHIA, 1, $condiviso);
        $this->riga(self::RECENTE, 1, $condiviso);
        $this->riga(self::VECCHIA, 0, $diPendente);
        $this->riga(self::VECCHIA, 1, $fuori);
        $this->riga(self::VECCHIA, 1, $this->cartellaLog . '/../fuori/risalita.log');
        $this->riga(self::VECCHIA, 1, $link);

        $report = $this->servizio()->run(14, false, 60, new DateTimeImmutable(self::ADESSO));

        $this->assertFileDoesNotExist($solo);
        $this->assertFileExists($condiviso, 'usato ancora da una riga recente');
        $this->assertFileExists($diPendente, 'la riga pendente resta');
        $this->assertFileExists($fuori, 'fuori dalla cartella dei log');
        $this->assertFileExists($risalita, 'percorso con .. verso fuori');
        $this->assertFileExists($bersaglioLink, 'symlink verso fuori');
        $this->assertTrue(is_link($link));
        $this->assertSame(1, $report->filesRemoved);
    }

    public function testGiorniDaiParametriDelModulo(): void
    {
        $this->assertSame([14, null], SpoolRetentionService::resolveDays([]));
        $this->assertSame([7, null], SpoolRetentionService::resolveDays(['purge_spool_days' => 7]));
        $this->assertSame([7, null], SpoolRetentionService::resolveDays(['purge_spool_days' => 7, 'purge_log_interval' => 3]));

        [$giorni, $avviso] = SpoolRetentionService::resolveDays(['purge_log_interval' => 3]);
        $this->assertSame(90, $giorni);
        $this->assertStringContainsString('purge_log_interval', $avviso);
        $this->assertStringContainsString('purge_spool_days', $avviso);
    }

    public function testOttimizzazioneSottoSogliaSenzaAlter(): void
    {
        $this->riga(self::VECCHIA, 1);

        $report = $this->servizio()->run(14, true, 60, new DateTimeImmutable(self::ADESSO));

        $this->assertSame(OptimizeResult::BELOW_THRESHOLD, $report->optimize->status);
        $this->assertSame(1, (int) Yii::$app->db->createCommand('SELECT @@in_transaction')->queryScalar());
    }

    public function testGiorniNonValidi(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->servizio()->run(0, false, 60);
    }

    /** Righe medie di commands_spool ~74 KB: blocchi da 500 (~37 MB di binlog per transazione). */
    private function inserisciConcluseVecchie(int $quante): void
    {
        $righe = [];
        for ($i = 0; $i < $quante; $i++) {
            $righe[] = ['echo test', 'test_retention', self::VECCHIA, 1];
        }
        Yii::$app->db->createCommand()
            ->batchInsert('commands_spool', ['command', 'provenience', 'created_at', 'completed'], $righe)
            ->execute();
    }

    public function testBlocchiDa500Righe(): void
    {
        $this->inserisciConcluseVecchie(501);

        $servizio = new SpoolRetentionService(Yii::$app->db, null, $this->cartellaLog);
        $report = $servizio->run(14, false, 0, new DateTimeImmutable(self::ADESSO));

        $this->assertSame(SpoolRetentionService::BATCH_SIZE, 500);
        $this->assertSame(501, $report->purge->deleted);
        $this->assertSame(2, $report->purge->batches);
    }

    public function testContaLePendentiOltreLaSogliaSenzaCancellarle(): void
    {
        $vecchiaPendente = $this->riga(self::VECCHIA, 0);
        $senzaDataPendente = $this->riga(null, 0);
        $this->riga(self::RECENTE, 0);
        $this->riga(self::VECCHIA, 1);

        $servizio = $this->servizio();
        $report = $servizio->run(14, false, 60, new DateTimeImmutable(self::ADESSO));

        $this->assertSame(2, $servizio->pendingOld);
        $this->assertTrue($this->esiste($vecchiaPendente));
        $this->assertTrue($this->esiste($senzaDataPendente));
        $this->assertStringContainsString('righe pendenti precedenti alla soglia 2 (non cancellate)', $servizio->summary($report));
    }

    public function testStessoFileScrittoAttraversoUnSymlinkNonSiCancella(): void
    {
        // Come sul dev: /var/www/html/console/runtime -> /var/soundorgan/runtime/console.
        $alias = $this->base . '/alias';
        symlink($this->cartellaLog, $alias);
        $condiviso = $this->file($this->cartellaLog, 'command_cron_job_5.log');

        $this->riga(self::VECCHIA, 1, $condiviso);
        $this->riga(self::RECENTE, 1, $alias . '/command_cron_job_5.log');

        $report = $this->servizio()->run(14, false, 60, new DateTimeImmutable(self::ADESSO));

        $this->assertFileExists($condiviso, 'stesso realpath di un logs_file ancora usato');
        $this->assertSame(0, $report->filesRemoved);
    }

    public function testFileModificatoNelleUltime24OreNonSiTocca(): void
    {
        $recente = $this->file($this->cartellaLog, 'recente.log', '2026-11-02 01:00:00');
        $vecchio = $this->file($this->cartellaLog, 'vecchio.log', '2026-11-01 02:00:00');
        $this->riga(self::VECCHIA, 1, $recente);
        $this->riga(self::VECCHIA, 1, $vecchio);

        $report = $this->servizio()->run(14, false, 60, new DateTimeImmutable(self::ADESSO));

        $this->assertFileExists($recente, 'modificato 2 ore prima');
        $this->assertFileDoesNotExist($vecchio, 'modificato 25 ore prima');
        $this->assertSame(1, $report->filesRemoved);
    }

    public function testCopieRuotateNonSonoOrfani(): void
    {
        $base = $this->file($this->cartellaLog, 'ruotato.log');
        $copia1 = $this->file($this->cartellaLog, 'ruotato.log.1.gz');
        $copia5 = $this->file($this->cartellaLog, 'ruotato.log.5.gz');
        $this->riga(self::VECCHIA, 1, $base);
        $this->riga(self::VECCHIA, 1, $copia1);
        $this->riga(self::VECCHIA, 1, $copia5);

        $report = $this->servizio()->run(14, false, 60, new DateTimeImmutable(self::ADESSO));

        $this->assertFileDoesNotExist($base, 'il log orfano si cancella');
        $this->assertFileExists($copia1);
        $this->assertFileExists($copia5);
        $this->assertSame(1, $report->filesRemoved);
    }

    public function testOrfanoVecchioCancellatoSenzaFileDiAppoggio(): void
    {
        $orfano = $this->file($this->cartellaLog, 'orfano.log');
        $this->riga(self::VECCHIA, 1, $orfano);

        $report = $this->servizio()->run(14, false, 60, new DateTimeImmutable(self::ADESSO));

        $this->assertFileDoesNotExist($orfano);
        $this->assertSame([], glob($this->cartellaLog . '/*.purge.*'), 'nessun .purge.<pid> rimasto');
        $this->assertSame(1, $report->filesRemoved);
    }
}
