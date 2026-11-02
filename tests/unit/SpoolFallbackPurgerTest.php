<?php

declare(strict_types=1);

namespace sharkom\cron\tests\unit;

use Codeception\Test\Unit;
use DateTimeImmutable;
use InvalidArgumentException;
use sharkom\cron\services\SpoolFallbackPurger;
use Yii;
use yii\db\Query;
use yii\db\Transaction;

/**
 * Ripiego senza le classi di manutenzione di yii2-dev-helper (core NormaFlow):
 * cancellazione a blocchi delle sole righe concluse, al massimo una volta al giorno.
 */
final class SpoolFallbackPurgerTest extends Unit
{
    private const ADESSO = '2026-11-02 03:00:00';
    private const VECCHIA = '2026-10-01 10:00:00';
    private const RECENTE = '2026-10-30 10:00:00';

    private ?Transaction $transazione = null;
    private string $marcatore;

    protected function _before(): void
    {
        $this->marcatore = sys_get_temp_dir() . '/spool-fallback-' . getmypid() . '-' . bin2hex(random_bytes(3)) . '.date';
        $this->transazione = Yii::$app->db->beginTransaction();
    }

    protected function _after(): void
    {
        if ($this->transazione !== null && $this->transazione->isActive) {
            $this->transazione->rollBack();
        }
        @unlink($this->marcatore);
    }

    private function riga(?string $creata, ?int $completed): int
    {
        Yii::$app->db->createCommand()->insert('commands_spool', [
            'command' => 'echo test',
            'provenience' => 'test_retention',
            'created_at' => $creata,
            'completed' => $completed,
        ])->execute();
        return (int) Yii::$app->db->getLastInsertID();
    }

    private function esiste(int $id): bool
    {
        return (new Query())->from('commands_spool')->where(['id' => $id])->exists();
    }

    private function purger(): SpoolFallbackPurger
    {
        $purger = new SpoolFallbackPurger(Yii::$app->db);
        $purger->batchSize = 2;
        $purger->pauseMs = 0;
        return $purger;
    }

    public function testCancellaABlocchiSoloRigheConcluseOltreLaRetention(): void
    {
        $conclusa = $this->riga(self::VECCHIA, 1);
        $inErrore = $this->riga(self::VECCHIA, -1);
        $pendente = $this->riga(self::VECCHIA, 0);
        $completedNull = $this->riga(self::VECCHIA, null);
        $senzaData = $this->riga(null, 1);
        $senzaDataPendente = $this->riga(null, 0);
        $recente = $this->riga(self::RECENTE, 1);

        $esito = $this->purger()->purge(14, 0, new DateTimeImmutable(self::ADESSO));

        $this->assertSame(3, $esito['deleted']);
        $this->assertSame(2, $esito['batches']);
        $this->assertFalse($this->esiste($conclusa));
        $this->assertFalse($this->esiste($inErrore));
        $this->assertTrue($this->esiste($pendente), 'mai le righe pendenti o in esecuzione');
        $this->assertTrue($this->esiste($completedNull));
        $this->assertFalse($this->esiste($senzaData));
        $this->assertTrue($this->esiste($senzaDataPendente));
        $this->assertTrue($this->esiste($recente));
    }

    public function testAlMassimoUnaVoltaAlGiorno(): void
    {
        $prima = $this->riga(self::VECCHIA, 1);
        $esito = $this->purger()->purgeOncePerDay($this->marcatore, 14, 0, new DateTimeImmutable(self::ADESSO));
        $this->assertNotNull($esito);
        $this->assertFalse($this->esiste($prima));

        $seconda = $this->riga(self::VECCHIA, 1);
        $this->assertNull($this->purger()->purgeOncePerDay($this->marcatore, 14, 0, new DateTimeImmutable('2026-11-02 23:59:00')));
        $this->assertTrue($this->esiste($seconda), 'stesso giorno: nessuna seconda pulizia');

        $this->assertNotNull($this->purger()->purgeOncePerDay($this->marcatore, 14, 0, new DateTimeImmutable('2026-11-03 00:01:00')));
        $this->assertFalse($this->esiste($seconda), 'giorno dopo: di nuovo');
    }

    public function testGiorniDaiParametriDelModulo(): void
    {
        $this->assertSame([14, null], SpoolFallbackPurger::resolveDays([]));
        $this->assertSame([7, null], SpoolFallbackPurger::resolveDays(['purge_spool_days' => 7, 'purge_log_interval' => 3]));
        [$giorni, $avviso] = SpoolFallbackPurger::resolveDays(['purge_log_interval' => 3]);
        $this->assertSame(90, $giorni);
        $this->assertStringContainsString('purge_spool_days', $avviso);
    }

    public function testGiorniNonValidi(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->purger()->purge(0);
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
        $purger = new SpoolFallbackPurger(Yii::$app->db);
        $purger->pauseMs = 0;

        $esito = $purger->purge(14, 0, new DateTimeImmutable(self::ADESSO));

        $this->assertSame(500, $purger->batchSize);
        $this->assertSame(501, $esito['deleted']);
        $this->assertSame(2, $esito['batches']);
    }
}
