<?php
/**
 * Created by PhpStorm.
 * User: Tamás
 * Date: 2019. 01. 10.
 * Time: 18:12
 */

namespace sharkom\cron\commands;

use Cron\CronExpression;
use http\Params;
use sharkom\cron\models\CronJob;
use sharkom\cron\services\SpoolFallbackPurger;
use sharkom\devhelper\LogHelper;
use yii\console\Controller;
use yii\console\widgets\Table;
use yii\helpers\ArrayHelper;
use yii\helpers\Console;
use Yii;

date_default_timezone_set('europe/rome');

/**
 * Class JobController
 * @package console\controllers
 */
class CronController extends Controller
{
    /**
     * @var string The controller default action
     */
    public $defaultAction = 'jobs';

    /**
     * Tempo massimo della pulizia semplice chiamata da cron/cron/run (solo senza le classi
     * di manutenzione): breve, il run gira ogni minuto. Il resto il giorno dopo.
     */
    public const FALLBACK_MAX_SECONDS = 60;

    /**
     * List all jobs
     * @throws \Exception
     */
    public function actionJobs()
    {
        $jobs = CronJob::find()
            ->where(['active' => true])
            ->all();

        echo PHP_EOL;
        echo Table::widget([
            'headers' => ['ID', 'Name', 'Schedule', 'Command', 'Log File', 'Max execution time', 'Active'],
            'rows' => ArrayHelper::getColumn($jobs, function (CronJob $job) {
                return [
                    $job->id,
                    $job->name,
                    $job->schedule,
                    $job->command,
                    $job->logfile,
                    $job->max_execution_time,
                    $job->active ? true : false,
                ];
            })
        ]);
    }

    /**
     * Run cron jobs
     */
    public function actionRun()
    {
        //$this->unlock();
        // La pulizia di commands_spool non e' piu' qui (era una DELETE senza LIMIT ogni
        // minuto che cancellava anche le righe pendenti): vedi cron/maintenance/purge-spool.
        // Solo senza le classi di manutenzione: purgeSpoolFallback() a fine run.

        foreach (CronJob::findRunnable() as $job) {

            //LogHelper::log('info', $job);

            if (CronExpression::factory($job->schedule)->isDue()) {
                //echo "[" . date('Y-m-d H:i:s') . "] ". Console::ansiFormat("[info]", [Console::FG_GREEN]) . " - ".$job->id." - ".$job->name." - isDue". PHP_EOL;
                //$this->run('/cron/job/run', [$job->id]);
                $conn = Yii::$app->db;
                $exists = $conn->createCommand("select * from commands_spool where command=:command and completed=0 and executed_at is null", [':command' => $job->command])->queryOne();
                if (!$exists) {
                    $conn->createCommand()->insert('commands_spool', [
                        'command' => $job->command,
                        'provenience' => 'cron_job',
                        'provenience_id' => $job->id,
                        'logs_file' => $job->logfile,
                        'created_at' => date('Y-m-d H:i:s')
                    ])->execute();
                }
            }
        }

        $this->purgeSpoolFallback();
    }

