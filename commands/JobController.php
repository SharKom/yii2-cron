<?php


namespace sharkom\cron\commands;

use sharkom\cron\helpers\CronScheduleHelper;
use sharkom\cron\models\CronJob;
use yii\console\Controller;
use yii\helpers\Console;

/**
 * Class JobController
 * @package sharkom\cron\commands
 */
class JobController extends Controller
{
    /**
     * @var bool skip confirm prompt (--yes / -y)
     */
    public $yes = false;

    public function options($actionID)
    {
        $opts = parent::options($actionID);
        if (in_array($actionID, ['disable-all', 'enable-all'], true)) {
            $opts[] = 'yes';
        }
        return $opts;
    }

    public function optionAliases()
    {
        return array_merge(parent::optionAliases(), ['y' => 'yes']);
    }

    /**
     * @param $id
     */
    public function actionRun($id)
    {
        $job = CronJob::findOne($id);
        $run = $job->run();

        echo "[" . date('Y-m-d H:i:s') . "] ". Console::ansiFormat("[info]", [Console::FG_GREEN]) . " - Process with id $id is finished, exit code: #" . $run->exit_code. PHP_EOL;
        if($job->logfile!="") {
            echo "[" . date('Y-m-d H:i:s') . "] ". Console::ansiFormat("[info]", [Console::FG_GREEN]) . " - Log file: " . $job->logfile. PHP_EOL;
        }

        //Console::output($run->output);

        if($job->logfile!="") {
            error_log($run->output, 3, $job->logfile);
        }

        if (!empty($run->error_output)) {
            if($job->logfile!="") {
                error_log($run->error_output, 3, $job->logfile);
            }
            Console::output($run->error_output);
        }
    }

    /**
     * Disattiva tutti i cron job attivi (imposta active=0 su cron_job).
     * Uso: ./yii cron/job/disable-all [--yes]
     * Il flag --yes (o -y) salta la conferma interattiva.
     */
    public function actionDisableAll()
    {
        $count = (int) CronJob::find()->where(['active' => true])->count();
        if ($count === 0) {
            Console::output(Console::ansiFormat('Nessun cron job attivo da disattivare.', [Console::FG_YELLOW]));
            return self::EXIT_CODE_NORMAL;
        }

        if (!$this->yes && !$this->confirm("Disattivare $count cron job attivi?")) {
            Console::output('Annullato.');
            return self::EXIT_CODE_NORMAL;
        }

        $affected = CronJob::updateAll(['active' => false], ['active' => true]);
        CronScheduleHelper::invalidateAll();

        Console::output(
            Console::ansiFormat("[OK]", [Console::FG_GREEN])
            . " Disattivati $affected cron job. Cache CronScheduleHelper invalidata."
        );
        return self::EXIT_CODE_NORMAL;
    }

    /**
     * Riattiva tutti i cron job (imposta active=1 su cron_job).
     * Uso: ./yii cron/job/enable-all [--yes]
     */
    public function actionEnableAll()
    {
        $count = (int) CronJob::find()->where(['active' => false])->count();
        if ($count === 0) {
            Console::output(Console::ansiFormat('Nessun cron job inattivo da riattivare.', [Console::FG_YELLOW]));
            return self::EXIT_CODE_NORMAL;
        }

        if (!$this->yes && !$this->confirm("Riattivare $count cron job inattivi?")) {
            Console::output('Annullato.');
            return self::EXIT_CODE_NORMAL;
        }

        $affected = CronJob::updateAll(['active' => true], ['active' => false]);
        CronScheduleHelper::invalidateAll();

        Console::output(
            Console::ansiFormat("[OK]", [Console::FG_GREEN])
            . " Riattivati $affected cron job. Cache CronScheduleHelper invalidata."
        );
        return self::EXIT_CODE_NORMAL;
    }

    /**
     * Mostra il conteggio dei cron job per stato (attivi/inattivi/totale).
     * Uso: ./yii cron/job/status
     */
    public function actionStatus()
    {
        $total = (int) CronJob::find()->count();
        $active = (int) CronJob::find()->where(['active' => true])->count();
        $inactive = $total - $active;

        Console::output('Cron jobs:');
        Console::output('  Totale:   ' . $total);
        Console::output('  Attivi:   ' . Console::ansiFormat((string)$active, [Console::FG_GREEN]));
        Console::output('  Inattivi: ' . Console::ansiFormat((string)$inactive, [Console::FG_YELLOW]));
        return self::EXIT_CODE_NORMAL;
    }
}

