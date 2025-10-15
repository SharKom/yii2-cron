<?php

namespace sharkom\cron\commands;

date_default_timezone_set("europe/rome");

use sharkom\devhelper\LogHelper;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\db\Expression;
use sharkom\cron\models\CommandsSpool;
use Yii;

class SpoolerController extends Controller
{
    private $defaultServiceName = 'yii2-spooler';
    private $systemdUnit;
    private $serviceConfigFile;
    private $running = true;
    private $currentCommand = null;
    private $initialMemory = 0;
    private $echoProcessOutput = false;

    private $maxMemoryLeak = 50;     // MB - limite massimo di leak consentito
    private $maxCommandsBeforeRestart = 100;  // numero massimo di comandi prima del restart
    private $restartMemoryThreshold = 50;    // MB - soglia di leak per forzare restart
    private $commandsExecuted = 0;
    private $shouldExit = false;
    private $shutdownStartTime = null;
    private $shutdownTimeout = 10; // secondi, corrisponde al TimeoutStopSec di systemd

    public function init()
    {
        parent::init();
        $this->serviceConfigFile = Yii::getAlias('@runtime/spooler-service.conf');
    }

    /**
     * Garantisce che il file di log sia pronto: crea la cartella e il file se mancano.
     */
    private function ensureLogFile(string $logFile): void
    {
        $dir = dirname($logFile);
        // PHP8 compatible: proper error handling instead of suppression
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $dir));
            }
        }
        if (!file_exists($logFile)) {
            if (!touch($logFile)) {
                throw new \RuntimeException(sprintf('Log file "%s" could not be created', $logFile));
            }
            chmod($logFile, 0664);
        }
    }

    /**
     * Scrive righe nel file di log (append, con lock per evitare interleaving).
     */
    private function appendLog(string $logFile, string $text): void
    {
        // PHP8 compatible: proper error handling instead of suppression
        $fh = fopen($logFile, 'ab');
        if ($fh) {
            flock($fh, LOCK_EX);
            fwrite($fh, $text);
            if (substr($text, -1) !== "\n") {
                fwrite($fh, "\n");
            }
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * Stampa condizionale sulla console solo se esplicitamente abilitata per i comandi processati.
     * (NON usiamo direttamente $this->stdout nella sezione comandi)
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
        // Verifica se abbiamo superato il numero massimo di comandi
        if ($this->commandsExecuted >= $this->maxCommandsBeforeRestart) {
            LogHelper::log("warning", "Reached maximum commands limit ({$this->maxCommandsBeforeRestart}). Triggering restart.");
            return true;
        }

        // Verifica se il leak di memoria ha superato la soglia
        if ($memory['leak'] >= $this->restartMemoryThreshold) {
            LogHelper::log("warning", "Memory leak ({$memory['leak']}MB) exceeded threshold ({$this->restartMemoryThreshold}MB). Triggering restart.");
            return true;
        }

        // Verifica se abbiamo superato il limite massimo di leak
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

        // Preparazione comando di restart
        $scriptName = Yii::getAlias('@app/yii');
        $command = $this->getServiceName();

        // Log dello stato finale prima del restart
        $finalMemory = $this->getMemoryUsage();
        LogHelper::log("info", "Final memory state before restart - Current: {$finalMemory['current']}MB, Leak: {$finalMemory['leak']}MB");

        // Esegui il restart usando systemctl
        $serviceName = $this->getServiceName();
        LogHelper::log("info", "Requesting service restart through systemd...");

        // Richiedi il restart del servizio
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
        // Clear PHP internal cache
        clearstatcache();

        // Close any open database connections
        Yii::$app->db->close();

        // Force immediate garbage collection
        gc_collect_cycles();

        // Reset PHP's internal memory limit (if needed)
        gc_mem_caches();
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

            // Se c'è un processo attivo, terminalo
            // PHP8 compatible: proc_open returns Process object, not resource
            if ($process && $process !== false) {
                // Invia SIGTERM al processo
                proc_terminate($process, SIGTERM);

                // Aspetta brevemente che si chiuda
                $waitStart = time();
                while (time() - $waitStart < 5) {
                    $status = proc_get_status($process);
                    if (!$status['running']) {
                        break;
                    }
                    usleep(100000); // 0.1 secondi
                }

                // Se è ancora in esecuzione, forza la chiusura
                $status = proc_get_status($process);
                if ($status['running']) {
                    proc_terminate($process, SIGKILL);
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
            return trim(file_get_contents($this->serviceConfigFile));
        }
        return $this->defaultServiceName;
    }

    /**
     * Save service name to config
     */
    private function saveServiceName($name)
    {
        file_put_contents($this->serviceConfigFile, $name);
    }


    /**
     * Write to log file in real-time
     */
    private function writeToLog($logFile, $content, $mode = 'a')
    {
        file_put_contents($logFile, $content . "\n", FILE_APPEND);
    }

    /**
     * Process pending commands in the commands_spool table
     * @return int Exit code
     */
    /**
     * Process pending commands in the commands_spool table
     * @return int Exit code
     */
    public function actionProcess()
    {
        $this->initialMemory = memory_get_usage(true);

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, [$this, 'handleShutdown']);
        pcntl_signal(SIGINT, [$this, 'handleShutdown']);

        // Questi log usano LogHelper (log di controller/servizio), non stdout.
        LogHelper::log('info', 'Starting spool processing with memory limits:');
        LogHelper::log('info', "- Max memory leak: {$this->maxMemoryLeak}MB");
        LogHelper::log('info', "- Restart threshold: {$this->restartMemoryThreshold}MB");
        LogHelper::log('info', "- Max commands before restart: {$this->maxCommandsBeforeRestart}");

        $iterationCount = 0;
        $lastMemory = 0;

        $conn = Yii::$app->db;

        // Recupero comandi interrotti: se hanno logs_file valorizzato, provo a recuperarne la coda
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
                $handle = fopen($logFile, 'r');
                if ($handle) {
                    $startFound = false;
                    $logsBuffer = [];
                    while (($line = fgets($handle)) !== false) {
                        if (!$startFound) {
                            if (strpos($line, $targetStart) !== false) {
                                $startFound = true;
                                $logsBuffer[] = $line;
                            }
                        } else {
                            $logsBuffer[] = $line;
                            if (count($logsBuffer) > 1000) {
                                break;
                            }
                        }
                    }
                    fclose($handle);
                    if ($startFound) {
                        $logs = implode('', $logsBuffer);
                    }
                }
            }

            $spool = CommandsSpool::findOne($interrupted['id']);
            if ($spool) {
                $spool->result = 'fatal';
                $spool->completed = -1;
                $spool->logs = "Informazioni recuperate dai log dopo la chiusura imprevista del processo: \n\n---------------------------------\n" . $logs;
                $spool->save(false);
            }
        }

        while ($this->running) {
            $iterationCount++;

            // Monitoraggio memoria (solo su LogHelper, non stdout)
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
                // NON scrivo su stdout, procedo direttamente al restart
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
            try {
                $this->currentCommand = $command;

                // segno l'avvio
                $spool = CommandsSpool::findOne($command['id']);
                if ($spool) {
                    $spool->executed_at = new \yii\db\Expression('NOW()');
                    $spool->save(false);
                }

                // Risolvo log file (default + aggiornamento immediato nello spooler se non presente)
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

                // Niente stdout: traccio l’header direttamente nel file
                $this->appendLog($logFile, "=== COMMAND EXECUTION START {$command['id']} ===");
                $this->appendLog($logFile, $memoryInfo . "\n");

                $stdout = '';
                $stderr = '';

                $descriptorspec = [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'], // catturiamo stdout del processo
                    2 => ['pipe', 'w'], // catturiamo stderr del processo
                ];

                // Evito colori/escape nei log (facultativo)
                putenv('FORCE_COLOR=0');
                putenv('TERM=dumb');

                $process = proc_open('echo -1 | ' . $command['command'], $descriptorspec, $pipes, null, null);

                // PHP8 compatible: proc_open returns Process object or false
                if ($process === false) {
                    throw new \Exception('Failed to start process');
                }

                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);

                $startTime = time();

                // Loop di lettura: scrive SOLO su file, MAI su stdout
                while (true) {
                    // prima: gestione shutdown
                    if ($this->checkShutdownTimeout($process)) {
                        break;
                    }

                    $reads = [$pipes[1], $pipes[2]];
                    $writes = null;
                    $excepts = null;
                    $result = @stream_select($reads, $writes, $excepts, 1);

                    if ($result === false && !$this->shouldExit) {
                        // EINTR ecc: provo a continuare
                        continue;
                    }
                    if ($result === 0) {
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
                            $stdout .= $chunk;
                            $this->appendLog($logFile, rtrim($chunk, "\0"));
                        } else {
                            $stderr .= $chunk;
                            $this->appendLog($logFile, rtrim($chunk, "\0"));
                        }
                    }

                    if ($done && feof($pipes[1]) && feof($pipes[2])) {
                        break;
                    }
                }

                // Chiudo pipe
                // PHP8 compatible: check pipes are valid before closing
                foreach ($pipes as $pipe) {
                    if ($pipe !== false && is_resource($pipe)) {
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
                $this->appendLog($logFile, $summary);

                // Se durante shutdown abbiamo sforato timeout, marchio fatal e STOP
                if ($this->shouldExit && $this->checkShutdownTimeout()) {
                    if ($spool) {
                        $spool->completed = -1;
                        $spool->completed_at = new \yii\db\Expression('NOW()');
                        $spool->logs = $stdout;
                        $spool->errors = $stderr;
                        $spool->result = 'fatal';
                        $spool->logs_file = $logFile; // già valorizzato prima, ma ok aggiornarlo
                        $spool->save(false);
                    }
                    $endtime = time();
                    $this->updateCronJob($command, $endtime - $startTime, 'ML');
                    $this->sendErrorEmail($command['command'], 'Shutdown timeout reached', $stdout);
                    $this->notification($command['command'], 'Shutdown timeout reached', $stdout);
                    break;
                }

                // Aggiornamento record spool di esito (NB: NON scrivo nulla su stdout)
                if ($spool) {
                    $spool->completed = 1;
                    $spool->completed_at = new \yii\db\Expression('NOW()');
                    $spool->logs = $stdout;
                    $spool->errors = $stderr;
                    $spool->result = $returnValue === 0 ? 'success' : 'error';
                    $spool->logs_file = $logFile;
                    $spool->save(false);
                }

                $endtime = time();
                $executionTime = $endtime - $startTime;
                $exitCodeStr = $returnValue === 0 ? 'OK' : 'KO';
                $this->updateCronJob($command, $executionTime, $exitCodeStr);

                if ($exitCodeStr === 'KO') {
                    $this->sendErrorEmail($command['command'], $stderr, $stdout);
                    $this->notification($command['command'], $stderr, $stdout);
                }

                $this->commandsExecuted++;

                $postExecMemory = $this->getMemoryUsage();
                LogHelper::log('info', "Post-command memory - Current: {$postExecMemory['current']}MB, Leak: {$postExecMemory['leak']}MB");

            } catch (\Exception $e) {
                LogHelper::log('error', "Error processing command {$command['id']}: " . $e->getMessage());

                if (isset($logFile)) {
                    $this->appendLog($logFile, "\n=== ERROR ===\n" . $e->getMessage());
                }

                $spool = CommandsSpool::findOne($command['id']);
                if ($spool) {
                    $spool->completed = 1;
                    $spool->completed_at = new \yii\db\Expression('NOW()');
                    $spool->errors = $e->getMessage();
                    $spool->result = 'error';
                    // se avevo creato il default, rimane valorizzato
                    if (empty($spool->logs_file) && isset($logFile)) {
                        $spool->logs_file = $logFile;
                    }
                    $spool->save(false);
                }

                $endtime = time();
                $this->updateCronJob($command, $endtime - $startTime, 'KO');
                $this->sendErrorEmail($command['command'], $e->getMessage(), isset($stdout) ? $stdout : '');
                $this->notification($command['command'], $e->getMessage(), isset($stdout) ? $stdout : '');
            }

            // Cleanup
            $this->currentCommand = null;
            unset($command, $stdout, $stderr, $process, $pipes);
            $this->cleanupResources();

            if ($this->checkShutdownTimeout()) {
                break;
            }
            if (!$this->running) {
                // Non stampo su stdout, chiudo in silenzio la sezione di esecuzione
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

    private function updateCronJob(array $command, int $seconds = null, $exit_code)
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

    private function sendErrorEmail($command, $errorOutput, $executionOutput)
    {
        LogHelper::log("error", "SEND EMAIL TO ".Yii::$app->params['NotificationsEmail']." Error executing command: $command\nError output: $errorOutput\nExecution output: $executionOutput");
        Yii::$app->mailer->compose()
            ->setTo(Yii::$app->params['NotificationsEmail'])
            ->setFrom([Yii::$app->params['senderEmail'] => Yii::$app->name])
            ->setSubject('Errore di esecuzione del Cron Job ' . $command)
            ->setTextBody("Si è verificato un errore durante l'esecuzione del comando: $command\n\nDettagli dell'errore:\n$errorOutput\n\nLog di esecuzione:\n$executionOutput")
            ->send();
    }

    private function notification($command,$errorOutput, $executionOutput){
        LogHelper::log("error", "SEND NOTIFICATION SO Error executing command: $command\nError output: $errorOutput\nExecution output: $executionOutput");
        $moduleID = $moduleID ?? Yii::$app->controller->module->id;
        $module = Yii::$app->getModule($moduleID);

        if ($module->has('customNotification')) {
            $notificationComponent = $module->get('customNotification');
            $title = "Errore esecuzione cronjob $command";
            $description = "Si è presentato un errore durante l'esecuzione del cronjob $command il " . date('d/m/Y') . ' alle ' . date('H:i:s');

            $notificationComponent->send($title, $description, 'cron-jobs', 'error', "$command \n\n$errorOutput \n\n$executionOutput");
        } else {
            // Gestisci l'errore in un altro modo o ignoralo se il componente non è impostato
            // Ad esempio, puoi scrivere un messaggio nel log dell'applicazione
            //Yii::warning('Il componente di notifica personalizzato non è impostato nel modulo corrente.');
        }
    }
}