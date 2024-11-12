<?php

namespace sharkom\cron\controllers;

use sharkom\devhelper\NormalizeHelper;
use Yii;
use sharkom\cron\models\CommandsSpool;
use sharkom\cron\models\CommandsSpoolSearch;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\filters\VerbFilter;
use yii\web\Response;
use yii\helpers\Html;
use yii\helpers\Console;
/**
 * CommandsSpoolController implements the CRUD actions for CommandsSpool model.
 */
class CommandsSpoolController extends Controller
{
    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return [
            'verbs' => [
                'class' => VerbFilter::className(),
                'actions' => [
                    'delete' => ['POST'],
                ],
            ],
        ];
    }

    /**
     * Lists all CommandsSpool models.
     * @return mixed
     */
    public function actionIndex()
    {
        $searchModel = new CommandsSpoolSearch();
        $dataProvider = $searchModel->search(Yii::$app->request->queryParams);

        return $this->render('index', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
        ]);
    }

    /**
     * Displays a single CommandsSpool model.
     * @param integer $id
     * @return mixed
     */
    public function actionView($id)
    {
        return $this->render('view', [
            'model' => $this->findModel($id),
        ]);
    }

    /**
     * Creates a new CommandsSpool model.
     * If creation is successful, the browser will be redirected to the 'view' page.
     * @return mixed
     */
    public function actionCreate()
    {
        $model = new CommandsSpool();

        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            return $this->redirect(['index', 'id' => $model->id]);
        } else {
            return $this->render('create', [
                'model' => $model,
            ]);
        }
    }

    /**
     * Updates an existing CommandsSpool model.
     * If update is successful, the browser will be redirected to the 'view' page.
     * @param integer $id
     * @return mixed
     */
    public function actionUpdate($id)
    {
        $model = $this->findModel($id);

        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            return $this->redirect(['index', 'id' => $model->id]);
        } else {
            return $this->render('update', [
                'model' => $model,
            ]);
        }
    }

    /**
     * Deletes an existing CommandsSpool model.
     * If deletion is successful, the browser will be redirected to the 'index' page.
     * @param integer $id
     * @return mixed
     */
    public function actionDelete($id)
    {
        $this->findModel($id)->delete();

        return $this->redirect(['index']);
    }

    /**
     * Finds the CommandsSpool model based on its primary key value.
     * If the model is not found, a 404 HTTP exception will be thrown.
     * @param integer $id
     * @return CommandsSpool the loaded model
     * @throws NotFoundHttpException if the model cannot be found
     */
    protected function findModel($id)
    {
        if (($model = CommandsSpool::findOne($id)) !== null) {
            return $model;
        } else {
            throw new NotFoundHttpException('The requested page does not exist.');
        }
    }

    public function actionTail($file = '/var/log/messages')
    {
        \Yii::$app->response->format = Response::FORMAT_JSON;
        $realPath = \Yii::getAlias($file);

        if (!file_exists($realPath)) {
            return ['error' => 'File non trovato'];
        }

        $size = 1024 * 100; // Legge gli ultimi 100KB
        $handle = fopen($realPath, 'r');
        fseek($handle, -$size, SEEK_END);
        $content = fread($handle, $size);
        fclose($handle);

        // Prende solo le righe complete
        $lines = explode("\n", $content);
        array_shift($lines); // Rimuove la prima riga parziale
        $content = implode("\n", $lines);

        return [
            'content' => \yii\helpers\Console::ansiToHtml($content, [
                30 => ['color' => '#333333']  // 30 è il codice ANSI per FG_BLACK
            ]),
            'timestamp' => filemtime($realPath)
        ];
    }

/*    public function actionNinjaTest() {
        \Yii::$app->response->format = Response::FORMAT_JSON;

        $db = \Yii::$app->db;
        $query = 'SELECT COUNT(*) FROM `billing_operative_mandates` 
              WHERE (`execution` = 0) 
              AND (send_after <= "2024-11-02 08:00:00") 
              AND (
                  need_test = 0 
                  OR (need_test = 1 
                      AND EXISTS (
                          SELECT 1 
                          FROM quotations q 
                          WHERE q.id = offer_id 
                          AND q.status IN (6, 9, 15)
                      )
                  )
              )';

        $res = $db->createCommand($query)->queryScalar();

        return ['message' => $res];
    }*/

    public function actionModal()
    {
        return $this->renderPartial('_log_modal');
    }

    public function actionLazyLoadLogs($id)
    {
        $model = $this->findModel($id);

        // Prendiamo gli ultimi 1 milione di caratteri
        $logs = $model->logs;
        $maxLength = 1000000; // 1 milione di caratteri


        if (strlen($logs) > $maxLength) {
            // Se il log è più lungo di 1M caratteri, prendiamo gli ultimi 1M
            // Aggiungiamo un messaggio all'inizio per indicare il troncamento
            $truncateMessage = "====== Log troncato, mostrati gli ultimi " . number_format($maxLength, 0,',','.') . " su ". number_format(strlen($model->logs), 0,',','.')." caratteri ======\n====== Scarica il file per analizare il log completo ======\n\n";
            $logs = $truncateMessage . substr($logs, -$maxLength);
        }

        return Html::tag('div',
            Html::tag('pre', Console::ansiToHtml($logs, [
                30 => ['color' => '#333333']  // 30 è il codice ANSI per FG_BLACK
            ]), [
                'style' => 'width: 100%; min-width: 500px; padding: 15px; background-color: #323232; color: white; white-space: pre-wrap; word-wrap: break-word; margin: 0;'
            ]),
            ['style' => 'max-height: 400px; overflow-y: auto; position: relative; margin: 10px 0;']
        );
    }

    /*public function actionDownloadLogs($file)
    {
        //force file download
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.basename($file).'"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
    }*/

    public function actionDownloadLogs($id)
    {
        // Trova il record nel database
        $model = CommandsSpool::findOne($id);

        if (!$model) {
            throw new NotFoundHttpException('Record non trovato');
        }

        // Verifica se esiste il contenuto dei log
        $content = '';

        // Controlla prima il campo logs
        if (!empty($model->logs)) {
            $content = $model->logs;
        }
        // Se non ci sono logs diretti, prova a leggere dal file

        if (empty($content)) {
            throw new NotFoundHttpException('Nessun log disponibile');
        }

        // Genera un nome file significativo
        $commandArray=explode(" ", $model->command);
        $command = end($commandArray);
        $command=str_replace("/", "_", $command);
        //$command=NormalizeHelper::normalize($model->command);
        $filename = "log_comando_{$model->id}_".$command."_del_". date('Y-m-d_His', strtotime($model->executed_at)) . ".txt";

        // Imposta gli header per il download
        Yii::$app->response->format = Response::FORMAT_RAW;
        Yii::$app->response->headers->add('Content-Type', 'text/plain');
        Yii::$app->response->headers->add('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $content;
    }
}
