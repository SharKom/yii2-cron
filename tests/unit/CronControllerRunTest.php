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
}
