<?php

namespace sharkom\cron\commands;

use yii\console\Controller;
use yii\db\Query;
use yii\helpers\Console;
use Yii;

class ReportController extends Controller
{
    public function actionDaily()
    {
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $today = date('Y-m-d');

        Console::output("Generazione report giornaliero per: $yesterday");

        // Recupera i dati del giorno precedente
        $reportData = $this->generateReportData($yesterday, $today);

        if (empty($reportData['commands'])) {
            Console::output('No commands executed yesterday.');
            return;
        }

        // Genera il report HTML
        $htmlReport = $this->generateHtmlReport($reportData, $yesterday);

        // Invia via email
        $this->sendReport($htmlReport, $yesterday);

        Console::output('Daily report sent successfully!');
    }

    private function generateReportData($dateFrom, $dateTo)
    {
        // Query per i dati aggregati per comando
        $commandsData = (new Query())
            ->select([
                'command',
                'provenience',
                'COUNT(*) as total_executions',
                'SUM(CASE WHEN result = "success" THEN 1 ELSE 0 END) as successes',
                'SUM(CASE WHEN result = "error" THEN 1 ELSE 0 END) as errors',
                'SUM(CASE WHEN result = "fatal" THEN 1 ELSE 0 END) as fatals',
                'SUM(CASE WHEN completed = 1 THEN 1 ELSE 0 END) as completed_count',
                'AVG(CASE WHEN executed_at IS NOT NULL AND completed_at IS NOT NULL 
                     THEN TIMESTAMPDIFF(SECOND, executed_at, completed_at) END) as avg_execution_time',
                'MAX(CASE WHEN executed_at IS NOT NULL AND completed_at IS NOT NULL 
                     THEN TIMESTAMPDIFF(SECOND, executed_at, completed_at) END) as max_execution_time',
                'MIN(CASE WHEN executed_at IS NOT NULL AND completed_at IS NOT NULL 
                     THEN TIMESTAMPDIFF(SECOND, executed_at, completed_at) END) as min_execution_time',
                'COUNT(CASE WHEN executed_at IS NULL THEN 1 END) as not_executed',
                'COUNT(CASE WHEN completed_at IS NULL AND executed_at IS NOT NULL THEN 1 END) as incomplete'
            ])
            ->from('commands_spool')
            ->where(['>=', 'created_at', $dateFrom])
            ->andWhere(['<', 'created_at', $dateTo])
            ->groupBy(['command', 'provenience'])
            ->orderBy(['total_executions' => SORT_DESC, 'command' => SORT_ASC])
            ->all();

        // Query per statistiche generali
        $generalStats = (new Query())
            ->select([
                'COUNT(*) as total_commands',
                'SUM(CASE WHEN result = "success" THEN 1 ELSE 0 END) as total_successes',
                'SUM(CASE WHEN result = "error" THEN 1 ELSE 0 END) as total_errors',
                'SUM(CASE WHEN result = "fatal" THEN 1 ELSE 0 END) as total_fatals',
                'SUM(CASE WHEN completed = 1 THEN 1 ELSE 0 END) as total_completed',
                'COUNT(CASE WHEN executed_at IS NULL THEN 1 END) as total_not_executed',
                'AVG(CASE WHEN executed_at IS NOT NULL AND completed_at IS NOT NULL 
                     THEN TIMESTAMPDIFF(SECOND, executed_at, completed_at) END) as overall_avg_time',
                'COUNT(DISTINCT command) as unique_commands',
                'COUNT(DISTINCT provenience) as unique_proveniences'
            ])
            ->from('commands_spool')
            ->where(['>=', 'created_at', $dateFrom])
            ->andWhere(['<', 'created_at', $dateTo])
            ->one();

        // Query per l'errore più recente di ogni comando che ha avuto errori
        $recentErrors = (new Query())
            ->select([
                'cs1.id',
                'cs1.command',
                'cs1.provenience',
                'cs1.result',
                'cs1.errors',
                'cs1.created_at',
                'cs1.executed_at',
                'cs1.completed_at'
            ])
            ->from('commands_spool cs1')
            ->innerJoin('(
                SELECT command, provenience, MAX(created_at) as max_created_at
                FROM commands_spool 
                WHERE created_at >= "' . $dateFrom . '" 
                AND created_at < "' . $dateTo . '" 
                AND result IN ("error", "fatal")
                GROUP BY command, provenience
            ) cs2', 'cs1.command = cs2.command AND cs1.provenience = cs2.provenience AND cs1.created_at = cs2.max_created_at')
            ->where(['>=', 'cs1.created_at', $dateFrom])
            ->andWhere(['<', 'cs1.created_at', $dateTo])
            ->andWhere(['in', 'cs1.result', ['error', 'fatal']])
            ->orderBy(['cs1.result' => SORT_DESC, 'cs1.created_at' => SORT_DESC])
            ->all();

        return [
            'commands' => $commandsData,
            'general' => $generalStats,
            'errors' => $recentErrors
        ];
    }

    private function generateHtmlReport($data, $date)
    {
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Report Giornaliero Comandi Spool - ' . $date . '</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .header { background-color: #f8f9fa; padding: 20px; border-radius: 5px; margin-bottom: 20px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 30px; }
        .stat-card { background: #ffffff; border: 1px solid #dee2e6; border-radius: 5px; padding: 15px; text-align: center; }
        .stat-number { font-size: 2em; font-weight: bold; margin-bottom: 5px; }
        .success { color: #28a745; }
        .error { color: #dc3545; }
        .warning { color: #ffc107; }
        .info { color: #17a2b8; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
        th, td { border: 1px solid #dee2e6; padding: 8px 12px; text-align: left; }
        th { background-color: #f8f9fa; font-weight: bold; }
        tr:nth-child(even) { background-color: #f8f9fa; }
        .command-cell { max-width: 300px; word-break: break-word; font-family: monospace; font-size: 0.9em; }
        .error-cell { max-width: 400px; word-break: break-word; font-size: 0.8em; }
        .center { text-align: center; }
        .right { text-align: right; }
    </style>
</head>
<body>';

        // Header
        $html .= '<div class="header">
            <h1>Report Giornaliero Comandi Spool</h1>
            <h2>Data: ' . $date . '</h2>
            <p>Generato il: ' . date('d/m/Y H:i:s') . '</p>
        </div>';

        // Statistiche generali
        $general = $data['general'];
        $successRate = $general['total_commands'] > 0 ? round(($general['total_successes'] / $general['total_commands']) * 100, 2) : 0;
        $completionRate = $general['total_commands'] > 0 ? round(($general['total_completed'] / $general['total_commands']) * 100, 2) : 0;

        $html .= '<h3>Statistiche Generali</h3>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number info">' . $general['total_commands'] . '</div>
                <div>Totale Comandi</div>
            </div>
            <div class="stat-card">
                <div class="stat-number success">' . $general['total_successes'] . '</div>
                <div>Successi</div>
            </div>
            <div class="stat-card">
                <div class="stat-number error">' . $general['total_errors'] . '</div>
                <div>Errori</div>
            </div>
            <div class="stat-card">
                <div class="stat-number error">' . $general['total_fatals'] . '</div>
                <div>Errori Fatali</div>
            </div>
            <div class="stat-card">
                <div class="stat-number warning">' . $general['total_not_executed'] . '</div>
                <div>Non Eseguiti</div>
            </div>
            <div class="stat-card">
                <div class="stat-number success">' . $successRate . '%</div>
                <div>Tasso di Successo</div>
            </div>
            <div class="stat-card">
                <div class="stat-number info">' . $completionRate . '%</div>
                <div>Tasso di Completamento</div>
            </div>
            <div class="stat-card">
                <div class="stat-number info">' . round($general['overall_avg_time'] ?? 0, 2) . 's</div>
                <div>Tempo Medio Esecuzione</div>
            </div>
        </div>';

        // Tabella dettagliata per comando
        $html .= '<h3>Dettaglio Comandi</h3>
        <table>
            <thead>
                <tr>
                    <th>Comando</th>
                    <th>Provenienza</th>
                    <th class="center">Totale</th>
                    <th class="center">Successi</th>
                    <th class="center">Errori</th>
                    <th class="center">Fatali</th>
                    <th class="center">Non Eseguiti</th>
                    <th class="center">Incompleti</th>
                    <th class="center">Tasso Successo</th>
                    <th class="center">Tempo Medio (s)</th>
                    <th class="center">Tempo Max (s)</th>
                    <th class="center">Tempo Min (s)</th>
                </tr>
            </thead>
            <tbody>';

        foreach ($data['commands'] as $cmd) {
            $cmdSuccessRate = $cmd['total_executions'] > 0 ? round(($cmd['successes'] / $cmd['total_executions']) * 100, 2) : 0;
            $rowClass = '';
            if ($cmd['fatals'] > 0) {
                $rowClass = 'style="background-color: #f8d7da;"';
            } elseif ($cmd['errors'] > 0) {
                $rowClass = 'style="background-color: #fff3cd;"';
            }

            $html .= '<tr ' . $rowClass . '>
                <td class="command-cell">' . htmlspecialchars($cmd['command']) . '</td>
                <td>' . htmlspecialchars($cmd['provenience'] ?? 'N/A') . '</td>
                <td class="center">' . $cmd['total_executions'] . '</td>
                <td class="center success">' . $cmd['successes'] . '</td>
                <td class="center error">' . $cmd['errors'] . '</td>
                <td class="center error">' . $cmd['fatals'] . '</td>
                <td class="center warning">' . $cmd['not_executed'] . '</td>
                <td class="center warning">' . $cmd['incomplete'] . '</td>
                <td class="center">' . $cmdSuccessRate . '%</td>
                <td class="center">' . round($cmd['avg_execution_time'] ?? 0, 2) . '</td>
                <td class="center">' . ($cmd['max_execution_time'] ?? 'N/A') . '</td>
                <td class="center">' . ($cmd['min_execution_time'] ?? 'N/A') . '</td>
            </tr>';
        }

        $html .= '</tbody></table>';

        // Errori recenti
        if (!empty($data['errors'])) {
            $html .= '<h3>Ultimo Errore per Comando</h3>
            <p><em>Mostra l\'errore più recente per ogni comando che ha avuto almeno un errore nel giorno selezionato</em></p>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Comando</th>
                        <th>Provenienza</th>
                        <th>Risultato</th>
                        <th>Creato il</th>
                        <th>Eseguito il</th>
                        <th>Messaggio Errore</th>
                    </tr>
                </thead>
                <tbody>';

            foreach ($data['errors'] as $error) {
                $resultClass = $error['result'] === 'fatal' ? 'error' : 'warning';
                $html .= '<tr>
                    <td>' . $error['id'] . '</td>
                    <td class="command-cell">' . htmlspecialchars($error['command']) . '</td>
                    <td>' . htmlspecialchars($error['provenience'] ?? 'N/A') . '</td>
                    <td class="center ' . $resultClass . '">' . strtoupper($error['result']) . '</td>
                    <td>' . $error['created_at'] . '</td>
                    <td>' . ($error['executed_at'] ?? 'Non eseguito') . '</td>
                    <td class="error-cell">' . htmlspecialchars(substr($error['errors'] ?? 'N/A', 0, 200)) . '</td>
                </tr>';
            }

            $html .= '</tbody></table>';
        }

        $html .= '</body></html>';

        return $html;
    }

    private function sendReport($htmlContent, $date)
    {
        $subject = "Daily Spool Commands Report - $date";

        // Configura il destinatario (potresti volerlo configurabile)
        $to = Yii::$app->params['NotificationsEmail'] ?? 'dev@sharkom.net';

        try {
            Yii::$app->mailer->compose()
                ->setTo($to)
                ->setFrom([Yii::$app->params['senderEmail'] ?? 'no-reply@sharkom.net' => 'SoiundOrgan Spool Report'])
                ->setSubject($subject)
                ->setHtmlBody($htmlContent)
                ->send();

            Console::output("Report sent to: $to");
        } catch (\Exception $e) {
            Console::error('Failed to send report: ' . $e->getMessage());
            // Salva il report su file come backup
            $this->saveReportToFile($htmlContent, $date);
        }
    }

    private function saveReportToFile($htmlContent, $date)
    {
        $filename = Yii::getAlias('@runtime') . "/spool_report_$date.html";
        file_put_contents($filename, $htmlContent);
        Console::output("Report salvato nel file: $filename");
    }
}