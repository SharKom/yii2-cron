<?php

namespace sharkom\cron\commands;

use sharkom\cron\services\SpoolRetentionService;
use sharkom\devhelper\LogHelper;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Manutenzione della coda comandi.
 *
 *   php yii cron/maintenance/purge-spool [--days=14] [--optimize=1] [--max-seconds=1200]
 *
 * Senza --days valgono i parametri del modulo cron: purge_spool_days (giorni, default 14)
 * oppure, deprecato, purge_log_interval (mesi, convertito in mesi x 30 con un avviso).
 * Programmato dalla migrazione m261102_160100 (dispatcher alle 2:45 oppure cron_job).
 * Alternativa manuale piu' semplice (senza file e senza ottimizzazione): cron/spooler/cleanup.
 */
class MaintenanceController extends Controller
{
    /** @var int|null giorni da tenere; null = dai parametri del modulo */
    public $days;
    /** @var int 1 = dopo la pulizia ricostruisce la tabella online, se conviene */
    public $optimize = 0;
    /** @var int tempo massimo della cancellazione, in secondi */
    public $maxSeconds = 1200;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['days', 'optimize', 'maxSeconds']);
    }

    public function actionPurgeSpool(): int
    {
        if ($this->days !== null && $this->days !== '') {
            $days = (int) $this->days;
        } else {
            [$days, $warning] = SpoolRetentionService::resolveDays($this->module->params);
            if ($warning !== null) {
                LogHelper::log('warning', $warning, SpoolRetentionService::LOG_CATEGORY);
            }
        }
        if ($days < 1) {
            $this->stderr("--days deve essere almeno 1\n");
            return ExitCode::USAGE;
        }

        $report = $this->createService()->run($days, (bool) (int) $this->optimize, (int) $this->maxSeconds);

        $this->stdout($report->summary() . "\n");
        // Ottimizzazione rinviata, sotto soglia, senza lock o senza spazio: esito 0, si
        // riprova la notte dopo. Solo un errore vero (es. ALTER fallito) da' 1.
        return $report->isError() ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /** Servizio di retention (sostituibile nei test). */
    protected function createService(): SpoolRetentionService
    {
        return new SpoolRetentionService();
    }
}
