<?php

declare(strict_types=1);

namespace sharkom\cron\tests\unit;

use Codeception\Test\Unit;
use Yii;
use yii\db\Query;
use yii\db\Transaction;

require_once dirname(__DIR__, 2) . '/migrations/m261104_120000_cron_job_log_in_runtime.php';

/**
 * Migrazione dei cron_job con logfile in /var/log/ verso @runtime/logs (logfile vuoto).
 * Su soundorgan_test, in una transazione annullata in _after.
 */
final class CronJobLogInRuntimeMigrationTest extends Unit
{
    private ?Transaction $transazione = null;

    protected function _before(): void
    {
        $this->transazione = Yii::$app->db->beginTransaction();
        ob_start();
    }

    protected function _after(): void
    {
        ob_end_clean();
        if ($this->transazione !== null && $this->transazione->isActive) {
            $this->transazione->rollBack();
        }
    }

    private function job(string $logfile): int
    {
        Yii::$app->db->createCommand()->insert('cron_job', [
            'name' => 'test migrazione log',
            'schedule' => '0 0 1 1 *',
            'command' => 'echo test',
            'logfile' => $logfile,
            'active' => 0,
        ])->execute();
        return (int) Yii::$app->db->getLastInsertID();
    }

    private function logfile(int $id): string
    {
        return (string) (new Query())->select('logfile')->from('cron_job')->where(['id' => $id])->scalar();
    }

    private function migrazione(): \m261104_120000_cron_job_log_in_runtime
    {
        return new \m261104_120000_cron_job_log_in_runtime(['db' => Yii::$app->db]);
    }

    /** @return array<int, string> id => logfile di tutta la tabella */
    private function tutti(): array
    {
        return (new Query())->select(['logfile', 'id'])->from('cron_job')->indexBy('id')->column();
    }

    public function testUpSvuotaSoloILogfileInVarLog(): void
    {
        $inVarLog = $this->job('/var/log/offers2mandates.log');
        $altroInVarLog = $this->job('/var/log/invoices.log');
        $vuoto = $this->job('');
        $runtime = $this->job('/var/www/html/console/runtime/logs/mio.log');
        $simile = $this->job('/var/logs/altro.log');
        $prima = $this->tutti();

        $this->assertTrue($this->migrazione()->up() !== false);

        $this->assertSame('', $this->logfile($inVarLog));
        $this->assertSame('', $this->logfile($altroInVarLog));
        $this->assertSame('', $this->logfile($vuoto));
        $this->assertSame('/var/www/html/console/runtime/logs/mio.log', $this->logfile($runtime));
        $this->assertSame('/var/logs/altro.log', $this->logfile($simile));
        foreach ($this->tutti() as $id => $logfile) {
            $atteso = strpos($prima[$id], '/var/log/') === 0 ? '' : $prima[$id];
            $this->assertSame($atteso, $logfile, "cron_job {$id}");
        }
        $this->assertSame(0, (int) (new Query())->from('cron_job')->where(['like', 'logfile', '/var/log/%', false])->count());
    }

    public function testSecondoUpNonCambiaNulla(): void
    {
        $this->job('/var/log/offers2mandates.log');
        $this->job('/var/www/html/console/runtime/logs/mio.log');
        $this->migrazione()->up();
        $dopoIlPrimo = $this->tutti();
        ob_clean();

        $this->migrazione()->up();

        $this->assertSame($dopoIlPrimo, $this->tutti());
        $this->assertStringContainsString('nessun cron_job con logfile in /var/log/', (string) ob_get_contents());
    }

    public function testDownNonRipristinaEDichiaraIlMotivo(): void
    {
        $id = $this->job('/var/log/offers2mandates.log');
        $this->migrazione()->up();
        $this->assertStringContainsString('UPDATE cron_job SET logfile = \'/var/log/offers2mandates.log\' WHERE id = ' . $id, (string) ob_get_contents());
        ob_clean();

        $this->assertTrue($this->migrazione()->down() !== false);

        $this->assertSame('', $this->logfile($id));
        $this->assertStringContainsString('non vengono ripristinati', (string) ob_get_contents());
    }
}
