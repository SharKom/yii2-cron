<?php

use yii\helpers\Html;
use yii\widgets\ActiveForm;
use sharkom\widgets\CronJob;

/**@var $this yii\web\View */
/**@var $model sharkom\cron\models\CronJob */
/**@var $form yii\widgets\ActiveForm */
?>

<div class="cron-job-form">

    <?php $form = ActiveForm::begin(); ?>

    <?= $form->errorSummary($model); ?>

    <?= $form->field($model, 'name')->textInput(['maxlength' => true]) ?>

    <?=CronJob::widget([
        'model' => $model,
        'attribute'=>'schedule'
    ]); ?>

    <?= $form->field($model, 'command')->textInput(['maxlength' => true]) ?>

    <?= $form->field($model, 'logfile')->textInput(['maxlength' => true]) ?>

    <?= $form->field($model, 'max_execution_time')->textInput() ?>

    <?= $form->field($model, 'active')->checkbox() ?>

    <div class="form-group">
        <?= Html::submitButton(Yii::t('vbt-cron', 'Save'), ['class' => 'btn btn-success']) ?>
    </div>

    <?php ActiveForm::end(); ?>

</div>
