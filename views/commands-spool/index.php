<?php

/* @var $this yii\web\View */
/* @var $searchModel sharkom\cron\models\CommandsSpoolSearch */
/* @var $dataProvider yii\data\ActiveDataProvider */

use yii\helpers\Html;
use kartik\export\ExportMenu;
use kartik\grid\GridView;
use yii\helpers\Console;
use sharkom\cron\assets\LogViewerAsset;
use yii\helpers\Url;

$this->title = Yii::t('app', 'Commands Spools');
$this->params['breadcrumbs'][] = $this->title;
$search = "$('.search-button').click(function(){
	$('.search-form').toggle(1000);
	return false;
});";
$this->registerJs($search);

$actual = false;
$isHistoryRaw = isset($_GET['CommandsSpoolSearch']['history']) && $_GET['CommandsSpoolSearch']['history']!=0 ? $actual = true : $actual = false;

$provenience = isset($_GET['CommandsSpoolSearch']['provenience']) ? $_GET['CommandsSpoolSearch']['provenience'] : null;

$params=$_GET;

$this->title="Esecuzione comandi";

LogViewerAsset::register($this);

?>
<div class="commands-spool-index box box-primary">
    <div class="box-body">
    <?php
    //se esiste il namespace ed è acccessibile \sharkom\devhelper\widgets\AdvancedFiltersBox
    if (class_exists('\sharkom\devhelper\widgets\AdvancedFiltersBox')) {

        $filters = [
            'route' => "/".Yii::$app->controller->module->id . '/commands-spool/index',
            'box_title'=>"Filtri Avanzati",
            'filters' => [
                [
                    'title' => 'Scelte rapide:',
                    'items' => [
                        [
                            'type' => 'link',
                            'text' => 'Mostra la coda di esecuzione',
                            'url' => ['/cron/commands-spool/index', 'CommandsSpoolSearch[history]' => 0],
                            'icon'=>'glyphicon glyphicon-road',
                        ],
                        [
                            'type' => 'link',
                            'text' => 'Mostra lo storico esecuzioni',
                            'url' => ['/cron/commands-spool/index', 'CommandsSpoolSearch[history]' => 1],
                            'icon'=>'glyphicon glyphicon-list',
                        ],
                        [
                            'type' => 'link',
                            'text' => 'Torna ai cron job',
                            'url' => ['/cron/cron-job/index'],
                            'icon'=>'fa fa-clock-o',
                        ]
                    ],
                ], [
                    'title' => '',
                    'items' => [

                    ],
                ],
                [
                    'title' => '',
                    'items' => [
                        [
                            'type' => 'customDropdown',
                            'values'=>[
                                '0' => 'Coda di esecuzione',
                                '1' => 'Storico esecuzioni',
                                '2' => 'Tutti',
                            ],
                            'name' => 'CommandsSpoolSearch[history]',
                            'label' => 'Mostra:',
                            'selectedValue'=> isset($params['CommandsSpoolSearch']['history']) ? $params['CommandsSpoolSearch']['history'] : 0,
                        ],
                    ],
                ], [
                    'title' => '',
                    'items' => [
                        [
                            'type' => 'customDropdown',
                            'values'=>[
                                'cron_job' => 'Cron Job',
                                '' => 'Tutte le esecuzioni',
                            ],
                            'name' => 'CommandsSpoolSearch[provenience]',
                            'label' => 'Provenienza:',
                            'selectedValue'=> isset($params['CommandsSpoolSearch']['provenience']) ? $params['CommandsSpoolSearch']['provenience'] : 0,
                        ],
                    ],
                ],
            ]
        ];

        echo \sharkom\devhelper\widgets\AdvancedFiltersBox::widget($filters);
    }


$gridColumn = [];

