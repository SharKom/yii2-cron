<?php

declare(strict_types=1);

namespace sharkom\cron\tests\unit;

use Codeception\Test\Unit;
use sharkom\cron\commands\CronController;
use Yii;
use yii\db\Query;
use yii\db\Transaction;

/**
 * cron/cron/run non pulisce piu' commands_spool: la pulizia e' cron/maintenance/purge-spool.
 */
final class CronControllerRunTest extends Unit
{
    private ?Transaction $transazione = null;

    protected function _before(): void
    {
        $this->transazione = Yii::$app->db->beginTransaction();
    }

    protected function _after(): void
    {
        if ($this->transazione !== null && $this->transazione->isActive) {
            $this->transazione->rollBack();
        }
    }

    public function testRunNonCancellaPiuLeRigheDiCommandsSpool(): void
    {
        $this->assertFalse(method_exists(CronController::class, 'purgeLogs'));

        // Nessun job dovuto: il run non accoda nulla.
        Yii::$app->db->createCommand()->update('cron_job', ['active' => 0])->execute();
        Yii::$app->db->createCommand()->insert('commands_spool', [
            'command' => 'echo test',
            'provenience' => 'test_retention',
            'created_at' => '2020-01-01 00:00:00',
            'completed' => 1,
        ])->execute();
        $id = (int) Yii::$app->db->getLastInsertID();

        (new CronController('cron', Yii::$app->getModule('cron')))->actionRun();

        $this->assertTrue((new Query())->from('commands_spool')->where(['id' => $id])->exists());
    }

    private function controller(bool $classiDisponibili, string $marcatore): CronController
    {
        return new class('cron', Yii::$app->getModule('cron'), ['classi' => $classiDisponibili, 'marcatore' => $marcatore]) extends CronController {
            public bool $classi = true;
            public string $marcatore = '';

            protected function maintenanceAvailable(): bool
            {
                return $this->classi;
            }

            protected function fallbackMarkerFile(): string
            {
                return $this->marcatore;
            }
        };
    }

    private function riga(int $completed): int
    {
        Yii::$app->db->createCommand()->insert('commands_spool', [
            'command' => 'echo test',
            'provenience' => 'test_retention',
            'created_at' => '2020-01-01 00:00:00',
            'completed' => $completed,
        ])->execute();
        return (int) Yii::$app->db->getLastInsertID();
    }

    private function esiste(int $id): bool
    {
        return (new Query())->from('commands_spool')->where(['id' => $id])->exists();
    }

    public function testSenzaClassiDiManutenzioneRunUsaIlRipiegoUnaVoltaAlGiorno(): void
    {
        $marcatore = sys_get_temp_dir() . '/spool-fallback-run-' . getmypid() . '-' . bin2hex(random_bytes(3)) . '.date';
        Yii::$app->db->createCommand()->update('cron_job', ['active' => 0])->execute();
        $conclusa = $this->riga(1);
        $pendente = $this->riga(0);

        try {
            $this->controller(false, $marcatore)->actionRun();
            $this->assertFalse($this->esiste($conclusa));
            $this->assertTrue($this->esiste($pendente), 'mai le righe pendenti');

            $seconda = $this->riga(1);
            $this->controller(false, $marcatore)->actionRun();
            $this->assertTrue($this->esiste($seconda), 'non piu\' di una volta al giorno');
        } finally {
            @unlink($marcatore);
        }
    }

    public function testConClassiDiManutenzioneRunNonCancellaENonSegna(): void
    {
        $marcatore = sys_get_temp_dir() . '/spool-fallback-run-' . getmypid() . '-' . bin2hex(random_bytes(3)) . '.date';
        Yii::$app->db->createCommand()->update('cron_job', ['active' => 0])->execute();
        $conclusa = $this->riga(1);

        $this->controller(true, $marcatore)->actionRun();

        $this->assertTrue($this->esiste($conclusa));
        $this->assertFileDoesNotExist($marcatore);
    }
}
