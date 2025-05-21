<?php

use yii\db\Migration;

/**
 * Handles adding execution_time to table `{{%cron_job}}`.
 */
class m190525_122153_alter_cron_job extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        //alter table cron_job change column max_execution_time in execution_time
        $this->renameColumn('{{%cron_job}}', 'max_execution_time', 'execution_time');
        //add column last_execution datetime
        $this->addColumn('{{%cron_job}}', 'last_execution', $this->dateTime()->after('execution_time'));
        //add column extit_code - varchar(255)
        $this->addColumn('{{%cron_job}}', 'exit_code', $this->string()->after('last_execution'));

        /* add following indexes
        create index commands_spool_provenience_index
            on commands_spool (provenience);

        create index commands_spool_provenience_id_index
            on commands_spool (provenience_id);

        create index commands_spool_provenience_id_provenience_index
            on commands_spool (provenience_id, provenience);
        */
        $this->createIndex('commands_spool_provenience_index', 'commands_spool', 'provenience');
        $this->createIndex('commands_spool_provenience_id_index', 'commands_spool', 'provenience_id');
        $this->createIndex('commands_spool_provenience_id_provenience_index', 'commands_spool', ['provenience_id', 'provenience']);

    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropColumn('{{%cron_job}}', 'max_execution_time');
    }
}
