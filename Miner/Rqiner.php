<?php
require_once __DIR__ . '/MinerAbstract.php';
require_once __DIR__ . '/MinerInterface.php';

class Rqiner extends MinerAbstract implements MinerInterface
{
    
    static function getMinerProcessName(string $os = ''): string
    {
        if (strtoupper(trim($os)) == self::OS_WINDOWS) {
            return 'rqiner-x86';
        } else {
            return 'rqiner';
        }
    }

    /**
     * @return false | int
     */
    static function detectProcess ( phpseclib3\Net\SSH2 $ssh, string $host, string $password, ?string $processName = '', string $os = self::OS_LINUX )
    {
        if (!$processName) {
            $processName = self::getMinerProcessName($os);
        }
        return parent::detectProcess($ssh, $host, $password, $processName, $os);
    }

    public function start( string $algo, string $host, string $user, string $pass = 'x', ?string $threads = null, string $args = '' ): bool
    {
        $output = '';
        if ($threads) {
            $args = '-t ' . $threads . ' ' . $args;
        }

        if ($this->os == self::OS_WINDOWS) {
            $command = "psexec -s -d -i 1 powershell -Command \"Start-Process {$this->path} -argumentlist '-i $user $args' -NoNewWindow -RedirectStandardError {$this->logPath} -Wait\"";
            $output = self::execWithLogger($this->ssh, $command, $this->logger, "$this->host ".__FUNCTION__.' Exec');
            return stripos($output, 'with process ID') !== false;
        } else {
            // output in log scrreen screenlog.0
            $command = 
                "timeout 1 echo '{$this->password}' | sudo -S screen -L -Logfile '{$this->logPath}' -dmS {$this->name} '{$this->path}' -i $user $args;
                timeout 1 echo '{$this->password}' | sudo -S screen -ls | awk '{print $1}'; ";
            $output = self::execWithLogger($this->ssh, $command, $this->logger, "$this->host ".__FUNCTION__.' Exec');
            return stripos($output, $this->name) !== false;
        }
        return false;
    }

    /**
     * @return string "time | pool | hashrate | solutions"
     */
    public function getParseLogCommand( string $cpu = '' ): string
    {
        if ($this->os == self::OS_WINDOWS) {
            return "powershell -Command \"Select-String -Path '{$this->logPath}' -Pattern 'INFO' | Select -Last 1 | ForEach-Object{(\$_ -split '\s+')[0,2,6,14] -Join '|'};\"";

        } else {
            return "echo $( timeout 1 tail -f {$this->logPath} | grep -m 1 'INFO' | awk '/INFO/ {print $1 \"|\" $4 \"|\" $8 \"|\" $13}' );";
        }  
        return '';
    }

    /**
     * @return array Statistics: array(time, pool, hashrate, solutions) || array()
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
            if (count($expl) == 1 || stripos($output, ' cannot ')) {
                $this->logger->warning("$this->host ".__FUNCTION__." Incorrect data from the log", [ "Output" => $output]);
                return $r;
            }
            $matches = [];
            if (preg_match('/^.*?\[(?:0m)?(20.*?Z).*$/su', $expl[0], $matches))
            {
                $date = new DateTime($matches[1], new DateTimeZone('UTC'));
                $date->setTimezone(new DateTimeZone(date_default_timezone_get()));
                $r['time'] = $date->format('H:i:s');
            }
			$r['pool'] = substr($expl[1], 0, $this->os == self::OS_WINDOWS ? -1 : -10);
			$r['hashrate'] 	= (float)($expl[2] ?? 0);
			$r['solutions'] 	= (int)($expl[3] ?? 0);
            $this->logger->debug("$this->host ".__FUNCTION__." Exec", [ "Output" => $output, 'Result' => $r]);
        } catch (\Exception $e) {
            $this->logger->error("$this->host ".__FUNCTION__." Exec", [ "Error:" => $e->getMessage() , "Output" => $output, 'Result' => $r]);
        }
        return $r;
    }
}