    /**
     * Solo sui core senza le classi di manutenzione di yii2-dev-helper (NormaFlow), dove
     * cron/maintenance/purge-spool puo' non essere programmato: pulizia semplice di
     * commands_spool al massimo una volta al giorno, dopo aver accodato i job.
     * Dove le classi ci sono (SoundOrgan) non fa nulla: ci pensa il job notturno.
     */
    private function purgeSpoolFallback(): void
    {
        if ($this->maintenanceAvailable()) {
            return;
        }
        [$days, $warning] = SpoolFallbackPurger::resolveDays($this->module->params);
        if ($days < 1) {
            LogHelper::log('warning', "commands_spool: giorni di retention non validi ({$days}), pulizia saltata", SpoolFallbackPurger::LOG_CATEGORY);
            return;
        }
        try {
            $result = $this->createFallbackPurger()->purgeOncePerDay($this->fallbackMarkerFile(), $days, self::FALLBACK_MAX_SECONDS);
        } catch (\Throwable $e) {
            LogHelper::log('error', 'commands_spool: pulizia semplice non riuscita: ' . $e->getMessage(), SpoolFallbackPurger::LOG_CATEGORY);
            return;
        }
        if ($result === null) {
            return;
        }
        if ($warning !== null) {
            LogHelper::log('warning', $warning, SpoolFallbackPurger::LOG_CATEGORY);
        }
        LogHelper::log(
            'info',
            SpoolFallbackPurger::summary($result, 'pulizia semplice giornaliera'),
            SpoolFallbackPurger::LOG_CATEGORY
        );
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

    /** Pulizia semplice (sostituibile nei test). */
    protected function createFallbackPurger(): SpoolFallbackPurger
    {
        return new SpoolFallbackPurger();
    }


    private function unlock()
    {
        $conn = Yii::$app->db;

        $moduleID = $moduleID ?? Yii::$app->controller->module->id;
        $module = Yii::$app->getModule($moduleID);

        $result = $conn->createCommand('select * from cron_job_run where in_progress=1 and start<(NOW() - INTERVAL 1 HOUR)')->queryAll();
        $cronJobData = [];
        foreach ($result as $job) {

            $cronJobName = $conn->createCommand("SELECT name FROM cron_job WHERE id=:id", [':id' => (int)$job['job_id']])->queryScalar();
            $cronJobData[] = [
                'name' => $cronJobName,
                'start' => $job['start'],
                'finish' => $job['finish'],
                'runtime' => $job['runtime'],
                'error_output' => $job['error_output'],
            ];
            $result = $conn->createCommand("update cron_job set last_id=null where id=:id", [':id' => (int)$job['job_id']])->execute();

            $result = $conn->createCommand("delete from cron_job_run where id=:id", [':id' => (int)$job['id']])->execute();

            /*
            if ($module->has('customNotification')) {
                $notificationComponent = $module->get('customNotification');
                $title="Notifica sblocco CronJob $cronJobName";
                $description= 'CronJob: ' . $cronJobName . ', Inzio: ' . $job['start'] . ', Fine: ' . $job['finish'] . ', Tempo di esecuzione: ' . $job['runtime'] . ' secondi' .  "\n" ;
                $notificationComponent->send($title, $description, "cron-jobs", "error", $job['error_output']);
                sleep(1);
            } else {
                // Gestisci l'errore in un altro modo o ignoralo se il componente non è impostato
                // Ad esempio, puoi scrivere un messaggio nel log dell'applicazione
                //Yii::warning('Il componente di notifica personalizzato non è impostato nel modulo corrente.');
            }
            //Qui raccogliere i dati dei job incagliati individuati
            */
        }

        /*
        if (!empty($cronJobData)) {
            if($module->params["sendNotifications"]===true) {
                $this->sendUlockNotify($cronJobData);
            }
        }
        */

        //Qui mandare una mail di notifica
    }

    private function sendUlockNotify($cronJobData)
    {
        $subject = 'Notifica sblocco CronJob';

        $body = 'I seguenti cronjob sono stati sbloccati:' . "\n";
        foreach ($cronJobData as $jobData) {
            $body .= 'CronJob: ' . $jobData['name'] . ', Inzio: ' . $jobData['start'] . ', Fine: ' . $jobData['finish'] . ', Tempo di esecuzione: ' . $jobData['runtime'] . ' secondi' . ', Output errore: ' . $jobData['error_output'] . "\n";
        }
        Yii::$app->mailer->compose()
            ->setTo(Yii::$app->params['NotificationsEmail'])
            ->setFrom([Yii::$app->params['senderEmail'] => Yii::$app->name])
            ->setSubject($subject)
            ->setTextBody($body)
            ->send();
    }
}