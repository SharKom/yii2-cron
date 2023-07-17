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
use yii\console\Controller;
use yii\console\widgets\Table;
use yii\helpers\ArrayHelper;
use yii\helpers\Console;
use Yii;

date_default_timezone_set("europe/rome");

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
        $this->unlock();

        foreach (CronJob::findRunnable() as $job) {

            if (CronExpression::factory($job->schedule)->isDue()) {
                echo "[" . date('Y-m-d H:i:s') . "] ". Console::ansiFormat("[info]", [Console::FG_GREEN]) . " - ".$job->id." - ".$job->name." - isDue". PHP_EOL;
                $this->run('/cron/job/run', [$job->id]);
            }
        }

        $this->purgeLogs();
    }



    private function unlock() {
        $conn=Yii::$app->db;

        $moduleID = $moduleID ?? Yii::$app->controller->module->id;
        $module = Yii::$app->getModule($moduleID);

        $result=$conn->createCommand("select * from cron_job_run where in_progress=1 and start<(NOW() - INTERVAL 1 HOUR)")->queryAll();
        $cronJobData = [];
        foreach ($result as $job) {

            $cronJobName = $conn->createCommand("SELECT name FROM cron_job WHERE id=$job[job_id]")->queryScalar();
            $cronJobData[] = [
                'name' => $cronJobName,
                'start' => $job['start'],
                'finish' => $job['finish'],
                'runtime' =>$job['runtime'] ,
                'error_output' => $job['error_output'],
            ];
            $result=$conn->createCommand("update cron_job set last_id=null where id=$job[job_id]")->execute();

            $result=$conn->createCommand("delete from cron_job_run where id=$job[id]")->execute();

            if ($module->has('customNotification')) {
                $notificationComponent = $module->get('customNotification');
                $title="Notifica sblocco CronJob $cronJobName";
                $description= 'CronJob: ' . $cronJobName . ', Inzio: ' . $job['start'] . ', Fine: ' . $job['finish'] . ', Tempo di esecuzione: ' . $job['runtime'] . ' secondi' .  "\n" ;
                $notificationComponent->send($title, $description, "cron-jobs", "error");
                sleep(1);
            } else {
                // Gestisci l'errore in un altro modo o ignoralo se il componente non è impostato
                // Ad esempio, puoi scrivere un messaggio nel log dell'applicazione
                //Yii::warning('Il componente di notifica personalizzato non è impostato nel modulo corrente.');
            }
            //Qui raccogliere i dati dei job incagliati individuati
        }

        if (!empty($cronJobData)) {

            if($module->params["sendNotifications"]===true) {
                $this->sendUlockNotify($cronJobData);
            }


        }

        //Qui mandare una mail di notifica
    }
    private function sendUlockNotify($cronJobData){
        $subject = 'Notifica sblocco CronJob';

        $body = 'I seguenti cronjob sono stati sbloccati:'  . "\n";
        foreach ($cronJobData as $jobData) {
            $body .= 'CronJob: ' . $jobData['name'] . ', Inzio: ' . $jobData['start'] . ', Fine: ' . $jobData['finish'] . ', Tempo di esecuzione: ' . $jobData['runtime'] . ' secondi' . ', Output errore: ' . $jobData['error_output'] .  "\n" ;
        }
        Yii::$app->mailer->compose()
            ->setTo(Yii::$app->params["NotificationsEmail"])
            ->setFrom([Yii::$app->params['senderEmail'] => Yii::$app->name])
            ->setSubject($subject)
            ->setTextBody($body)
            ->send();
    }

    private function purgeLogs(){
        $conn=Yii::$app->db;
        $module = Yii::$app->getModule(Yii::$app->controller->module->id);

        if(isset($module->params["purge_log_interval"])) {
            $months=$module->params["purge_log_interval"];
        } else {
            $months=3;
        }

        $conn->createCommand("delete from cron_job_run where start<(NOW() - INTERVAL $months MONTH)")->execute();
    }
}