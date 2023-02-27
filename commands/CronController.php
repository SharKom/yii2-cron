<?php
/**
 * Created by PhpStorm.
 * User: Tamás
 * Date: 2019. 01. 10.
 * Time: 18:12
 */

namespace sharkom\cron\commands;

use Cron\CronExpression;
use sharkom\cron\models\CronJob;
use yii\console\Controller;
use yii\console\widgets\Table;
use yii\helpers\ArrayHelper;
use Yii;

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
                $this->run('/cron/job/run', [$job->id]);
            }
        }

        $this->purgeLogs();
    }

    private function unlock() {
        $conn=Yii::$app->db;
        $result=$conn->createCommand("select * from cron_job_run where in_progress=1 and start<(NOW() - INTERVAL 8 HOUR)")->queryAll();

        foreach ($result as $job) {
            $result=$conn->createCommand("update cron_job set last_id=null where id=$job[job_id]")->execute();
            $result=$conn->createCommand("delete from cron_job_run where id=$job[id]")->execute();
        }
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