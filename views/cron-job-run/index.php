<?php

use kartik\grid\GridView;
use sharkom\cron\models\CronJob;
use sharkom\cron\models\CronJobRun;
use sharkom\cron\Module;
use yii\helpers\ArrayHelper;
use yii\helpers\Console;
use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var sharkom\cron\models\CronJobRunSearch $searchModel */
/** @var yii\data\ActiveDataProvider $dataProvider */

$this->title = Yii::t('vbt-cron', 'Cron Job Runs');
$this->params['breadcrumbs'][] = $this->title;
?>

<div class="cron-job-run-index">
    <div id="ajaxCrudDatatable">
        <?= GridView::widget([
            'dataProvider' => $dataProvider,
            'filterModel' => $searchModel,
            'pjax' => true,
            'bsVersion' => Module::getInstance()->bsVersion,
            'columns' => [
                ['class' => 'kartik\grid\SerialColumn'],
                [
                    'class' => '\kartik\grid\DataColumn',
                    'attribute' => 'job_id',
                    'vAlign' => 'middle',
                    'value' => function (CronJobRun $model) {
                        return $model->job->name;
                    },
                    'filter' => ArrayHelper::map(
                        CronJob::find()->all(),
                        'id',
                        'name'
                    )
                ],
                [
                    'class' => '\kartik\grid\DataColumn',
                    'attribute' => 'pid',
                    'vAlign' => 'middle',
                ],
                [
                    'class' => '\kartik\grid\DataColumn',
                    'attribute' => 'in_progress',
                    'format' => 'boolean',
                    'vAlign' => 'middle',
                    'filter' => [
                        0 => Yii::t('app', 'No'),
                        1 => Yii::t('app', 'Yes'),
                    ]
                ],
                [
                    'class' => '\kartik\grid\DataColumn',
                    'attribute' => 'start',
                    'vAlign' => 'middle',
                ],
                [
                    'class' => '\kartik\grid\DataColumn',
                    'attribute' => 'finish',
                    'vAlign' => 'middle',
                ],
                [
                    'class' => '\kartik\grid\DataColumn',
                    'attribute' => 'runtime',
                    'vAlign' => 'middle',
                ],
                [
                    'class' => '\kartik\grid\DataColumn',
                    'attribute' => 'exit_code',
                    'vAlign' => 'middle',
                ],
                [
                    'class' => '\kartik\grid\DataColumn',
                    'attribute' => 'output',
                    'value' => function (CronJobRun $model) {
                        return Html::tag('pre', Console::ansiToHtml($model->output), ['style' => 'width:100%; padding:15px; background-color:black; color:white; white-space: pre-wrap; white-space: -moz-pre-wrap; white-space: -pre-wrap; white-space: -o-pre-wrap; word-wrap: break-word;']);
                    },
                    'contentOptions'=>["style"=>"width:35%; vertical-align: top !important;"],
                    'format' => 'raw',
                    'hAlign' => 'left',
                    'vAlign' => 'middle',
                ],
                [
                    'class' => '\kartik\grid\DataColumn',
                    'attribute' => 'error_output',
                    'value' => function (CronJobRun $model) {
                        return Html::tag('pre', Console::ansiToHtml($model->error_output), ['style' => 'width:100%; padding:15px; background-color:black; color:white;  white-space: pre-wrap; white-space: -moz-pre-wrap; white-space: -pre-wrap; white-space: -o-pre-wrap; word-wrap: break-word;']);
                    },
                    'contentOptions'=>["style"=>"width:35%; vertical-align: top !important;"],
                    'format' => 'raw',
                    'hAlign' => 'left',
                    'vAlign' => 'middle',
                ],
            ],
            'toolbar' => [
                Html::a('<i class="glyphicon glyphicon-repeat"></i> ' . Yii::t('vbt-cron', 'Reset'), [''], [
                    'class' => 'btn btn-default',
                    'title' => Yii::t('vbt-cron', 'Reset'),
                ]),
            ],
            'panel' => [
                'type' => 'green',
                'heading' => '<i class="glyphicon glyphicon-list"></i> ' . $this->title,
            ]
        ]) ?>
    </div>
</div>
