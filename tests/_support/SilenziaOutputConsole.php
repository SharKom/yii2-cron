<?php

declare(strict_types=1);

namespace sharkom\cron\tests;

/**
 * Per i controller console nei test: stdout()/stderr() non scrivono, cosi' i riepiloghi
 * dei comandi non sporcano l'output della suite. Restituiscono i byte come l'originale.
 */
trait SilenziaOutputConsole
{
    public function stdout($string)
    {
        return strlen((string) $string);
    }

    public function stderr($string)
    {
        return strlen((string) $string);
    }
}
