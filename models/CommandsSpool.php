<?php

namespace sharkom\cron\models;

use Yii;
use \sharkom\cron\models\base\CommandsSpool as BaseCommandsSpool;

/**
 * This is the model class for table "commands_spool".
 */
class CommandsSpool extends BaseCommandsSpool
{
    /**
     * @inheritdoc
     */
    public function rules()
    {
        return array_replace_recursive(parent::rules(),
	    [
            [['provenience_id'], 'integer'],
            [['logs', 'errors', 'result'], 'string'],
            [['created_at', 'executed_at', 'completed_at'], 'safe'],
            [['command', 'logs_file'], 'string', 'max' => 200],
            [['provenience'], 'string', 'max' => 100],
            [['completed'], 'string', 'max' => 4]
        ]);
    }
	
}
