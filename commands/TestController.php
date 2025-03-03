<?php

namespace sharkom\cron\commands;

use sharkom\devhelper\LogHelper;
use Cron\CronExpression;
use sharkom\cron\models\CronJob;
use yii\console\Controller;
use yii\console\widgets\Table;
use yii\helpers\ArrayHelper;
use sharkom\devhelper\DallEHelper;
use Yii;


class TestController extends Controller
{
    public function actionIndex(){
        $n=180;
        while ($n) {
            LogHelper::log("info", "Questo è un log di test");
            sleep(1);
        }

        LogHelper::log("error", "Questo è un log di test");
        throw new \Exception("Errore di esecuzione");
    }

    public function actionTest(){

        if (function_exists('pcntl_fork')) {
            echo "La funzione pcntl_fork() è supportata e abilitata nel tuo ambiente PHP.\n";
        } else {
            echo "La funzione pcntl_fork() NON è supportata o abilitata nel tuo ambiente PHP.\n";
        }

    }


}