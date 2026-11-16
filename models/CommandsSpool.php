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
            [['created_at', 'executed_at', 'completed_at','command'], 'safe'],
            [['logs_file'], 'string', 'max' => 200],
            [['provenience'], 'string', 'max' => 100],
            [['completed'], 'string', 'max' => 4]
        ]);
    }

    /**
     * Log, errori ed esito arrivano dall'output dei comandi, tagliato in coda: un
     * taglio a meta' di un carattere UTF-8 (le cornici "┏━━━" del cleaning) fa
     * fallire il salvataggio con l'errore 1366, e lo spooler riprendeva sempre lo
     * stesso job bloccando la coda. Qui si tolgono i byte non validi.
     *
     * @inheritdoc
     */
    public function beforeSave($insert)
    {
        if (!parent::beforeSave($insert)) {
            return false;
        }
        foreach (['logs', 'errors', 'result'] as $attribute) {
            if (is_string($this->$attribute)) {
                $this->$attribute = self::utf8Valido($this->$attribute);
            }
        }
        return true;
    }

    /** Testo UTF-8 valido: i byte non validi (caratteri tagliati) diventano "?". */
    public static function utf8Valido(string $testo): string
    {
        if (mb_check_encoding($testo, 'UTF-8')) {
            return $testo;
        }
        return mb_scrub($testo, 'UTF-8');
    }
}
