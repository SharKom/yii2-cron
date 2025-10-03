<?php

namespace sharkom\cron\commands;

date_default_timezone_set("europe/rome");

use sharkom\devhelper\LogHelper;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\db\Expression;
use sharkom\cron\models\CommandsSpool;
use Yii;

if (!defined('SIGTERM')) define('SIGTERM', 15);
if (!defined('SIGKILL')) define('SIGKILL', 9);

class SpoolerController extends Controller
{
    private $defaultServiceName = 'yii2-spooler';
    private $serviceConfigFile;
    private $running = true;
    private $currentCommand = null;
    private $initialMemory = 0;
    private $echoProcessOutput = false;

    // Limiti/contatori
    private $maxMemoryLeak = 50;             // MB - limite massimo di leak consentito
    private $maxCommandsBeforeRestart = 100; // numero massimo di comandi prima del restart
    private $restartMemoryThreshold = 50;    // MB - soglia di leak per forzare restart
    private $commandsExecuted = 0;

    // Shutdown
    private $shouldExit = false;
    private $shutdownStartTime = null;
    private $shutdownTimeout = 10; // secondi, corrisponde al TimeoutStopSec di systemd

    // Tail (ultimi N byte da mantenere in RAM per DB/email)
    private $tailLimitBytes = 65536; // 64KB

    public function init()
    {
        parent::init();
        $this->serviceConfigFile = Yii::getAlias('@runtime/spooler-service.conf');
        if (!gc_enabled()) {
            gc_enable();
        }
    }

