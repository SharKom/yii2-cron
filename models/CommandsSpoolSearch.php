<?php

namespace sharkom\cron\models;

use Yii;
use yii\base\Model;
use yii\data\ActiveDataProvider;
use sharkom\cron\models\CommandsSpool;

/**
 * sharkom\cron\models\CommandsSpoolSearch represents the model behind the search form about `sharkom\cron\models\CommandsSpool`.
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
        $query = CommandsSpool::find();


        $this->load($params);

        if(!empty($this->history) && $this->history==1) {
            $defaultSort=["executed_at"=>SORT_DESC];
        } else {
            $defaultSort=["created_at"=>SORT_ASC];

        }

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'sort'=>["defaultOrder"=>$defaultSort]
        ]);

        if (!$this->validate()) {
            // uncomment the following line if you do not want to return any records when validation fails
            // $query->where('0=1');
            return $dataProvider;
        }

        if(!empty($this->history) && $this->history==1) {
            $query->andWhere(["completed"=>[1, -1]]);
        } else {
            $query->andWhere(["completed"=>0]);

        }

        $query->andFilterWhere([
            'id' => $this->id,
            'provenience_id' => $this->provenience_id,
            'created_at' => $this->created_at,
            'executed_at' => $this->executed_at,
            'completed_at' => $this->completed_at,
        ]);

        $query->andFilterWhere(['like', 'command', $this->command])
            ->andFilterWhere(['like', 'provenience', $this->provenience])
            ->andFilterWhere(['like', 'logs', $this->logs])
            ->andFilterWhere(['like', 'errors', $this->errors])
            ->andFilterWhere(['like', 'logs_file', $this->logs_file])
            ->andFilterWhere(['like', 'completed', $this->completed])
            ->andFilterWhere(['like', 'result', $this->result]);

        return $dataProvider;
    }
}
