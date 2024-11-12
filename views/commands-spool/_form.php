<?php

use yii\helpers\Html;
use yii\widgets\ActiveForm;

/* @var $this yii\web\View */
/* @var $model sharkom\cron\models\CommandsSpool */
/* @var $form yii\widgets\ActiveForm */

?>

<div class="commands-spool-form">

    <?php $form = ActiveForm::begin(); ?>

    <?= $form->errorSummary($model); ?>

    <?= $form->field($model, 'id', ['template' => '{input}'])->textInput(['style' => 'display:none']); ?>

    <?= $form->field($model, 'command')->textInput(['maxlength' => true, 'placeholder' => 'Command']) ?>

    <?= $form->field($model, 'provenience')->textInput(['maxlength' => true, 'placeholder' => 'Provenience']) ?>

    <?= $form->field($model, 'provenience_id')->textInput(['placeholder' => 'Provenience']) ?>

    <?= $form->field($model, 'logs')->textarea(['rows' => 6]) ?>

    <?= $form->field($model, 'errors')->textarea(['rows' => 6]) ?>

    <?= $form->field($model, 'logs_file')->textInput(['maxlength' => true, 'placeholder' => 'Logs File']) ?>

    <?= $form->field($model, 'executed_at')->widget(\kartik\datecontrol\DateControl::classname(), [
        'type' => \kartik\datecontrol\DateControl::FORMAT_DATETIME,
        'saveFormat' => 'php:Y-m-d H:i:s',
        'ajaxConversion' => true,
        'options' => [
            'pluginOptions' => [
                'placeholder' => Yii::t('app', 'Choose Executed At'),
                'autoclose' => true,
            ]
        ],
    ]); ?>

    <?= $form->field($model, 'completed_at')->widget(\kartik\datecontrol\DateControl::classname(), [
        'type' => \kartik\datecontrol\DateControl::FORMAT_DATETIME,
        'saveFormat' => 'php:Y-m-d H:i:s',
        'ajaxConversion' => true,
        'options' => [
            'pluginOptions' => [
                'placeholder' => Yii::t('app', 'Choose Completed At'),
                'autoclose' => true,
            ]
        ],
    ]); ?>

    <?= $form->field($model, 'completed')->textInput(['placeholder' => 'Completed']) ?>

    <?= $form->field($model, 'result')->textarea(['rows' => 6]) ?>


    <div class="form-group">
        <?= Html::submitButton($model->isNewRecord ? Yii::t('app', 'Salva') : Yii::t('app', 'Aggiorna'), ['class' => $model->isNewRecord ? 'btn btn-success' : 'btn btn-primary']) ?>
    </div>

    <?php ActiveForm::end(); ?>

</div>
