<?php

use yii\helpers\Html;


/* @var $this yii\web\View */
/* @var $model sharkom\cron\models\CommandsSpool */

$this->title = Yii::t('app', 'Nuovo record Commands Spool');
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Commands Spools'), 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="commands-spool-create box box-primary">
    <div class="box-header with-border">
        <?= Html::encode($this->title) ?>
    </div>
    <div class="box-body">

    <?= $this->render('_form', [
        'model' => $model,
    ]) ?>
    </div>
</div>
