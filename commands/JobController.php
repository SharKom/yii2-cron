<?php


namespace sharkom\cron\commands;

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

}

