<?php

namespace sharkom\cron\models;

/**
 * This is the ActiveQuery class for [[\sharkom\cron\models\CreditNotesIncomingItems]].
 *
 * @see \sharkom\cron\models\CreditNotesIncomingItems
 */
class CreditNotesIncomingItemsQuery extends \yii\db\ActiveQuery
{
    /*public function active()
    {
        $this->andWhere('[[status]]=1');
        return $this;
    }*/

    /**
     * @inheritdoc
     * @return \sharkom\cron\models\CreditNotesIncomingItems[]|array
     */
    public function all($db = null)
    {
        return parent::all($db);
    }

    /**
     * @inheritdoc
     * @return \sharkom\cron\models\CreditNotesIncomingItems|array|null
     */
    public function one($db = null)
    {
        return parent::one($db);
    }
}
