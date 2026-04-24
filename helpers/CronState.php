<?php
namespace sharkom\cron\helpers;

enum CronState: string
{
    case NOT_CONFIGURED = 'not_configured';
    case INACTIVE       = 'inactive';
    case MISCONFIGURED  = 'misconfigured';
    case ACTIVE         = 'active';
}
