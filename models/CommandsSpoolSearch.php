<?php

namespace sharkom\cron\models;

use Yii;
use yii\base\Model;
use yii\data\ActiveDataProvider;
use sharkom\cron\models\CommandsSpool;
use yii\db\Expression;

/**
 * sharkom\cron\models\CommandsSpoolSearch represents the model behind the search form about sharkom\cron\models\CommandsSpool.
 */
class CommandsSpoolSearch extends CommandsSpool
{
    public $history;

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['id', 'provenience_id'], 'integer'],
            [['command', 'provenience', 'logs', 'errors', 'logs_file', 'created_at', 'executed_at', 'completed_at', 'completed', 'result', 'history'], 'safe'],
        ];
    }

    /**
     * @inheritdoc
     */
    public function scenarios()
    {
        // bypass scenarios() implementation in the parent class
        return Model::scenarios();
    }

    /**
     * Creates data provider instance with search query applied
     *
     * @param array $params
     *
     * @return ActiveDataProvider
     */
    public function search($params)
    {
        // 1) Costruisco la query e aggiungo la colonna virtuale statusOrder
        $table    = CommandsSpool::getTableSchema();
        $cols     = $table->columnNames;
        $filtered = array_diff($cols, ['logs']);

        $query = CommandsSpool::find()
            ->select($filtered)
            ->addSelect(new Expression('CASE WHEN completed=0 THEN 0 ELSE 1 END AS statusOrder'));


        $this->load($params);

        // 2) Imposto l'ordine di default in base a history
        $defaultSort = ['executed_at' => SORT_DESC];
        if (isset($this->history)) {
            switch ($this->history) {
                case 0:
                    $defaultSort = ['created_at' => SORT_ASC];
                    break;
                case 1:
                    $defaultSort = ['executed_at' => SORT_DESC];
                    break;
                case 2:
                    // qui usiamo il sort virtuale byStatus
                    $defaultSort = ['byStatus' => SORT_ASC];
                    break;
            }
        }

        // 3) Configuro l'ActiveDataProvider con il defaultOrder
        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'sort'  => [
                'defaultOrder' => $defaultSort,
                'attributes'   => [
                    // mantengo tutti gli attributi di default...
                    'id',
                    'command',
                    'provenience',
                    'created_at',
                    'executed_at',
                    'completed_at',
                    'completed',
                    'result',
                    // ...e aggiungo il virtual sort byStatus:
                    'byStatus' => [
                        'asc'  => ['statusOrder' => SORT_ASC, 'executed_at' => SORT_DESC],
                        'desc' => ['statusOrder' => SORT_DESC, 'executed_at' => SORT_DESC],
                        'label' => 'Stato / Executed At',
                    ],
                ],
            ],
        ]);

        if (!$this->validate()) {
            return $dataProvider;
        }

        // 4) Filtri di history sulla colonna completed
        if (isset($this->history)) {
            switch ($this->history) {
                case 0:
                    $query->andWhere(['completed' => 0]);
                    break;
                case 1:
                    $query->andWhere(['completed' => [1, -1]]);
                    break;
                case 2:
                    $query->andWhere(['completed' => [0, 1, -1]]);
                    break;
            }
        }

        // 5) Altri filtri
        $query->andFilterWhere([
            'id'              => $this->id,
            'provenience_id'  => $this->provenience_id,
            'created_at'      => $this->created_at,
            'executed_at'     => $this->executed_at,
            'completed_at'    => $this->completed_at,
            'provenience' =>$this->provenience,
        ]);

        $query->andFilterWhere(['like', 'command',      $this->command])
            //->andFilterWhere(['like', 'provenience',  $this->provenience])
            ->andFilterWhere(['like', 'logs',         $this->logs])
            ->andFilterWhere(['like', 'errors',       $this->errors])
            ->andFilterWhere(['like', 'logs_file',    $this->logs_file])
            ->andFilterWhere(['like', 'completed',    $this->completed])
            ->andFilterWhere(['like', 'result',       $this->result]);

        return $dataProvider;
    }
}
