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
            if ($process && is_resource($process)) {
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
     * Get systemd unit content
     */
    private function getSystemdUnit($serviceName)
    {
        $working_dir=str_replace("/console", "", Yii::getAlias("@app"));
        LogHelper::log("info", "WD: $working_dir");
        //die();
        return <<<EOT
[Unit]
Description=Yii2 Command Spooler Service ($serviceName)
After=network.target mysql.service

[Service]
KillMode=mixed
TimeoutStopSec=90
Type=simple
User=root
Group=root
ExecStart=/usr/bin/php $working_dir/yii cron/spooler/process
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
EOT;
    }

    /**
     * Install systemd service
     * @param string $serviceName Optional custom service name
     */
    public function actionInstall($serviceName = '')
    {
        if (empty($serviceName)) {
            if (file_exists($this->serviceConfigFile)) {
                $serviceName = $this->getServiceName();
                if (!$this->confirm("Found existing service name: $serviceName. Do you want to use it?")) {
                    $serviceName = $this->prompt('Enter new service name:', ['default' => $this->defaultServiceName]);
                }
            } else {
                $serviceName = $this->prompt('Enter service name:', ['default' => $this->defaultServiceName]);
            }
        }

        // Validate service name
        if (!preg_match('/^[a-zA-Z0-9\-_]+$/', $serviceName)) {
            $this->stderr("Error: Invalid service name. Use only letters, numbers, dashes and underscores.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Installing systemd service as '$serviceName'...\n");

        // Save service name
        $this->saveServiceName($serviceName);

        // Generate and write systemd unit file
        $unitPath = "/etc/systemd/system/{$serviceName}.service";
        $unitContent = $this->getSystemdUnit($serviceName);

        if (!file_put_contents($unitPath, $unitContent)) {
            $this->stderr("Error: Could not write systemd unit file\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        // Reload systemd
        exec('systemctl daemon-reload', $output, $returnVar);
        if ($returnVar !== 0) {
            $this->stderr("Error: Could not reload systemd\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Service installed successfully as '$serviceName'!\n");
        $this->stdout("Use 'yii spooler/enable' to enable the service\n");
        return ExitCode::OK;
    }

    /**
     * Remove systemd service
     */
    public function actionUninstall()
    {
        $serviceName = $this->getServiceName();

        if (empty($serviceName)) {
            $this->stderr("Error: No service name found. Was the service installed?\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Uninstalling systemd service '$serviceName'...\n");

        // Stop and disable service first
        exec("systemctl stop {$serviceName}", $output, $returnVar);
        exec("systemctl disable {$serviceName}", $output, $returnVar);

        // Remove unit file
        $unitPath = "/etc/systemd/system/{$serviceName}.service";
        if (file_exists($unitPath)) {
            unlink($unitPath);
        }

        // Remove service name config
        if (file_exists($this->serviceConfigFile)) {
            unlink($this->serviceConfigFile);
        }

        // Reload systemd
        exec('systemctl daemon-reload', $output, $returnVar);

        $this->stdout("Service '$serviceName' uninstalled successfully!\n");
        return ExitCode::OK;
    }

    /**
     * Enable systemd service
     */
    public function actionEnable()
    {
        $serviceName = $this->getServiceName();

        if (empty($serviceName)) {
            $this->stderr("Error: No service name found. Please install the service first.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Enabling service '$serviceName'...\n");
        exec("systemctl enable {$serviceName}", $output, $returnVar);

        if ($returnVar === 0) {
            $this->stdout("Service enabled successfully!\n");
            $this->stdout("Use 'yii spooler/start' to start the service\n");
            return ExitCode::OK;
        } else {
            $this->stderr("Error enabling service\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Disable systemd service
     */
    public function actionDisable()
    {
        $serviceName = $this->getServiceName();

        if (empty($serviceName)) {
            $this->stderr("Error: No service name found. Please install the service first.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Disabling service '$serviceName'...\n");
        exec("systemctl disable {$serviceName}", $output, $returnVar);

        if ($returnVar === 0) {
            $this->stdout("Service disabled successfully!\n");
            return ExitCode::OK;
        } else {
            $this->stderr("Error disabling service\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Start systemd service
     */
    public function actionStart()
    {
        $serviceName = $this->getServiceName();

        if (empty($serviceName)) {
            $this->stderr("Error: No service name found. Please install the service first.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Starting service '$serviceName'...\n");
        exec("systemctl start {$serviceName}", $output, $returnVar);

        if ($returnVar === 0) {
            $this->stdout("Service started successfully!\n");
            return ExitCode::OK;
        } else {
            $this->stderr("Error starting service\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Stop systemd service
     */
    public function actionStop()
    {
        $serviceName = $this->getServiceName();

        if (empty($serviceName)) {
            $this->stderr("Error: No service name found. Please install the service first.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Stopping service '$serviceName'...\n");
        exec("systemctl stop {$serviceName}", $output, $returnVar);

        if ($returnVar === 0) {
            $this->stdout("Service stopped successfully!\n");
            return ExitCode::OK;
        } else {
            $this->stderr("Error stopping service\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Show service status
     */
    public function actionStatus()
    {
        $serviceName = $this->getServiceName();

        if (empty($serviceName)) {
            $this->stderr("Error: No service name found. Please install the service first.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        exec("systemctl status {$serviceName}", $output, $returnVar);
        $this->stdout(implode("\n", $output) . "\n");
        return $returnVar;
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

        LogHelper::log("info", "Starting spool processing with memory limits:");
        LogHelper::log("info", "- Max memory leak: {$this->maxMemoryLeak}MB");
        LogHelper::log("info", "- Restart threshold: {$this->restartMemoryThreshold}MB");
        LogHelper::log("info", "- Max commands before restart: {$this->maxCommandsBeforeRestart}");

        $iterationCount = 0;
        $lastMemory = 0;
 
        $conn = Yii::$app->db;

        //prendo tutti i comandi rimasti in sospeso
        $res=$conn->createCommand("select * from commands_spool where completed=0 AND executed_at is not null")->queryAll();

        foreach ($res as $interrupted) {
            $logFile = $interrupted["logs_file"];
            $targetStart = "=== COMMAND EXECUTION START {$interrupted['id']} ===\n";
            $logs = null;

            if (is_readable($logFile)) {
                $handle = fopen($logFile, "r");
                if ($handle) {
                    $startFound = false;
                    $logsBuffer = [];

                    while (($line = fgets($handle)) !== false) {
                        if (!$startFound) {
                            // Cerca il punto di inizio
                            if (strpos($line, $targetStart) !== false) {
                                $startFound = true;
                                $logsBuffer[] = $line;
                            }
                        } else {
                            // Dopo aver trovato il punto di inizio, accumula il log
                            $logsBuffer[] = $line;
                        }

                        // Condizione per interrompere la lettura se necessario
                        if ($startFound && count($logsBuffer) > 1000) {
                            // Ad esempio, leggi solo 1000 righe dopo il match
                            break;
                        }
                    }
                    fclose($handle);

                    if ($startFound) {
                        $logs = implode("", $logsBuffer);
                    }
                }
            }
            /*
            $conn->createCommand()->update(
                "commands_spool",
                [
                    "result" => "fatal",
                    "completed" => -1,
                    "logs" => "Informazioni recuperate dai log dopo la chiusura imprevista del processo: \n\n---------------------------------\n" . $logs
                ],
                "id={$interrupted["id"]}"
            )->execute();*/

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

            // Monitoraggio memoria
            $memory = $this->getMemoryUsage();
            $memoryChange = $lastMemory > 0 ? sprintf("(%+.2fMB)", $memory['current'] - $lastMemory) : "";
            $memoryInfo = sprintf(
                "Iteration %d - Memory: Current: %sMB %s, Real: %sMB, Peak: %sMB, Leak: %sMB",
                $iterationCount,
                $memory['current'],
                $memoryChange,
                $memory['current_real'],
                $memory['peak'],
                $memory['leak']
            );

            //LogHelper::log("info", $memoryInfo);
            $this->stdout($memoryInfo . "\n");

            // Verifica se è necessario un restart
            if ($this->shouldRestart($memory)) {
                LogHelper::log("warning", "Restart condition met after $iterationCount iterations.");
                $this->stdout("Memory safety limit reached. Initiating restart...\n");
                $this->restartProcess();
            }

            $lastMemory = $memory['current'];

            // Pulizia risorse
            $this->cleanupResources();

            // Cerca il prossimo comando
            $command = Yii::$app->db->createCommand("
                SELECT * FROM commands_spool 
                WHERE completed = 0 
                AND executed_at IS NULL
                ORDER BY created_at ASC 
                LIMIT 1
            ")->queryOne();

            if (!$command) {
                if (!$this->running) {
                    break;
                }
                sleep(5);
                continue;
            }

            // Esecuzione comando
            try {
                $this->currentCommand = $command;
                $this->stdout("Processing command ID {$command['id']}: {$command['command']}\n");

                // Mark as being executed
                $executionStart = date('Y-m-d H:i:s');
                /*
                Yii::$app->db->createCommand()->update('commands_spool', [
                    'executed_at' => new Expression('NOW()')
                ], ['id' => $command['id']])->execute();
                */

                $spool = CommandsSpool::findOne($command['id']);
                if ($spool) {
                    $spool->executed_at = new \yii\db\Expression('NOW()');
                    $spool->save(false);
                }


                $logFile = !empty($command["logs_file"]) ? $command["logs_file"] : Yii::getAlias("@runtime/logs/command_{$command["provenience"]}_{$command["provenience_id"]}.log");

                $stdout = '';
                $stderr = '';

                $descriptorspec = [
                    0 => ["pipe", "r"],
                    1 => ["pipe", "w"],
                    2 => ["pipe", "w"]
                ];

                putenv('FORCE_COLOR=1');
                putenv('TERM=xterm-256color');

                $process = proc_open("echo -1 | ".$command['command'], $descriptorspec, $pipes, null, null);

                if (is_resource($process)) {
                    stream_set_blocking($pipes[1], false);
                    stream_set_blocking($pipes[2], false);
                    $startTime=time();
                    file_put_contents($logFile, "=== COMMAND EXECUTION START {$command['id']} ===\n" . $memoryInfo . "\n\n", FILE_APPEND);

                    while (true) {
                        $reads = [$pipes[1], $pipes[2]];
                        $writes = null;
                        $excepts = null;

                        // Controllo timeout shutdown prima di stream_select
                        if ($this->checkShutdownTimeout($process)) {
                            break;
                        }

                        // Gestione interruzione con timeout breve per controllare i segnali
                        $result = @stream_select($reads, $writes, $excepts, 1);

                        // Gestione interruzione da segnale
                        if ($result === false && !$this->shouldExit) {
                            if (errno() == EINTR) {
                                continue;
                            }
                            break;
                        }

                        // Controllo se è stato richiesto lo shutdown
                        if ($this->shouldExit) {
                            // Logga il tempo rimanente prima del timeout
                            if ($this->shutdownStartTime !== null) {
                                $remainingTime = $this->shutdownTimeout - (time() - $this->shutdownStartTime);
                                LogHelper::log("info", "Shutdown in progress. Remaining time: {$remainingTime} seconds");
                            }
                        }

                        if ($result === 0) { // Timeout di stream_select
                            continue;
                        }

                        $done = true;
                        foreach ($reads as $pipe) {
                            $chunk = fread($pipe, 8192);
                            if ($chunk === false || feof($pipe)) {
                                continue;
                            }
                            $done = false;

                            if ($pipe === $pipes[1]) {
                                $stdout .= $chunk;
                                file_put_contents($logFile, $chunk, FILE_APPEND);
                            } else {
                                $stderr .= $chunk;
                                file_put_contents($logFile, $chunk, FILE_APPEND);
                            }
                        }

                        if ($done && feof($pipes[1]) && feof($pipes[2])) {
                            break;
                        }
                    }

                    // Chiusura pipes
                    foreach ($pipes as $pipe) {
                        if (is_resource($pipe)) {
                            fclose($pipe);
                        }
                    }



                    $returnValue = proc_close($process);
                    $executionEnd = date('Y-m-d H:i:s');

                    // Get final memory state
                    $finalMemory = $this->getMemoryUsage();

                    // Write execution summary
                    $summary = "\n=== EXECUTION SUMMARY ===\n";
                    $summary .= "Completed at: $executionEnd\n";
                    $summary .= "Status: " . ($returnValue === 0 ? "SUCCESS" : "ERROR") . "\n";
                    $summary .= "Memory at end: Current: {$finalMemory['current']}MB, Real: {$finalMemory['current_real']}MB\n";
                    $summary .= "Memory change during execution: " .
                        round($finalMemory['current'] - $memory['current'], 2) . "MB\n";
                    $summary .= "Total memory leak since start: {$finalMemory['leak']}MB\n";

                    file_put_contents($logFile, $summary, FILE_APPEND);

                    // Se siamo in shutdown e il processo è ancora attivo, controlliamo il timeout
                    if ($this->shouldExit) {
                        if ($this->checkShutdownTimeout()) {
                            /*
                            Yii::$app->db->createCommand()->update('commands_spool', [
                                'completed' => -1,
                                'completed_at' => new Expression('NOW()'),
                                'logs' => $stdout,
                                'errors' => $stderr,
                                'result' => 'fatal',
                                'logs_file' => $logFile
                            ], ['id' => $command['id']])->execute();
                            */

                            $spool = CommandsSpool::findOne($command['id']);
                            if ($spool) {
                                $spool->completed = -1;
                                $spool->completed_at = new \yii\db\Expression('NOW()');
                                $spool->logs = $stdout;
                                $spool->errors = $stderr;
                                $spool->result = 'fatal';
                                $spool->logs_file = $logFile;
                                $spool->save(false);
                            }


                            $endtime=time();
                            $executionTime=$endtime-$startTime;

                            $this->updateCronJob($command, $executionTime, "ML");
                            $this->sendErrorEmail($command['command'], "Shutdown timeout reached", $stdout);
                            $this->notification($command['command'], "Shutdown timeout reached", $stdout);
                            break;
                        }
                    }

                    // Update database
                    /*
                    Yii::$app->db->createCommand()->update('commands_spool', [
                        'completed' => 1,
                        'completed_at' => new Expression('NOW()'),
                        'logs' => $stdout,
                        'errors' => $stderr,
                        'result' => $returnValue === 0 ? 'success' : 'error',
                        'logs_file' => $logFile
                    ], ['id' => $command['id']])->execute();
                    */

                    $spool = CommandsSpool::findOne($command['id']);
                    if ($spool) {
                        $spool->completed = 1;
                        $spool->completed_at = new \yii\db\Expression('NOW()');
                        $spool->logs = $stdout;
                        $spool->errors = $stderr;
                        $spool->result = $returnValue === 0 ? 'success' : 'error';
                        $spool->logs_file = $logFile;
                        $spool->save(false);
                    }


                    $endtime=time();
                    $executionTime=$endtime-$startTime;
                    $exitCode=$returnValue === 0 ? "OK" : "KO";
                    $this->updateCronJob($command, $executionTime, $exitCode);
                    if($exitCode=="KO") {
                        $this->sendErrorEmail($command['command'], $stderr, $stdout);
                        $this->notification($command['command'], $stderr, $stdout);
                    }

                } else {
                    throw new \Exception("Failed to start process");
                }

                $this->commandsExecuted++;

                // Log della memoria dopo ogni comando
                $postExecMemory = $this->getMemoryUsage();
                LogHelper::log("info", "Post-command memory - Current: {$postExecMemory['current']}MB, Leak: {$postExecMemory['leak']}MB");

            } catch (\Exception $e) {
                // Log error and update command status
                LogHelper::log("error", "Error processing command {$command['id']}: " . $e->getMessage());

                if (isset($logFile)) {
                    file_put_contents($logFile, "\n=== ERROR ===\n" . $e->getMessage() . "\n", FILE_APPEND);
                }

                /*
                Yii::$app->db->createCommand()->update('commands_spool', [
                    'completed' => 1, 
                    'completed_at' => new Expression('NOW()'),
                    'errors' => $e->getMessage(),
                    'result' => 'error'
                ], ['id' => $command['id']])->execute();
                */
                $spool = CommandsSpool::findOne($command['id']);
                if ($spool) {
                    $spool->completed = 1;
                    $spool->completed_at = new \yii\db\Expression('NOW()');
                    $spool->errors = $e->getMessage();
                    $spool->result = 'error';
                    $spool->save(false);
                }


                $endtime=time();
                $executionTime=$endtime-$startTime;
                $this->updateCronJob($command, $executionTime, "KO");
                $this->sendErrorEmail($command['command'], $e->getMessage(), $stdout);
                $this->notification($command['command'], $e->getMessage(), $stdout);
            }

            // Cleanup after command execution
            $this->currentCommand = null;
            unset($command);
            unset($stdout);
            unset($stderr);
            unset($process);
            unset($pipes);

            $this->cleanupResources();

            if ($this->checkShutdownTimeout()) {
                break;
            }

            if (!$this->running) {
                $this->stdout("Shutdown requested. Stopping after completing current command.\n");
                break;
            }

            sleep(1);
        }

        // Final cleanup
        $this->cleanupResources();

        $finalMemory = $this->getMemoryUsage();
        LogHelper::log("info", "Service shutting down. Final memory state - Current: {$finalMemory['current']}MB, Total leak: {$finalMemory['leak']}MB");

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