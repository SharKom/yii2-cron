<?php

use sharkom\cron\models\CronJob;
use sharkom\cron\Module;
use kartik\grid\GridView;
use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var sharkom\cron\models\CronJobSearch $searchModel */
/** @var yii\data\ActiveDataProvider $dataProvider */

$this->title = Yii::t('vbt-cron', 'Cron Jobs');
$this->params['breadcrumbs'][] = $this->title;
?>

<div class="cron-job-index">
    <div id="ajaxCrudDatatable">
        <?php
        //se esiste il namespace ed è acccessibile \sharkom\devhelper\widgets\AdvancedFiltersBox
        if (class_exists('\sharkom\devhelper\widgets\AdvancedFiltersBox')) {

            $filters = [
                'route' => '/' . Yii::$app->controller->module->id . '/commands-spool/index',
                'box_title' => 'Filtri Avanzati',
                'filters' => [
                    [
                        'title' => 'Scelte rapide:',
                        'items' => [
                            [
                                'type' => 'link',
                                'text' => 'Mostra la coda di esecuzione',
                                'url' => ['/cron/commands-spool/index', 'CommandsSpoolSearch[history]' => 0],
                                'icon' => 'glyphicon glyphicon-road',
                            ],
                            [
                                'type' => 'link',
                                'text' => 'Mostra lo storico esecuzioni',
                                'url' => ['/cron/commands-spool/index', 'CommandsSpoolSearch[history]' => 1],
                                'icon' => 'glyphicon glyphicon-list',
                            ],
                        ],
                    ], [
                        'title' => '',
                        'items' => [

                        ],
                    ],
                    [
                        'title' => '',
                        'items' => [

                        ],
                    ], [
                        'title' => '',
                        'items' => [

                        ],
                    ],
                ]
            ];

            echo \sharkom\devhelper\widgets\AdvancedFiltersBox::widget($filters);
        }
        ?>

        <?= GridView::widget([
            'dataProvider' => $dataProvider,
            'filterModel' => $searchModel,
            'pjax' => true,
            'bsVersion' => Module::getInstance()->bsVersion,
            'columns' => [
                ['class' => 'kartik\grid\SerialColumn'],
                [
                    'class' => '\kartik\grid\DataColumn',
                    'attribute' => 'name',
                ],
                [
                    'class' => '\kartik\grid\DataColumn',
                    'attribute' => 'schedule',
                ],
                [
                    'class' => '\kartik\grid\DataColumn',
                    'attribute' => 'command',
                ],
                [
                    'class' => '\kartik\grid\DataColumn',
                    'attribute' => 'logfile',
                ],
                [
                    'class' => '\kartik\grid\DataColumn',
                    'attribute' => 'execution_time',
                ],
                [
                    'class' => '\kartik\grid\DataColumn',
                    'attribute' => 'last_execution',
                ],
                [
                    'attribute' => 'exit_code',
                    'format' => 'raw',
                    'value' => function ($model) {
                        return $model->exit_code == "OK" ? '<span class="label label-success">OK</span>' : '<span class="label label-danger">'.$model->exit_code.'</span>';
                    },
                ],
                [
                    'class' => '\kartik\grid\DataColumn',
                    'attribute' => 'active',
                    'format' => 'boolean',
                ],
                [
                    'class' => 'kartik\grid\ActionColumn',
                    'template' => '{update} {delete} {logs} {running} {run}',
                    'buttons' => [
                        'logs' => function ($url, CronJob $model) {
                            return Html::a('<i class="glyphicon glyphicon-list"></i>', ['/cron/commands-spool/index', 'CommandsSpoolSearch' => ['history' => 1, "provenience"=>"cron_job", "provenience_id"=>$model->id]], [
                                'title' => Yii::t('vbt-cron', 'Logs'),
                                'data-pjax' => 0,
                            ]);
                        },
                        'running' => function ($url, CronJob $model) {
                            return Html::a('<i class="glyphicon glyphicon-road"></i>', ['/cron/commands-spool/index', 'CommandsSpoolSearch' => ["provenience"=>"cron_job", "provenience_id"=>$model->id]], [
                                'title' => Yii::t('vbt-cron', 'Coda di esecuzione'),
                                'data-pjax' => 0,
                            ]);
                        },
                    ]
                ],
                [
                    'class' => 'kartik\grid\ActionColumn',
                    "header"=>Yii::t('vbt-cron', 'Execute'),
                    'template' => '{run}',
                    'buttons' => [

                        'run' => function ($url, CronJob $model) {
                            return Html::a('<i class="glyphicon glyphicon-play"></i>', ['run', 'id' => $model->id], [
                                'title' => Yii::t('vbt-cron', 'Execute'),
                                'data-pjax' => 0,
                            ]);
                        },
                    ]
                ],
            ],
            'toolbar' => [['content' =>
                Html::a('<i class="glyphicon glyphicon-plus"></i> ' . Yii::t('vbt-cron', 'Create Cron Job'), ['create'], [
                    'class' => 'btn btn-info',
                    'title' => Yii::t('vbt-cron', 'Create Cron Job'),
                    'data-pjax' => 0,
                ]) .
                Html::a('<i class="glyphicon glyphicon-repeat"></i> ' . Yii::t('vbt-cron', 'Reset'), [''], [
                    'class' => 'btn btn-default',
                    'title' => Yii::t('vbt-cron', 'Reset'),
                ])
            ]],
            'panel' => [
                'type' => 'success',
                'heading' => '<i class="glyphicon glyphicon-list"></i> ' . $this->title,
            ]
        ]) ?>
    </div>
</div>
