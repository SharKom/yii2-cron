<?php

declare(strict_types=1);

namespace sharkom\cron\tests\unit;

use Codeception\Test\Unit;
use sharkom\cron\Module;
use Yii;

/**
 * La suite gira su un'applicazione console collegata al solo DB di test soundorgan_test.
 */
final class TestDbGuardTest extends Unit
{
    public function testApplicazioneSulDbDiTest(): void
    {
        $this->assertNotNull(Yii::$app);
        $this->assertSame('soundorgan_test', Yii::$app->db->createCommand('SELECT DATABASE()')->queryScalar());
        $this->assertInstanceOf(Module::class, Yii::$app->getModule('cron'));
    }
}
