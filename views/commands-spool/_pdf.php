<?php

use yii\helpers\Html;
use yii\widgets\DetailView;
use kartik\grid\GridView;

/* @var $this yii\web\View */
/* @var $model sharkom\cron\models\CommandsSpool */

$this->title = $model->id;
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Commands Spools'), 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="commands-spool-view">

    <div class="row">
        <div class="col-sm-9">
            <h2><?= Yii::t('app', 'Commands Spool').' '. Html::encode($this->title) ?></h2>
        </div>
    </div>

    <div class="row">
<?php 
    $gridColumn = [
        ['attribute' => 'id', 'visible' => false],
        'command',
        'provenience',
        'provenience_id',
        'logs:ntext',
        'errors:ntext',
        'logs_file',
        'executed_at',
        'completed_at',
        'completed',
        'result:ntext',
    ];
    echo DetailView::widget([
        'model' => $model,
        'attributes' => $gridColumn
    ]); 
?>
    </div>
</div>
