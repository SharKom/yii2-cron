<?php
/**
 * Created by Model Generator.
 */

namespace sharkom\cron\models;

use common\helpers\FileHelper;
use sharkom\devhelper\LogHelper;
use Symfony\Component\Process\Process;
use sharkom\cron\Module;
use Yii;
use yii\db\ActiveQuery;
use yii\db\ActiveQueryInterface;
use yii\db\ActiveRecord;
use yii\db\Expression;

/**
 * This is the model class for table "cron_job".
 *
 * @property int $id
 * @property int $last_id
 * @property string $name
 * @property string $schedule
 * @property string $command
 * @property int $execution_time
 * @property boolean $active
 *
 * @property CronJobRun[] $cronJobRuns
 * @property CronJobRun $last
 */
class CronJob extends ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'cron_job';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['last_id', 'execution_time'], 'integer'],
            [['active'], 'boolean'],
            [['last_execution'], 'safe'],
            [['name', 'schedule', 'command', 'logfile', 'exit_code'], 'string', 'max' => 255],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => Yii::t('vbt-cron', 'ID'),
            'last_id' => Yii::t('vbt-cron', 'Last'),
            'name' => Yii::t('vbt-cron', 'Name'),
            'schedule' => Yii::t('vbt-cron', 'Schedule'),
            'command' => Yii::t('vbt-cron', 'Command'),
            'logfile' => Yii::t('vbt-cron', 'Log File'),
            'execution_time' => Yii::t('vbt-cron', 'Tempo di esecuzione'),
            'active' => Yii::t('vbt-cron', 'Active'),
            'last_execution' => Yii::t('vbt-cron', 'Ultima esecuzione'),
        ];
    }

    /**
     * @return ActiveQuery|CronJobQuery|ActiveQueryInterface
     */
    public function getCronJobRuns()
    {
        return $this->hasMany(CronJobRun::class, ['job_id' => 'id']);
    }

    /**
     * @return ActiveQuery|CronJobQuery|ActiveQueryInterface
     */
    public function getLast()
    {
        return $this->hasOne(CronJobRun::class, ['id' => 'last_id']);
    }

    /**
     * {@inheritdoc}
     * @return CronJobQuery the active query used by this AR class.
     */
    public static function find()
    {
        return new CronJobQuery(get_called_class());
    }

    /**
     * @return array|CronJob[]
     */
    public static function findRunnable()
    {
        return static::find()
            ->leftJoin('cron_job_run', 'cron_job_run.id = cron_job.last_id')
            ->where(['AND',
                ['active' => 1],
                ['OR',
                    ['cron_job_run.id' => null],
                    ['cron_job_run.in_progress' => 0],
                ],
            ])
            ->all();
    }

    /**
     * Run cron job
     *
     * @return CronJobRun
     */
    public function run()
    {
        LogHelper::log("info", "CronJob::run()");
        $moduleID = $moduleID ?? Yii::$app->controller->module->id;
        $module = Yii::$app->getModule($moduleID);

        $process = $this->buildProcess($this->command, $this->execution_time ?? 60);
        $process->start();

        $start = microtime(true);

        $run = new CronJobRun();
        $run->job_id = $this->id;
        $run->start = date('Y-m-d H:i:s');
        $run->pid = (string)$process->getPid();
        $run->in_progress = true;
        $run->save(false);
        $this->last_id = $run->id;
        $this->save();

        $run->exit_code = $process->wait();
        $run->runtime = microtime(true) - $start;
        $run->finish = date('Y-m-d H:i:s');
        $run->output = $process->getOutput();
        $run->error_output = $process->getErrorOutput();
        $run->in_progress = false;
        $run->save(true);

        if ($run->exit_code != 0) {
            if($module->params["sendNotifications"]===true) {
                $this->sendErrorEmail($this->command, $run->error_output, $run->output);
            }

            if ($module->has('customNotification')) {
                $notificationComponent = $module->get('customNotification');
                $title="Errore esecuzione cronjob $this->command";
                $description="Si è presentato un errore durante l'esecuzione del cronjob $this->command il ".date("d/m/Y")." alle ".date("H:i:s");

                $notificationComponent->send($title, $description, "cron-jobs", "error", "$this->command \n\n$run->error_output \n\n$run->output");
            } else {
                // Gestisci l'errore in un altro modo o ignoralo se il componente non è impostato
                // Ad esempio, puoi scrivere un messaggio nel log dell'applicazione
                //Yii::warning('Il componente di notifica personalizzato non è impostato nel modulo corrente.');
            }
        }

        return $run;
        LogHelper::log("info", "CronJob::run() - END");
    }


    private function sendErrorEmail($command, $errorOutput, $executionOutput)
    {
        Yii::$app->mailer->compose()
            ->setTo(Yii::$app->params["NotificationsEmail"])
            ->setFrom([Yii::$app->params['senderEmail'] => Yii::$app->name])
            ->setSubject('Errore di esecuzione del Cron Job '.$command)
            ->setTextBody("Si è verificato un errore durante l'esecuzione del comando: $command\n\nDettagli dell'errore:\n$errorOutput\n\nLog di esecuzione:\n$executionOutput")
            ->send();
    }


    /**
     * @param string $command
     * @param int $timeout
     * @return Process
     */
    public function buildProcess($command, $timeout = 60)
    {
        $command = $this->buildCommand($command);
        //print_r($command);
        $process = new Process($command, null, null, null, $timeout);
        return $process;
    }

    /**
     * Build cron job command
     *
     * @return string
     */
    public function buildCommand($command)
    {
        $module = Module::getInstance();

        return explode(" ", $command);
    }
}
