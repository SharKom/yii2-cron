<?php

namespace sharkom\cron\models\base;

use Yii;
use yii\behaviors\TimestampBehavior;

/**
 * This is the base model class for table "commands_spool".
 *
 * @property integer $id
 * @property string $command
 * @property string $provenience
 * @property integer $provenience_id
 * @property string $logs
 * @property string $errors
 * @property string $logs_file
 * @property string $created_at
 * @property string $executed_at
 * @property string $completed_at
 * @property integer $completed
 * @property string $result
 */
class CommandsSpool extends \yii\db\ActiveRecord
{
    
    use \mootensai\relation\RelationTrait;


    /**
    * This function helps \mootensai\relation\RelationTrait runs faster
    * @return array relation names of this model
    */
    public function relationNames()
    {
        return [
            ''
        ];
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['provenience_id'], 'integer'],
            [['logs', 'errors', 'result'], 'string'],
            [['created_at', 'executed_at', 'completed_at'], 'safe'],
            [['command', 'logs_file'], 'string', 'max' => 200],
            [['provenience'], 'string', 'max' => 100],
            [['completed'], 'string', 'max' => 4]
        ];
    }

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'commands_spool';
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'id' => Yii::t('app', 'ID'),
            'command' => Yii::t('app', 'Command'),
            'provenience' => Yii::t('app', 'Provenience'),
            'provenience_id' => Yii::t('app', 'Provenience ID'),
            'logs' => Yii::t('app', 'Logs'),
            'errors' => Yii::t('app', 'Errors'),
            'logs_file' => Yii::t('app', 'Logs File'),
            'executed_at' => Yii::t('app', 'Executed At'),
            'completed_at' => Yii::t('app', 'Completed At'),
            'completed' => Yii::t('app', 'Completed'),
            'result' => Yii::t('app', 'Result'),
        ];
    }

    /**
     * @inheritdoc
     * @return array mixed
     */
    public function behaviors()
    {
        return [
            'timestamp' => [
                'class' => TimestampBehavior::className(),
                'createdAtAttribute' => 'created_at',
                'updatedAtAttribute' => false,
                'value' => new \yii\db\Expression('NOW()'),
            ],
        ];
    }


    /**
     * @inheritdoc
     * @return \sharkom\cron\models\CommandsSpoolQuery the active query used by this AR class.
     */
    public static function find()
    {
        return new \sharkom\cron\models\CommandsSpoolQuery(get_called_class());
    }
}
