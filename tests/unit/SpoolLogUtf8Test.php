<?php

declare(strict_types=1);

namespace sharkom\cron\tests\unit;

use Codeception\Test\Unit;
use sharkom\cron\commands\SpoolerController;
use sharkom\cron\models\CommandsSpool;
use Yii;
use yii\db\Query;
use yii\db\Transaction;

/**
 * Log dei job con caratteri UTF-8 tagliati.
 *
 * In produzione, il 16/11/2026 dalle 23:08 alle 23:25, il job 356960 non
 * riusciva a salvare la coda dei log (errore 1366). La coda era tagliata a
 * meta' di una cornice "┏━━━" del cleaning, e lo spooler ripartiva sempre da
 * quel job, bloccando tutta la coda.
 */
final class SpoolLogUtf8Test extends Unit
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

    public function testLaCodaNonTagliaICaratteriMultibyte(): void
    {
        $spooler = (new \ReflectionClass(SpoolerController::class))->newInstanceWithoutConstructor();
        $keepTail = new \ReflectionMethod(SpoolerController::class, 'keepTail');
        $keepTail->setAccessible(true);

        $buffer = '';
        // "━" sono 3 byte: con 10 byte di coda il taglio cadrebbe a meta' di un carattere.
        $args = [&$buffer, str_repeat('━', 20), 10];
        $keepTail->invokeArgs($spooler, $args);

        $this->assertTrue(mb_check_encoding($buffer, 'UTF-8'));
        $this->assertLessThanOrEqual(10, strlen($buffer));
        $this->assertSame(str_repeat('━', 3), $buffer);
    }

    public function testIlJobConLogTroncatoSiSalva(): void
    {
        $troncato = str_repeat('┏━━━ cleaning ', 5) . substr('━', 0, 2);
        $this->assertFalse(mb_check_encoding($troncato, 'UTF-8'));

        $spool = new CommandsSpool();
        $spool->command = 'cleaning/clean-invoices/clean-all supplier 1';
        $spool->provenience = 'test';
        $spool->provenience_id = 1;
        $spool->completed = 1;
        $spool->logs = $troncato;
        $spool->errors = "\xE2\x94";
        $this->assertTrue($spool->save(false));

        $riga = (new Query())->from('commands_spool')->where(['id' => $spool->id])->one();
        $this->assertTrue(mb_check_encoding((string) $riga['logs'], 'UTF-8'));
        $this->assertStringStartsWith('┏━━━ cleaning', (string) $riga['logs']);
        $this->assertTrue(mb_check_encoding((string) $riga['errors'], 'UTF-8'));
    }

    public function testIlTestoValidoNonCambia(): void
    {
        $this->assertSame('┏━━━ ok', CommandsSpool::utf8Valido('┏━━━ ok'));
    }
}
