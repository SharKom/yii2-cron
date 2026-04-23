<?php
namespace sharkom\cron\tests\unit;

use PHPUnit\Framework\TestCase;
use sharkom\cron\helpers\CronScheduleHelper;
use sharkom\cron\helpers\CronState;

class CronScheduleHelperTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CronScheduleHelper::resetInternalState();
    }

    protected function tearDown(): void
    {
        CronScheduleHelper::resetInternalState();
        parent::tearDown();
    }

    public function testStatusReturnsNotConfiguredWhenNoRowInDb(): void
    {
        CronScheduleHelper::setJobFinderForTests(fn($cmd) => null);
        $status = CronScheduleHelper::status('nonexistent/cmd');
        $this->assertSame(CronState::NOT_CONFIGURED, $status->state);
        $this->assertNull($status->schedule);
        $this->assertNull($status->nextRun);
    }

    public function testStatusReturnsActiveWhenJobActiveAndScheduleValid(): void
    {
        $fake = (object)['id' => 1, 'schedule' => '0 8 2,15 * *', 'active' => true];
        CronScheduleHelper::setJobFinderForTests(fn($cmd) => $fake);

        $status = CronScheduleHelper::status('invoices/generate');

        $this->assertSame(CronState::ACTIVE, $status->state);
        $this->assertSame('0 8 2,15 * *', $status->schedule);
        $this->assertNotNull($status->nextRun);
        $this->assertNotNull($status->previousRun);
    }

    public function testStatusReturnsInactiveButWithDatesWhenActiveFlagZero(): void
    {
        $fake = (object)['id' => 2, 'schedule' => '0 8 * * *', 'active' => false];
        CronScheduleHelper::setJobFinderForTests(fn($cmd) => $fake);

        $status = CronScheduleHelper::status('disabled/cmd');

        $this->assertSame(CronState::INACTIVE, $status->state);
        $this->assertNotNull($status->nextRun, 'INACTIVE deve popolare nextRun');
    }

    public function testStatusReturnsMisconfiguredWhenScheduleEmpty(): void
    {
        $fake = (object)['id' => 3, 'schedule' => '', 'active' => true];
        CronScheduleHelper::setJobFinderForTests(fn($cmd) => $fake);

        $status = CronScheduleHelper::status('broken/cmd');

        $this->assertSame(CronState::MISCONFIGURED, $status->state);
        $this->assertNull($status->schedule);
        $this->assertNull($status->nextRun);
    }

    public function testStatusReturnsMisconfiguredWhenScheduleInvalid(): void
    {
        $fake = (object)['id' => 4, 'schedule' => 'not-a-cron', 'active' => true];
        CronScheduleHelper::setJobFinderForTests(fn($cmd) => $fake);

        $status = CronScheduleHelper::status('invalid/cmd');

        $this->assertSame(CronState::MISCONFIGURED, $status->state);
        $this->assertNull($status->nextRun);
    }
}
