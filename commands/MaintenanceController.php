<?php

namespace sharkom\cron\commands;

use sharkom\cron\services\SpoolFallbackPurger;
use sharkom\cron\services\SpoolRetentionService;
use Yii;
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
 *
 * Senza le classi di manutenzione di yii2-dev-helper (core NormaFlow) usa il ripiego
 * SpoolFallbackPurger: stessa protezione delle righe pendenti, niente file ne'
 * ottimizzazione. In quel caso segna anche la pulizia del giorno, cosi' cron/cron/run
 * non la ripete.
 *
 * Exit code:
 *  - 0 (OK): pulizia eseguita; anche con ottimizzazione rinviata, sotto soglia, senza
 *    lock o senza spazio su disco (si riprova la notte dopo) e con la pulizia semplice;
 *  - 1 (UNSPECIFIED_ERROR): errore vero dell'ottimizzazione (es. ALTER TABLE fallito);
 *  - 64 (USAGE): --days minore di 1.
 * Le eccezioni non vengono catturate: un'interruzione del dispatcher
 * (InterruptedException durante il drain) risale fino al JobRunner.
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
            [$days, $warning] = SpoolFallbackPurger::resolveDays($this->module->params);
            if ($warning !== null) {
                LogHelper::log('warning', $warning, SpoolFallbackPurger::LOG_CATEGORY);
            }
        }
        if ($days < 1) {
            $this->stderr("--days deve essere almeno 1\n");
            return ExitCode::USAGE;
        }

        if (!$this->maintenanceAvailable()) {
            return $this->purgeWithFallback($days);
        }

        $service = $this->createService();
        $report = $service->run($days, (bool) (int) $this->optimize, (int) $this->maxSeconds);

        $this->stdout($service->summary($report) . "\n");
        // Ottimizzazione rinviata, sotto soglia, senza lock o senza spazio: esito 0, si
        // riprova la notte dopo. Solo un errore vero (es. ALTER fallito) da' 1.
        return $report->isError() ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Pulizia semplice senza le classi di manutenzione: mai un fatal error per una
     * dipendenza mancante.
     */
    private function purgeWithFallback(int $days): int
    {
        LogHelper::log(
            'warning',
            'Classi di manutenzione di yii2-dev-helper non disponibili: pulizia semplice di commands_spool'
            . ((int) $this->optimize ? ' (--optimize ignorato)' : '') . ', senza ottimizzazione ne\' rimozione dei file di log',
            SpoolFallbackPurger::LOG_CATEGORY
        );
        $result = (new SpoolFallbackPurger())->purge($days, (int) $this->maxSeconds);
        SpoolFallbackPurger::markDone($this->fallbackMarkerFile());

        $summary = SpoolFallbackPurger::summary($result, 'pulizia semplice');
        LogHelper::log('info', $summary, SpoolFallbackPurger::LOG_CATEGORY);
        $this->stdout($summary . "\n");
        return ExitCode::OK;
    }

    /** True se ci sono le classi di manutenzione di yii2-dev-helper (sostituibile nei test). */
    protected function maintenanceAvailable(): bool
    {
        return SpoolFallbackPurger::maintenanceAvailable();
    }

    /** File marcatore della pulizia semplice giornaliera (sostituibile nei test). */
    protected function fallbackMarkerFile(): string
    {
        return Yii::getAlias(SpoolFallbackPurger::MARKER_FILE);
    }

    /** Servizio di retention (sostituibile nei test). */
    protected function createService(): SpoolRetentionService
    {
        return new SpoolRetentionService();
    }
}
