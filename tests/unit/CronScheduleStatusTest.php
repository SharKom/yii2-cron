<?php
namespace sharkom\cron\tests\unit;

use PHPUnit\Framework\TestCase;
use sharkom\cron\helpers\CronScheduleStatus;
use sharkom\cron\helpers\CronState;

class CronScheduleStatusTest extends TestCase
{
    public function testIsActiveOnlyWhenStateIsActive(): void
    {
        $active = new CronScheduleStatus(CronState::ACTIVE, '0 8 * * *', null, null);
        $inactive = new CronScheduleStatus(CronState::INACTIVE, '0 8 * * *', null, null);

        $this->assertTrue($active->isActive());
        $this->assertFalse($inactive->isActive());
    }

    public function testIsConfiguredFalseOnlyWhenNotConfigured(): void
    {
        $notConf = new CronScheduleStatus(CronState::NOT_CONFIGURED, null, null, null);
        $miscon = new CronScheduleStatus(CronState::MISCONFIGURED, null, null, null);

        $this->assertFalse($notConf->isConfigured());
        $this->assertTrue($miscon->isConfigured());
    }

    public function testNextRunFromReturnsNullWhenNextRunIsNull(): void
    {
        $status = new CronScheduleStatus(CronState::NOT_CONFIGURED, null, null, null);
        $result = $status->nextRunFrom(new \DateTimeImmutable('2026-05-01 08:00:00'));
        $this->assertNull($result);
    }

    public function testNextRunFromComputesCorrectlyWhenScheduleValid(): void
    {
        $status = new CronScheduleStatus(
            CronState::ACTIVE,
            '0 8 2,15 * *',
            new \DateTimeImmutable('2026-05-02 08:00:00'),
            new \DateTimeImmutable('2026-04-15 08:00:00'),
        );
        $result = $status->nextRunFrom(new \DateTimeImmutable('2026-05-03 00:00:00'));
        $this->assertNotNull($result);
        $this->assertEquals('2026-05-15 08:00:00', $result->format('Y-m-d H:i:s'));
    }

    public function testNthNextRunReturnsFutureExecutions(): void
    {
        $status = new CronScheduleStatus(
            CronState::ACTIVE,
            '0 8 2,15 * *',
            new \DateTimeImmutable('2026-05-02 08:00:00'),
            null,
        );
        $secondNext = $status->nthNextRun(1);
        $this->assertNotNull($secondNext);
    }

    public function testLastDayOfMonthForNextRunReturnsLastDay(): void
    {
        $status = new CronScheduleStatus(
            CronState::ACTIVE,
            '0 8 2,15 * *',
            new \DateTimeImmutable('2026-05-02 08:00:00'),
            null,
        );
        $result = $status->lastDayOfMonthForNextRun();
        $this->assertNotNull($result);
        $this->assertEquals('2026-05-31', $result->format('Y-m-d'));
    }

    public function testLastDayOfMonthForNextRunNullIfNoNextRun(): void
    {
        $status = new CronScheduleStatus(CronState::NOT_CONFIGURED, null, null, null);
        $this->assertNull($status->lastDayOfMonthForNextRun());
    }
}