if ($actual) {
    // Configurazione per griglia expandable
    $gridColumn[] = [
        'class' => 'kartik\grid\ExpandRowColumn',
        'width' => '50px',
        'value' => function ($model, $key, $index, $column) {
            return GridView::ROW_COLLAPSED;
        },
        'contentOptions' => function ($model, $key, $index, $grid) {
            return [
                'class' => !empty($model->logs) ? 'has-logs' : ''
            ];
        },
        'detail' => function ($model, $key, $index, $column) {
            $errorsContent = !empty($model->errors) ? Html::tag('pre', Console::ansiToHtml($model->errors, ["30"=> ['color' => 'gray']]), [
                'style' => 'width:100%; min-width:500px; padding:15px; background-color:#323232; color:white; white-space: pre-wrap; word-wrap: break-word;'
            ]) : Yii::t('app', 'Nessun errore');

            $logsTabVisible = !empty($model->logs);
            $errorsTabVisible = !empty($model->errors);

            if (!$logsTabVisible && !$errorsTabVisible) {
                return Yii::t('app', 'Nessun dettaglio disponibile');
            }

            $uniqueId = 'tabs-' . $model->id;
            $logsTabId = "{$uniqueId}-logs";

            $html = Html::beginTag('div', ['class' => 'nav-tabs-custom']);
            $html .= Html::beginTag('ul', ['class' => 'nav nav-tabs']);

            if ($errorsTabVisible) {
                $html .= Html::tag('li', Html::a(Yii::t('app', '<b>Errori</b>'), "#{$uniqueId}-errors", ['data-toggle' => 'tab', 'class' => 'active']));
            }
            if ($logsTabVisible) {
                $html .= Html::tag('li', Html::a(
                    Yii::t('app', '<b>Logs</b>'),
                    "#{$logsTabId}",
                    [
                        'data-toggle' => 'tab',
                        'class' => !$errorsTabVisible ? 'active' : ''
                    ]
                ));
            }

            $html .= Html::endTag('ul');
            $html .= Html::beginTag('div', ['class' => 'tab-content']);

            if ($errorsTabVisible) {
                $html .= Html::tag('div', $errorsContent, ['class' => 'tab-pane active', 'id' => "{$uniqueId}-errors"]);
            }
            if ($logsTabVisible) {
                $html .= Html::tag('div',
                    '<div class="loading-indicator">Caricamento logs...</div>',
                    ['class' => 'tab-pane ' . (!$errorsTabVisible ? 'active' : ''), 'id' => $logsTabId]
                );
            }

            $html .= Html::endTag('div');
            $html .= Html::endTag('div');

            return $html;
        },
        'headerOptions' => ['class' => 'kartik-sheet-style'],
        'expandOneOnly' => true
    ];
} else {
    // Configurazione per griglia classica
    $gridColumn[] = [
        'class' => 'kartik\grid\SerialColumn',

    ];
}

if(!$provenience){
    $gridColumn[] = [
        'attribute' => 'provenience',
        'label' => 'Provenienza',
        'format' => 'raw',
        'value' => function ($model) {
            if (!empty($model->provenience)) {
                return "<span class=\"label label-default\">{$model->provenience}</span>";
            }
            return '<span class="label label-default">N/A</span>';
        },
        'contentOptions' => ['style' => 'text-align: center; vertical-align: middle;'],
    ];
}

// Ora facciamo il merge con le colonne comuni
$gridColumn = array_merge($gridColumn, [
    ['attribute' => 'id', 'visible' => false],
    [
        'attribute' => 'completed',
        'format' => 'raw',
        'value' => function ($model) {
            switch ($model->completed) {
                case '-1':
                    return '<span class="label label-danger">Esecuzione incompleta</span>';
                case '1':
                    return '<span class="label label-primary">Eseguito</span>';
                default:
                    if (!empty($model->executed_at) && empty($model->completed_at)) {
                        return '<span class="label label-warning">In esecuzione</span>';
                    } else {
                        return '<span class="label label-default">In coda</span>';
                    }
            }
        },
        //aggiungi tendina filtro
        'filter' => [
            '0' => 'In coda/In esecuzione',
            '1' => 'Eseguito',
            '-1' => 'Esecuzione incompleta'
        ],
        'contentOptions' => ['style' => 'text-align: center; vertical-align: middle;'],
    ],
    [
        'attribute' => 'command',
        'format' => 'raw',
        'value' => function ($model) {
            if (!empty($model->provenience) && !empty($model->provenience_id)) {
                $db = Yii::$app->db;
                $cron = $db->createCommand("SELECT * FROM {$model->provenience} WHERE id = {$model->provenience_id}")->queryOne();
                if (!empty($cron)) {
                    return Html::a(
                        $cron["name"],
                        ['cron-job/update', 'id' => $model->provenience_id],
                        [
                            'title' => $model->command,
                            'data-toggle' => 'tooltip',
                            'data-placement' => 'top',
                            'data-html' => 'true',
                        ]
                    );
                }
            }
            return $model->command;
        },
        'contentOptions' => ['style' => 'text-align: left; vertical-align: middle;'],
    ],
    [
        'attribute' => 'result',
        'format' => 'raw',
        'value' => function ($model) {
            if (!empty($model->result)) {

                switch ($model->result) {
                    case 'success':
                        return '<span class="label label-success"> OK </span>';
                    case 'error':
                        return '<span class="label label-danger">Errore</span>';
                    case 'fatal':
                        return '<span class="label bg-black">Fatal</span>';

                }

            }
        },
        //add select filter with success, error and fatal
        'filter' => [
            'success' => 'OK',
            'error' => 'Errore',
            'fatal' => 'Fatal'
        ],
        'contentOptions' => ['style' => 'text-align: center; vertical-align: middle;'],
    ],
    [
        'attribute' => 'executed_at',
        'label' => 'Inizio esecuzione',
        'format' => 'raw',
        'value' => function ($model) {
            if (!empty($model->executed_at)) {
                return date('d/m/Y H:i:s', strtotime($model->executed_at));
            }
            return '<span class="label label-default">In coda</span>';
        },
        'contentOptions' => ['style' => 'text-align: center; vertical-align: middle;'],
    ],
    [
        'attribute' => 'completed_at',
        'label' => 'Durata',
        'format' => 'raw',
        'value' => function ($model) {
            if (!empty($model->completed_at)) {
                $start = new DateTime($model->executed_at);
                $end = new DateTime($model->completed_at);
                $interval = $start->diff($end);
                return $interval->format('%H:%I:%S');
            }
        },
        'contentOptions' => ['style' => 'text-align: center; vertical-align: middle;'],
    ],
    [
        "attribute" => 'logs_file',
        "format" => "raw",
        "value" => function ($model) {
            if ($model->completed != 0 && !empty($model->logs)) {
                return Html::a(
                    '<i class="fas fa-download"></i> Scarica log',
                    Url::to(["download-logs", "id" => $model->id]),
                    [
                        'class' => 'btn btn-sm btn-default',
                        'title' => 'Scarica il file di log',
                        'data-pjax' => '0',
                        'target' => '_blank'
                    ]
                );
            }

            if($model->completed==0 && !empty($model->executed_at) && empty($model->complted_at)) {
                return Html::button('Visualizza Log', [
                    'class' => 'btn btn-primary',
                    'onclick' => 'showLogViewer("'.$model->logs_file.'")'
                ]);
            }

            return '<span class="text-muted">-</span>';
        },
        'contentOptions' => ['style' => 'text-align: center; vertical-align: middle;'],
    ]
]);

