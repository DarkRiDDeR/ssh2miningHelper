<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/MinerInterface.php';

use Monolog\Logger; // The Logger instance
use Monolog\Handler\StreamHandler; // The StreamHandler sends log messages to a file on your disk
use Monolog\Level;
use phpseclib3\Net\SSH2;

abstract class MinerAbstract implements MinerInterface {
    const MINER_NAMES = ['xmrig', 'cpuminer', 'cpuminer-sse2']; // names of miners processes
    const OS_WINDOWS = 'WIN';
    const OS_LINUX = 'LINUX';
    const CPU_AMD = 'AMD';
    const CPU_INTEL = 'INTEL';
    const LOGGER_LEVEL = LOGGER_LEVEL; // LOGGER_LEVEL - config constant
    protected $os = self::OS_LINUX;
    protected $name = ''; // name of miner process
    protected $host = '';
    protected phpseclib3\Net\SSH2 $ssh;
    protected Monolog\Logger $logger;
    protected $password, $path, $logPath;

    public function __construct( phpseclib3\Net\SSH2 $ssh, string $host, string $password, string $path, string $logPath, $os = self::OS_LINUX ) {
        $this->path = $path;
        $this->password = $password;
        $this->logPath = $logPath;
        $this->host = $host;
        $this->os = strtoupper(trim($os)) == self::OS_WINDOWS ? self::OS_WINDOWS : self::OS_LINUX;
        $this->ssh = $ssh;
        $this->name = $this->getMinerProcessName($this->os);
        if (!$this->name) {
            throw new Error('Class "' . __CLASS__ . '" for managing the miner does not have a miner process name (const MINER_NAMES). OS ' . $this->os);
        }
        $this->logger = new Logger($this->name);
        $streamHandler = new StreamHandler("./log/{$this->name}.log", self::LOGGER_LEVEL);
        $this->logger->pushHandler($streamHandler);
        $this->logger->debug("$host Create miner", ["OS" => $this->os, "Path" => $path, "Log" => $logPath]);
    }

    static function getMinerProcessName(string $os = ''): string
    {
        return '';
    }

    /**
     * result: 'AMD', 'INTEL', ''
     */
    public function getCpuFamily (): string
    {
        if ($this->os == self::OS_WINDOWS) {
            $command = "wmic CPU get NAME";
        } else {
            $command = "echo $( timeout 1 echo '' | lscpu | grep Vendor)";
        }
        $output = self::execWithLogger($this->ssh, $command, $this->logger, "$this->host ".__FUNCTION__.' Exec');

        if(stripos($output, 'AMD') !== false){
            return self::CPU_AMD;
        } else if(stripos($output, 'Intel') !== false){
            return self::CPU_INTEL;
        }
        return '';
    }

    /**
     * @return false | int
     */
    static function detectProcess ( phpseclib3\Net\SSH2 $ssh, string $host, string $password, ?string $processName, string $os = self::OS_LINUX )
    {
        if (!$processName) {
            $processName = self::getMinerProcessName();
        }
        $logger = new Logger(__CLASS__);
        $streamHandler = new StreamHandler("./log/{".__CLASS__."}.log", self::LOGGER_LEVEL);
        $logger->pushHandler($streamHandler);
    
        $matches =[];
        if (strtoupper(trim($os)) == self::OS_WINDOWS) {
            $command = 'tasklist /FI "IMAGENAME eq ' . $processName . '.exe"';
            $output = self::execWithLogger($ssh, $command, $logger, "$host ".__FUNCTION__.' Exec');
            if ($output !== false && preg_match("/\s{$processName}\.exe\s+(\d+)/i", $output, $matches)) {
                return $matches[1];
            }
        } else {
            $command = "echo $( timeout 1 echo '$password' | sudo -S screen -ls | grep " . $processName . " )";
            $output = self::execWithLogger($ssh, $command, $logger, "$host ".__FUNCTION__.' Exec');
            if ($output !== false && preg_match("/\s*(\d+)\.{$processName}\s+/imu", $output, $matches)) {
                return $matches[1];
            }
        }
        return false;
    }


    /**
     * Kill all miners
     */
    public function killAllMiners ( ): bool
    {
        $command = '';
        if ($this->os == self::OS_WINDOWS) {
            foreach( self::MINER_NAMES as $name ) {
                if ($command) {
                    $command .= " & ";
                }
                $command .= "taskkill /F /IM \"{$name}.exe\"";
            }
        } else {
            $command = "
                timeout 1 echo '{$this->password}' | sudo -S screen -ls | awk '{print $1}' | xargs -t -I% bash -c \"echo '{$this->password}' | sudo -S screen -X -S % quit;\";/*
                timeout 1 screen -ls | awk '{print $1}' | xargs -I{} screen -X -S {} quit;
            ";
        }
        return self::execWithLogger($this->ssh, $command, $this->logger, "$this->host ".__FUNCTION__.' Exec') !== false;
    }

    /**
     * @return array Statistics: array(pool, time, hashrate) || array()
     */
    public function getStatisticsFromMinerLog ( string $cpu = '' ): array
    {
        $r = [];
        $output = '';
        $command = $this->getParseLogCommand($cpu);
        $this->logger->debug("$this->host ".__FUNCTION__.' Command', ["Command" => $command]);
        try {
            $output = (string)$this->ssh->exec($command);
			$expl 	= explode("|", $output);
            // linux: tail: cannot open '/home/m2680/xmrig-6.21.0-don0/xmrig.log' 
            // win: Select-String : Cannot find path 
            if (count($expl) == 1 || stripos($output, ' cannot ')) {
                $this->logger->warning("$this->host ".__FUNCTION__." Incorrect data from the log", [ "Output" => $output]);
                return $r;
            } 
			$r['pool'] 		= trim($expl[0]);
			$time 	= preg_replace('/^([^\.\]\s]*).*$/', '$1', trim($expl[1])); // Example: 21:00:38.871], 21:00:38]
			$r['time'] 		= $time ? date("H:i:s", strtotime($time)) : '';
			$r['hashrate'] 	= round((float)($expl[2] ?? 0));
            $this->logger->debug("$this->host ".__FUNCTION__." Exec", [ "Output" => $output, 'Result' => $r]);
        } catch (\Exception $e) {
            $this->logger->error("$this->host ".__FUNCTION__." Exec", [ "Error:" => $e->getMessage() , "Output" => $output, 'Result' => $r]);
        }
        return $r;
    }

    public function removeLog (): bool {
        $command = "";
        if ($this->os == self::OS_WINDOWS){
            $command = "del /q \"{$this->logPath}\"";
        } else {
            $command = "timeout 1 echo '{$this->password}' | sudo -S rm '{$this->logPath}';";
        }
        
        $output = self::execWithLogger($this->ssh, $command, $this->logger, "$this->host ".__FUNCTION__.' Exec');
         // if the answer is not empty, then there are problems
        if ($output !== false && trim($output) !== '')
        {
            $this->logger->warning("$this->host ".__FUNCTION__.' Failed to delete log file', ["Command" => $command, "Output" => $output]);
            return true;
        }
        return false;
    }

    /**
     * @return bool | string 
     */
    static function execWithLogger(phpseclib3\Net\SSH2 $ssh, string $command, Monolog\Logger $logger, string $message, array $context = [])
    {
        try {
            $output = $ssh->exec($command);
            $logger->debug($message, ["Command" => $command, "Output" => $output, "Context" => $context]);
            return $output;
        } catch (\Exception $e) {
			$logger->error($message, [ "Error:" => $e->getMessage(), "Command" => $command, "Context" => $context]);
            return false;
        }
    }
}