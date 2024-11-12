<?php

namespace sharkom\cron\models;

/**
 * This is the ActiveQuery class for [[CommandsSpool]].
 *
 * @see CommandsSpool
 */
class CommandsSpoolQuery extends \yii\db\ActiveQuery
{
    /*public function active()
    {
        $this->andWhere('[[status]]=1');
        return $this;
    }*/

    /**
     * @inheritdoc
     * @return CommandsSpool[]|array
     */
    public function all($db = null)
    {
        return parent::all($db);
    }

    /**
     * @inheritdoc
     * @return CommandsSpool|array|null
     */
    public function one($db = null)
    {
        return parent::one($db);
    }
}
