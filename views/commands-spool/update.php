<?php

use yii\helpers\Html;

/* @var $this yii\web\View */
/* @var $model sharkom\cron\models\CommandsSpool */

$this->title = Yii::t('app', 'Aggiorna {modelClass}: ', [
    'modelClass' => 'Commands Spool',
]) . ' ' . $model->id;
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Commands Spools'), 'url' => ['index']];
$this->params['breadcrumbs'][] = ['label' => $model->id, 'url' => ['view', 'id' => $model->id]];
$this->params['breadcrumbs'][] = Yii::t('app', 'Aggiorna');
?>
<div class="commands-spool-update box box-primary">
    <div class="box-body">

    <?= $this->render('_form', [
        'model' => $model,
    ]) ?>
    </div>
</div>
