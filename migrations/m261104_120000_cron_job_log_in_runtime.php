<?php

use yii\db\Migration;
use yii\db\Query;

/**
 * I cron_job con logfile in /var/log/ passano a logfile vuoto: lo spooler scrive allora in
 * @runtime/logs/command_cron_job_<id>.log, che e' persistente (/var/log nel container
 * e' effimero), ruotato dallo spooler (LogRotator, .1.gz ... .5.gz) e coperto dalla
 * pulizia di cron/maintenance/purge-spool.
 *
 * I job si cercano per percorso (logfile LIKE '/var/log/%'), mai per id: gli id
 * cambiano da un'installazione all'altra. Idempotente: al secondo giro non trova nulla.
 * Senza tabella cron_job nessuna azione.
 *
 * DOWN: non ripristina. I percorsi precedenti non si possono salvare dentro la
 * migrazione (up e down girano in processi diversi e non c'e' una tabella apposita):
 * up() stampa nel log del deploy l'elenco id -> percorso e l'UPDATE per rimetterli a
 * mano. safeDown() non fa nulla e lo dice.
 */
class m261104_120000_cron_job_log_in_runtime extends Migration
{
    public const PREFISSO = '/var/log/';

    public function safeUp()
    {
        if (!$this->cronJobAvailable()) {
            echo "    > tabella cron_job assente: nessuna azione\n";
            return true;
        }
        $jobs = (new Query())
            ->select(['id', 'logfile'])
            ->from('{{%cron_job}}')
            ->where(['like', 'logfile', self::PREFISSO . '%', false])
            ->orderBy(['id' => SORT_ASC])
            ->all($this->db);
        if ($jobs === []) {
            echo "    > nessun cron_job con logfile in " . self::PREFISSO . "\n";
            return true;
        }
        foreach ($jobs as $job) {
            // Percorso precedente nel log del deploy, per un eventuale ripristino a mano.
            echo "    > cron_job {$job['id']}: logfile '{$job['logfile']}' -> '' (@runtime/logs); ripristino: "
                . "UPDATE cron_job SET logfile = " . $this->db->quoteValue($job['logfile']) . " WHERE id = {$job['id']};\n";
        }
        $this->update(
            '{{%cron_job}}',
            ['logfile' => ''],
            ['like', 'logfile', self::PREFISSO . '%', false]
        );
        return true;
    }

    public function safeDown()
    {
        echo "    > m261104_120000_cron_job_log_in_runtime: i logfile precedenti non vengono ripristinati "
            . "(vedi gli UPDATE stampati da up() nel log del deploy)\n";
        return true;
    }

    protected function cronJobAvailable(): bool
    {
        return $this->db->getTableSchema('{{%cron_job}}', true) !== null;
    }
}