    /**
     * Garantisce che il file di log sia pronto: crea la cartella e il file se mancano.
     */
    private function ensureLogFile(string $logFile): void
    {
        $dir = dirname($logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (!file_exists($logFile)) {
            @touch($logFile);
            @chmod($logFile, 0664);
        }
    }

    /**
     * Apre un handle di log in append binario.
     * @return resource|null
     */
    private function openLogHandle(string $logFile)
    {
        $fh = @fopen($logFile, 'ab');
        if ($fh) {
            return $fh;
        }
        return null;
    }

    /**
     * Scrive chunk su handle di log (senza rtrim per evitare copie).
     */
    private function writeLogChunk($handle, string $chunk): void
    {
        if (is_resource($handle) && $chunk !== '') {
            @fwrite($handle, $chunk);
        }
    }

    /**
     * Scrive una riga singola con newline garantito.
     */
    private function writeLogLine($handle, string $line): void
    {
        if (substr($line, -1) !== "\n") {
            $line .= "\n";
        }
        $this->writeLogChunk($handle, $line);
    }

    /**
     * Mantiene solo la coda (ultimi N byte) in buffer.
     */
    private function keepTail(&$buffer, $chunk, $maxBytes)
    {
        $buffer .= $chunk;
        $len = strlen($buffer);
        if ($len > $maxBytes) {
            $buffer = substr($buffer, $len - $maxBytes);
        }
    }

    /**
     * Stampa condizionale sulla console solo se esplicitamente abilitata per i comandi processati.
     */
    private function processEcho(string $line): void
    {
        if ($this->echoProcessOutput) {
            $this->stdout($line . (substr($line, -1) === "\n" ? '' : "\n"));
        }
    }

    /**
     * Check if we need to restart based on memory usage
     */
    private function shouldRestart($memory)
    {
        if ($this->commandsExecuted >= $this->maxCommandsBeforeRestart) {
            LogHelper::log("warning", "Reached maximum commands limit ({$this->maxCommandsBeforeRestart}). Triggering restart.");
            return true;
        }
        if ($memory['leak'] >= $this->restartMemoryThreshold) {
            LogHelper::log("warning", "Memory leak ({$memory['leak']}MB) exceeded threshold ({$this->restartMemoryThreshold}MB). Triggering restart.");
            return true;
        }
        if ($memory['leak'] >= $this->maxMemoryLeak) {
            LogHelper::log("error", "Memory leak ({$memory['leak']}MB) exceeded maximum limit ({$this->maxMemoryLeak}MB). Emergency restart required.");
            return true;
        }
        return false;
    }

    /**
     * Perform a graceful restart
     */
    private function restartProcess()
    {
        LogHelper::log("info", "Initiating graceful restart...");

        // Pulizia finale prima del restart
        $this->cleanupResources();

        // Log dello stato finale prima del restart
        $finalMemory = $this->getMemoryUsage();
        LogHelper::log("info", "Final memory state before restart - Current: {$finalMemory['current']}MB, Leak: {$finalMemory['leak']}MB");

        // Esegui il restart usando systemctl
        $serviceName = $this->getServiceName();
        LogHelper::log("info", "Requesting service restart through systemd...");
        exec("systemctl restart $serviceName > /dev/null 2>&1 &");

        // Termina il processo corrente
        exit(ExitCode::OK);
    }

    private function getMemoryUsage()
    {
        $mem = memory_get_usage(true);
        $memReal = memory_get_usage(false);
        $memPeak = memory_get_peak_usage(true);
        $memPeakReal = memory_get_peak_usage(false);

        return [
            'current' => round($mem / 1024 / 1024, 2),
            'current_real' => round($memReal / 1024 / 1024, 2),
            'peak' => round($memPeak / 1024 / 1024, 2),
            'peak_real' => round($memPeakReal / 1024 / 1024, 2),
            'leak' => round(($mem - $this->initialMemory) / 1024 / 1024, 2)
        ];
    }

    /**
     * Clean up resources and memory
     */
    private function cleanupResources()
    {
        clearstatcache();

        // Chiudi connessioni DB
        if (isset(Yii::$app->db)) {
            Yii::$app->db->close();
        }

        // GC aggressiva
        gc_collect_cycles();
        if (function_exists('gc_mem_caches')) {
            gc_mem_caches();
        }
    }

    /**
     * Handle shutdown gracefully
     */
    public function handleShutdown()
    {
        $this->running = false;
        $this->shouldExit = true;
        $this->shutdownStartTime = time();

        if ($this->currentCommand) {
            LogHelper::log("info", "Received stop signal. Waiting up to {$this->shutdownTimeout} seconds for current command to finish...");
            $this->stdout("\nReceived stop signal. Waiting up to {$this->shutdownTimeout} seconds for current command to finish...\n");
        } else {
            LogHelper::log("info", "Received stop signal. Shutting down...");
            $this->stdout("\nReceived stop signal. Shutting down...\n");
        }
    }

    private function checkShutdownTimeout($process = null)
    {
        if ($this->shutdownStartTime === null) {
            return false;
        }

        $elapsedTime = time() - $this->shutdownStartTime;
        if ($elapsedTime >= $this->shutdownTimeout) {
            LogHelper::log("warning", "Shutdown timeout reached after {$elapsedTime} seconds. Forcing termination.");
            $this->stdout("\nShutdown timeout reached. Forcing termination...\n");

            if ($process && is_resource($process)) {
                // SIGTERM
                @proc_terminate($process, SIGTERM);

                // attesa breve
                $waitStart = time();
                while (time() - $waitStart < 5) {
                    $status = @proc_get_status($process);
                    if (!$status || !$status['running']) {
                        break;
                    }
                    usleep(100000);
                }

                // forza chiusura
                $status = @proc_get_status($process);
                if ($status && $status['running']) {
                    @proc_terminate($process, SIGKILL);
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Get current service name from config or default
     */
    private function getServiceName()
    {
        if (file_exists($this->serviceConfigFile)) {
            return trim(@file_get_contents($this->serviceConfigFile));
        }
        return $this->defaultServiceName;
    }

    /**
     * Save service name to config
     */
    private function saveServiceName($name)
    {
        @file_put_contents($this->serviceConfigFile, $name);
    }

    /**
     * Process pending commands in the commands_spool table
     * @return int Exit code
     */
    public function actionProcess()
    {
        $this->initialMemory = memory_get_usage(true);

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, [$this, 'handleShutdown']);
            pcntl_signal(SIGINT,  [$this, 'handleShutdown']);
        }

        // Questi log usano LogHelper (log di controller/servizio), non stdout.
        LogHelper::log('info', 'Starting spool processing with memory limits:');
        LogHelper::log('info', "- Max memory leak: {$this->maxMemoryLeak}MB");
        LogHelper::log('info', "- Restart threshold: {$this->restartMemoryThreshold}MB");
        LogHelper::log('info', "- Max commands before restart: {$this->maxCommandsBeforeRestart}");

        $iterationCount = 0;
        $lastMemory = 0;

        $conn = Yii::$app->db;

        // Recupero comandi interrotti: limita in RAM (tail)
        $res = $conn->createCommand('
            SELECT * FROM commands_spool
            WHERE completed = 0
              AND executed_at IS NOT NULL
        ')->queryAll();

        foreach ($res as $interrupted) {
            $logFile = $interrupted['logs_file'];
            $targetStart = "=== COMMAND EXECUTION START {$interrupted['id']} ===\n";
            $logs = null;

            if ($logFile && is_readable($logFile)) {
                $handle = fopen($logFile, 'rb');
                if ($handle) {
                    $startFound = false;
                    $tail = '';
                    while (!feof($handle)) {
                        $chunk = fread($handle, 8192);
                        if ($chunk === false) {
                            break;
                        }
                        if (!$startFound) {
                            if (strpos($chunk, $targetStart) !== false) {
                                $startFound = true;
                                $this->keepTail($tail, $targetStart, $this->tailLimitBytes);
                            }
                        } else {
                            $this->keepTail($tail, $chunk, $this->tailLimitBytes);
                        }
                    }
                    fclose($handle);
                    if ($startFound) {
                        $logs = $tail; // solo tail
                    }
                }
            }

            $spool = CommandsSpool::findOne($interrupted['id']);
            if ($spool) {
                $spool->result = 'fatal';
                $spool->completed = -1;
                $prefix = "Informazioni (tail) recuperate dai log dopo la chiusura imprevista del processo:\n\n---------------------------------\n";
                $spool->logs = $prefix . ($logs !== null ? $logs : '[Nessuna coda trovata]');
                $spool->save(false);
            }
        }

        while ($this->running) {
            $iterationCount++;

            // Monitoraggio memoria
            $memory = $this->getMemoryUsage();
            $memoryChange = $lastMemory > 0 ? sprintf('(%+.2fMB)', $memory['current'] - $lastMemory) : '';
            $memoryInfo = sprintf(
                'Iteration %d - Memory: Current: %sMB %s, Real: %sMB, Peak: %sMB, Leak: %sMB',
                $iterationCount,
                $memory['current'],
                $memoryChange,
                $memory['current_real'],
                $memory['peak'],
                $memory['leak']
            );
            LogHelper::log('debug', $memoryInfo);

            // Restart safety
            if ($this->shouldRestart($memory)) {
                LogHelper::log('warning', "Restart condition met after $iterationCount iterations.");
                $this->restartProcess();
            }
            $lastMemory = $memory['current'];

            // Pulizia risorse
            $this->cleanupResources();

            // Prendo prossimo comando pending
            $command = Yii::$app->db->createCommand('
                SELECT * FROM commands_spool
                WHERE completed = 0
                  AND executed_at IS NULL
                ORDER BY created_at ASC
                LIMIT 1
            ')->queryOne();

            if (!$command) {
                if (!$this->running) {
                    break;
                }
                sleep(5);
                continue;
            }

            // ======= DA QUI IN POI: SEZIONE SILENZIOSA PER I COMANDI DELLO SPOOLER =======
            $logHandle = null;
            $tailStdout = '';
            $tailStderr = '';
            $logFile = null;
            $startTime = time();
            $loopBreakRequested = false; // <== flag per evitare break dentro finally

            try {
                $this->currentCommand = $command;

                // segno l'avvio
                $spool = CommandsSpool::findOne($command['id']);
                if ($spool) {
                    $spool->executed_at = new \yii\db\Expression('NOW()');
                    $spool->save(false);
                }

                // Risolvo log file
                $logFile = !empty($command['logs_file'])
                    ? $command['logs_file']
                    : Yii::getAlias("@runtime/logs/command_{$command['provenience']}_{$command['provenience_id']}.log");

                // Creo cartella/file se non esistono
                $this->ensureLogFile($logFile);

                // Se lo spool non ha il path, lo aggiorno SUBITO
                if ($spool && empty($spool->logs_file)) {
                    $spool->logs_file = $logFile;
                    $spool->save(false);
                }

                // Apro handle unico
                $logHandle = $this->openLogHandle($logFile);

                // Header nel file (newline garantiti)
                $this->writeLogLine($logHandle, "=== COMMAND EXECUTION START {$command['id']} ===");
                $this->writeLogLine($logHandle, $memoryInfo);

                $descriptorspec = [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'], // catturiamo stdout del processo
                    2 => ['pipe', 'w'], // catturiamo stderr del processo
                ];

                // Evito colori/escape nei log (facultativo)
                putenv('FORCE_COLOR=0');
                putenv('TERM=dumb');

                $process = proc_open('echo -1 | ' . $command['command'], $descriptorspec, $pipes, null, null);

                if (!is_resource($process)) {
                    throw new \Exception('Failed to start process');
                }

                // non bloccare i pipe
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);

                // Loop di lettura: scrive SOLO su file, mantiene TAIL minima in RAM
                while (true) {
                    if ($this->checkShutdownTimeout($process)) {
                        break;
                    }

                    $reads = [$pipes[1], $pipes[2]];
                    $writes = null;
                    $excepts = null;
                    $result = @stream_select($reads, $writes, $excepts, 1);

                    if ($result === false && !$this->shouldExit) {
                        continue;
                    }
                    if ($result === 0) {
                        // nessun dato
                        // verifica se i pipe sono chiusi
                        if (feof($pipes[1]) && feof($pipes[2])) {
                            break;
                        }
                        continue;
                    }

                    $done = true;
                    foreach ($reads as $pipe) {
                        $chunk = fread($pipe, 8192);
                        if ($chunk === false || $chunk === '' || feof($pipe)) {
                            continue;
                        }
                        $done = false;

                        if ($pipe === $pipes[1]) {
                            // STDOUT
                            $this->writeLogChunk($logHandle, $chunk);
                            $this->keepTail($tailStdout, $chunk, $this->tailLimitBytes);
                        } else {
                            // STDERR
                            $this->writeLogChunk($logHandle, $chunk);
                            $this->keepTail($tailStderr, $chunk, $this->tailLimitBytes);
                        }
                    }

                    if ($done && feof($pipes[1]) && feof($pipes[2])) {
                        break;
                    }
                }

                // Chiudo pipe
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }

                $returnValue = proc_close($process);
                $executionEnd = date('Y-m-d H:i:s');

                $finalMemory = $this->getMemoryUsage();

                // Summary nel file
                $summary = "\n=== EXECUTION SUMMARY ===\n";
                $summary .= "Completed at: $executionEnd\n";
                $summary .= 'Status: ' . ($returnValue === 0 ? 'SUCCESS' : 'ERROR') . "\n";
                $summary .= "Memory at end: Current: {$finalMemory['current']}MB, Real: {$finalMemory['current_real']}MB\n";
                $summary .= 'Memory change during execution: ' . round($finalMemory['current'] - $memory['current'], 2) . "MB\n";
                $summary .= "Total memory leak since start: {$finalMemory['leak']}MB\n";
                $this->writeLogChunk($logHandle, $summary);

                // Se durante shutdown abbiamo sforato timeout, marchio fatal e chiedo uscita dal loop
                if ($this->shouldExit && $this->checkShutdownTimeout()) {
                    if ($spool) {
                        $spool->completed = -1;
                        $spool->completed_at = new \yii\db\Expression('NOW()');
                        $spool->logs = $tailStdout;  // solo TAIL
                        $spool->errors = $tailStderr;
                        $spool->result = 'fatal';
                        $spool->logs_file = $logFile;
                        $spool->save(false);
                    }
                    $endtime = time();
                    $this->updateCronJob($command, $endtime - $startTime, 'ML');
                    $this->sendErrorEmail($command['command'], 'Shutdown timeout reached', $tailStdout, $logFile, $tailStderr);
                    $this->notification($command['command'], 'Shutdown timeout reached', $tailStdout, $logFile, $tailStderr);

                    $loopBreakRequested = true; // <== NON break qui, lo faremo dopo il finally
                } else {
                    // Aggiornamento record spool di esito (solo tail)
                    if ($spool) {
                        $spool->completed = 1;
                        $spool->completed_at = new \yii\db\Expression('NOW()');
                        $spool->logs = $tailStdout;
                        $spool->errors = $tailStderr;
                        $spool->result = $returnValue === 0 ? 'success' : 'error';
                        $spool->logs_file = $logFile;
                        $spool->save(false);
                    }

                    $endtime = time();
                    $executionTime = $endtime - $startTime;
                    $exitCodeStr = $returnValue === 0 ? 'OK' : 'KO';
                    $this->updateCronJob($command, $executionTime, $exitCodeStr);

                    if ($exitCodeStr === 'KO') {
                        $this->sendErrorEmail($command['command'], $tailStderr, $tailStdout, $logFile, $tailStderr);
                        $this->notification($command['command'], $tailStderr, $tailStdout, $logFile, $tailStderr);
                    }

                    $this->commandsExecuted++;

                    $postExecMemory = $this->getMemoryUsage();
                    LogHelper::log('info', "Post-command memory - Current: {$postExecMemory['current']}MB, Leak: {$postExecMemory['leak']}MB");
                }

            } catch (\Throwable $e) {
                LogHelper::log('error', "Error processing command {$command['id']}: " . $e->getMessage());

                if (is_resource($logHandle)) {
                    $this->writeLogLine($logHandle, "\n=== ERROR ===");
                    $this->writeLogLine($logHandle, $e->getMessage());
                } elseif (isset($logFile)) {
                    @file_put_contents($logFile, "\n=== ERROR ===\n" . $e->getMessage() . "\n", FILE_APPEND);
                }

                $spool = CommandsSpool::findOne($command['id']);
                if ($spool) {
                    $spool->completed = 1;
                    $spool->completed_at = new \yii\db\Expression('NOW()');
                    $spool->errors = $e->getMessage();
                    $spool->result = 'error';
                    if (empty($spool->logs_file) && isset($logFile)) {
                        $spool->logs_file = $logFile;
                    }
                    $spool->save(false);
                }

                $endtime = time();
                $this->updateCronJob($command, $endtime - $startTime, 'KO');
                $this->sendErrorEmail($command['command'], $e->getMessage(), isset($tailStdout) ? $tailStdout : '', isset($logFile) ? $logFile : '(n/a)', isset($tailStderr) ? $tailStderr : '');
                $this->notification($command['command'], $e->getMessage(), isset($tailStdout) ? $tailStdout : '', isset($logFile) ? $logFile : '(n/a)', isset($tailStderr) ? $tailStderr : '');

            } finally {
                // SOLO cleanup. NIENTE break/continue/return/exit qui (PHP 8.2)
                if (is_resource($logHandle)) {
                    fclose($logHandle);
                }
                $this->currentCommand = null;
                unset($tailStdout, $tailStderr, $process, $pipes, $logHandle);
                $this->cleanupResources();
            }

            // === controllo loop DOPO il finally ===
            if ($this->checkShutdownTimeout()) {
                break;
            }
            if ($loopBreakRequested || !$this->running) {
                break;
            }

            sleep(1);
        }

        // Final cleanup
        $this->cleanupResources();
        $finalMemory = $this->getMemoryUsage();
        LogHelper::log('info', "Service shutting down. Final memory state - Current: {$finalMemory['current']}MB, Total leak: {$finalMemory['leak']}MB");

        return ExitCode::OK;
    }

    /**
     * Clean up old completed commands
     * @param int $days Number of days to keep completed commands
     * @return int Exit code
     */
    public function actionCleanup($days = 30)
    {
        $this->stdout("Cleaning up commands older than $days days...\n");

        $affected = Yii::$app->db->createCommand("
            DELETE FROM commands_spool 
            WHERE completed = 1 
              AND completed_at < DATE_SUB(NOW(), INTERVAL :days DAY)
        ", [':days' => $days])->execute();

        $this->stdout("Cleaned up $affected commands.\n");
        return ExitCode::OK;
    }

    private function updateCronJob(array $command, int $seconds = null, $exit_code = null)
    {
        if ($command['provenience'] !== 'cron_job' || empty($command['provenience_id'])) {
            return;
        }
        Yii::$app->db->createCommand()->update('cron_job', [
            'execution_time' => $seconds,
            'last_execution' => new Expression('NOW()'),
            'exit_code' => $exit_code,
        ], ['id' => $command['provenience_id']])->execute();
        LogHelper::log('info', "cron_job #{$command['provenience_id']} updated: time={$seconds}s");
    }

    /**
     * Invia email con solo tail + percorso del log completo.
     * $errorOutputTail è usato come corpo errore (o messaggio di eccezione),
     * $executionOutputTail è la tail di stdout; $logFile indica dove leggere il log completo.
     */
    private function sendErrorEmail($command, $errorOutputTail, $executionOutputTail, $logFile, $stderrTail = '')
    {
        $to = isset(Yii::$app->params['NotificationsEmail']) ? Yii::$app->params['NotificationsEmail'] : null;
        LogHelper::log("error", "SEND EMAIL TO ".$to." Error executing command: $command\nSTDERR (tail): $errorOutputTail\nSTDOUT (tail): $executionOutputTail\nLOG: $logFile");

        $body = "Si è verificato un errore durante l'esecuzione del comando: $command\n\n"
            . "Dettagli (TAIL):\n--- STDERR ---\n$errorOutputTail\n\n--- STDOUT ---\n$executionOutputTail\n\n"
            . "Log completo: $logFile\n";

        Yii::$app->mailer->compose()
            ->setTo($to)
            ->setFrom([Yii::$app->params['senderEmail'] => Yii::$app->name])
            ->setSubject('Errore di esecuzione del Cron Job ' . $command)
            ->setTextBody($body)
            ->send();
    }

    /**
     * Notifica applicativa con solo tail + percorso del log completo.
     */
    private function notification($command, $errorOutputTail, $executionOutputTail, $logFile, $stderrTail = '')
    {
        LogHelper::log("error", "SEND NOTIFICATION SO Error executing command: $command\nSTDERR (tail): $errorOutputTail\nSTDOUT (tail): $executionOutputTail\nLOG: $logFile");
        $moduleID = isset($moduleID) ? $moduleID : Yii::$app->controller->module->id;
        $module = Yii::$app->getModule($moduleID);

        if ($module && $module->has('customNotification')) {
            $notificationComponent = $module->get('customNotification');
            $title = "Errore esecuzione cronjob $command";
            $description = "Si è presentato un errore durante l'esecuzione del cronjob $command il " . date('d/m/Y') . ' alle ' . date('H:i:s');

            $payload = "Command: $command\n\n--- STDERR (tail) ---\n$errorOutputTail\n\n--- STDOUT (tail) ---\n$executionOutputTail\n\nLog completo: $logFile";
            $notificationComponent->send($title, $description, 'cron-jobs', 'error', $payload);
        } else {
            // opzionale: warning nel log app
            // Yii::warning('Componente di notifica personalizzato non configurato.');
        }
    }
}
