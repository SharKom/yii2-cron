<?php

use yii\helpers\Html;
use yii\widgets\ActiveForm;

/* @var $this yii\web\View */
/* @var $model sharkom\cron\models\CommandsSpoolSearch */
/* @var $form yii\widgets\ActiveForm */
?>

<div class="form-commands-spool-search">

    <?php $form = ActiveForm::begin([
        'action' => ['index'],
        'method' => 'get',
    ]); ?>

    <?= $form->field($model, 'id', ['template' => '{input}'])->textInput(['style' => 'display:none']); ?>

    <?= $form->field($model, 'command')->textInput(['maxlength' => true, 'placeholder' => 'Command']) ?>

    <?= $form->field($model, 'provenience')->textInput(['maxlength' => true, 'placeholder' => 'Provenience']) ?>

    <?= $form->field($model, 'provenience_id')->textInput(['placeholder' => 'Provenience']) ?>

    <?= $form->field($model, 'logs')->textarea(['rows' => 6]) ?>

    <?php /* echo $form->field($model, 'errors')->textarea(['rows' => 6]) */ ?>

    <?php /* echo $form->field($model, 'logs_file')->textInput(['maxlength' => true, 'placeholder' => 'Logs File']) */ ?>

    <?php /* echo $form->field($model, 'executed_at')->widget(\kartik\datecontrol\DateControl::classname(), [
        'type' => \kartik\datecontrol\DateControl::FORMAT_DATETIME,
        'saveFormat' => 'php:Y-m-d H:i:s',
        'ajaxConversion' => true,
        'options' => [
            'pluginOptions' => [
                'placeholder' => Yii::t('app', 'Choose Executed At'),
                'autoclose' => true,
            ]
        ],
    ]); */ ?>

    <?php /* echo $form->field($model, 'completed_at')->widget(\kartik\datecontrol\DateControl::classname(), [
        'type' => \kartik\datecontrol\DateControl::FORMAT_DATETIME,
        'saveFormat' => 'php:Y-m-d H:i:s',
        'ajaxConversion' => true,
        'options' => [
            'pluginOptions' => [
                'placeholder' => Yii::t('app', 'Choose Completed At'),
                'autoclose' => true,
            ]
        ],
    ]); */ ?>

    <?php /* echo $form->field($model, 'completed')->textInput(['placeholder' => 'Completed']) */ ?>

    <?php /* echo $form->field($model, 'result')->textarea(['rows' => 6]) */ ?>

    <div class="form-group">
        <?= Html::submitButton(Yii::t('app', 'Search'), ['class' => 'btn btn-primary']) ?>
        <?= Html::resetButton(Yii::t('app', 'Reset'), ['class' => 'btn btn-default']) ?>
    </div>

    <?php ActiveForm::end(); ?>

</div>