/*        [
            'class' => 'kartik\grid\ActionColumn',
            'deleteOptions' => [
        'data-confirm' => "Sei sicuro di voler eliminare questo record? <br><b>ATTENZIONE: Azione Irreversibile!</b>",
            ],
            'template' => '{update} {delete}',
        ],*/

    ?>
    <?= GridView::widget([
        'dataProvider' => $dataProvider,
        'filterModel' => $searchModel,
        'columns' => $gridColumn,
        'layout' => "{items}\n{summary}\n{pager}",
        'pjax' => true,
        'pjaxSettings' => ['options' => ['id' => 'kv-pjax-container-commands-spool']],
        'responsive' => false,
        'options' => [
            'style'=>'word-wrap: break-word;'
        ],
        //'panel' => [
        //    'type' => GridView::TYPE_PRIMARY,
        //    'heading' => '<span class="glyphicon glyphicon-book"></span>  ' . Html::encode($this->title),
        //],
        'krajeeDialogSettings' => [
            'options' => [
                'title' => '<div style="color:black;">Richiesta Conferma Azione</div>',
                'type' => "modal-default",
            ],
        ],
        // your toolbar can include the additional full export menu
        'toolbar' => [
            '{export}',
            ExportMenu::widget([
                    'dataProvider' => $dataProvider,
                    'columns' => $gridColumn,
                    'columnSelectorOptions'=>[
                    'label' => 'Seleziona colonne',
                ],
                'target' => ExportMenu::TARGET_BLANK,
                'fontAwesome' => true,
                'dropdownOptions' => [
                    'label' => 'Esporta',
                    'class' => 'btn btn-default',
                    'itemsBefore' => [
                    '<li class="dropdown-header">Esporta</li>',
                ],
            ],
            'messages'=>[
                'allowPopups' => 'Si prega di disabilitare il blocco popup del browser per poter scaricare il file correttamente',
                'confirmDownload'=>'Avviare il Download?',
                'downloadProgress'=> 'Generazione file in corso... Si prega di attendere',
                'downloadProgress'=>'Generazione completata con successo',
            ],
            'exportConfig' => [
                ExportMenu::FORMAT_TEXT => false,
                ExportMenu::FORMAT_HTML => false,
                ExportMenu::FORMAT_EXCEL => false,
                ExportMenu::FORMAT_CSV => [
                    'alertMsg' => 'Il file CSV sarà generato per il download',
                ],
                ExportMenu::FORMAT_PDF => [
                    'alertMsg' => 'Il file PDF sarà generato per il download',
                ],
                ExportMenu::FORMAT_EXCEL_X => [
                    'alertMsg' => 'Il file EXCEL sarà generato per il download',
                ],
            ]
            ]) ,
        ],
    ]); ?>
    </div>
</div>
<?php
// Renderizza la modale
echo $this->render('_log_modal');