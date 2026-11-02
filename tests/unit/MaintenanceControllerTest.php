<?php

declare(strict_types=1);

namespace sharkom\cron\tests\unit;

use Codeception\Test\Unit;
use DateTimeImmutable;
use sharkom\cron\commands\MaintenanceController;
use sharkom\cron\services\SpoolRetentionService;
use sharkom\devhelper\maintenance\OptimizeResult;
use sharkom\devhelper\maintenance\RetentionReport;
use Yii;
use yii\console\ExitCode;
use yii\db\Query;
use yii\db\Transaction;

final class MaintenanceControllerTest extends Unit
{
    private ?Transaction $transazione = null;
    private array $parametri = [];

    protected function _before(): void
    {
        $this->parametri = Yii::$app->getModule('cron')->params;
        $this->transazione = Yii::$app->db->beginTransaction();
    }

    protected function _after(): void
    {
        if ($this->transazione !== null && $this->transazione->isActive) {
            $this->transazione->rollBack();
        }
        Yii::$app->getModule('cron')->params = $this->parametri;
    }

    private function riga(int $giorniFa): int
    {
        Yii::$app->db->createCommand()->insert('commands_spool', [
            'command' => 'echo test',
            'provenience' => 'test_retention',
            'created_at' => date('Y-m-d H:i:s', strtotime("-{$giorniFa} days")),
            'completed' => 1,
        ])->execute();
        return (int) Yii::$app->db->getLastInsertID();
    }

    private function esiste(int $id): bool
    {
        return (new Query())->from('commands_spool')->where(['id' => $id])->exists();
    }

    public function testSenzaDaysUsaPurgeSpoolDaysDelModulo(): void
    {
        $modulo = Yii::$app->getModule('cron');
        $modulo->params = ['purge_spool_days' => 30];
        $vecchia = $this->riga(40);
        $dentro = $this->riga(20);

        $comando = new MaintenanceController('maintenance', $modulo);

        $this->assertSame(ExitCode::OK, $comando->actionPurgeSpool());
        $this->assertFalse($this->esiste($vecchia));
        $this->assertTrue($this->esiste($dentro));
    }

    public function testDaysEsplicitoVinceSuiParametri(): void
    {
        $modulo = Yii::$app->getModule('cron');
        $modulo->params = ['purge_spool_days' => 30];
        $riga = $this->riga(20);

        $comando = new MaintenanceController('maintenance', $modulo);
        $comando->days = 14;

        $this->assertSame(ExitCode::OK, $comando->actionPurgeSpool());
        $this->assertFalse($this->esiste($riga));
    }

    public function testGiorniNonValidi(): void
    {
        $comando = new MaintenanceController('maintenance', Yii::$app->getModule('cron'));
        $comando->days = 0;

        $this->assertSame(ExitCode::USAGE, $comando->actionPurgeSpool());
    }

    /**
     * Exit code: 0 anche con ottimizzazione rinviata, sotto soglia, lock non ottenuto o
     * spazio insufficiente (si riprova la notte dopo); 1 solo su errore vero.
     */
    public function testExitCodeDallEsitoDellOttimizzazione(): void
    {
        $attesi = [
            OptimizeResult::DONE => ExitCode::OK,
            OptimizeResult::BELOW_THRESHOLD => ExitCode::OK,
            OptimizeResult::POSTPONED => ExitCode::OK,
            OptimizeResult::LOCK_TIMEOUT => ExitCode::OK,
            OptimizeResult::DISK_SPACE => ExitCode::OK,
            OptimizeResult::ERROR => ExitCode::UNSPECIFIED_ERROR,
        ];
        foreach ($attesi as $stato => $atteso) {
            $comando = new class('maintenance', Yii::$app->getModule('cron'), ['stato' => $stato]) extends MaintenanceController {
                public string $stato = '';

                protected function createService(): SpoolRetentionService
                {
                    return new class($this->stato) extends SpoolRetentionService {
                        private string $stato;

                        public function __construct(string $stato)
                        {
                            $this->stato = $stato;
                        }

                        public function run(int $days, bool $optimize, int $maxSeconds, ?DateTimeImmutable $now = null): RetentionReport
                        {
                            $report = new RetentionReport(self::TABLE, '2026-10-19 03:00:00');
                            $report->optimize = new OptimizeResult($this->stato, 'prova');
                            return $report;
                        }
                    };
                }
            };
            $comando->days = 14;
            $comando->optimize = 1;

            $this->assertSame($atteso, $comando->actionPurgeSpool(), "stato {$stato}");
        }
    }

    public function testSenzaClassiDiManutenzioneUsaIlRipiego(): void
    {
        $marcatore = sys_get_temp_dir() . '/spool-fallback-mc-' . getmypid() . '-' . bin2hex(random_bytes(3)) . '.date';
        $vecchia = $this->riga(40);
        Yii::$app->db->createCommand()->insert('commands_spool', [
            'command' => 'echo test',
            'provenience' => 'test_retention',
            'created_at' => date('Y-m-d H:i:s', strtotime('-40 days')),
            'completed' => 0,
        ])->execute();
        $pendente = (int) Yii::$app->db->getLastInsertID();

        $comando = new class('maintenance', Yii::$app->getModule('cron'), ['marcatore' => $marcatore]) extends MaintenanceController {
            public string $marcatore = '';

            protected function maintenanceAvailable(): bool
            {
                return false;
            }

            protected function fallbackMarkerFile(): string
            {
                return $this->marcatore;
            }

            protected function createService(): SpoolRetentionService
            {
                throw new \LogicException('senza classi di manutenzione il servizio non va creato');
            }
        };
        $comando->days = 14;
        $comando->optimize = 1;

        try {
            $this->assertSame(ExitCode::OK, $comando->actionPurgeSpool());
            $this->assertFalse($this->esiste($vecchia));
            $this->assertTrue($this->esiste($pendente), 'mai le righe pendenti');
            $this->assertSame(date('Y-m-d'), trim((string) file_get_contents($marcatore)), 'il comando segna la pulizia del giorno');
        } finally {
            @unlink($marcatore);
        }
    }
}
