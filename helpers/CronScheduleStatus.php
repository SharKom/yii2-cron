<?php
namespace sharkom\cron\helpers;

use Cron\CronExpression;
use DateTime;
use DateTimeImmutable;

final class CronScheduleStatus
{
    public function __construct(
        public readonly CronState $state,
        public readonly ?string $schedule,
        public readonly ?DateTimeImmutable $nextRun,
        public readonly ?DateTimeImmutable $previousRun,
    ) {}

    public function isActive(): bool
    {
        return $this->state === CronState::ACTIVE;
    }

    public function isConfigured(): bool
    {
        return $this->state !== CronState::NOT_CONFIGURED;
    }

    public function nextRunFrom(DateTimeImmutable $from, int $nth = 0, bool $allowCurrent = true): ?DateTimeImmutable
    {
        if ($this->schedule === null || $this->nextRun === null) {
            return null;
        }
        try {
            $cron = CronExpression::factory($this->schedule);
            $fromMutable = DateTime::createFromImmutable($from);
            return DateTimeImmutable::createFromMutable($cron->getNextRunDate($fromMutable, $nth, $allowCurrent));
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function nthNextRun(int $nth): ?DateTimeImmutable
    {
        if ($this->schedule === null || $this->nextRun === null) {
            return null;
        }
        try {
            $cron = CronExpression::factory($this->schedule);
            return DateTimeImmutable::createFromMutable($cron->getNextRunDate('now', $nth));
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function lastDayOfMonthForNextRun(): ?DateTimeImmutable
    {
        if ($this->nextRun === null) {
            return null;
        }
        $lastDay = DateTime::createFromImmutable($this->nextRun);
        $lastDay->modify('last day of this month');
        return DateTimeImmutable::createFromMutable($lastDay);
    }
}
