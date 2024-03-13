<?php
require_once __DIR__ . '/MinerAbstract.php';
require_once __DIR__ . '/MinerInterface.php';

class Cpuminer extends MinerAbstract implements MinerInterface
{
    
    static function getMinerProcessName(string $os = ''): string
    {
        if (strtoupper(trim($os)) == self::OS_WINDOWS) {
            return 'cpuminer-sse2';
        } else {
            return 'cpuminer';
        }
    }
    static function detectProcess ( phpseclib3\Net\SSH2 $ssh, string $host, string $password, ?string $processName = '', string $os = self::OS_LINUX ): false | int
    {
        if (!$processName) {
            $processName = self::getMinerProcessName($os);
        }
        return parent::detectProcess($ssh, $host, $password, $processName, $os);
    }

    public function getParseLogCommand( string $cpu = '' ): string
    {
        if ($this->os == self::OS_WINDOWS) {
            // не должно быть переводов строк для powershell
            /*return 
                "powershell -Command \""
                    ."Select-String -Path '{$this->logPath}' -Pattern 'Accepted' | Select -Last 1 | ForEach-Object{(\$_ -split '\s+')[1]};"
                    ."'|';"
                    ."Select-String -Path '{$this->logPath}' -Pattern 'Accepted' | Select -Last 1 | ForEach-Object{(\$_ -split '\s+')[7]};"
                    ."'|';"
                    ."Select-String -Path '{$this->logPath}' -Pattern 'network' | Select -Last 1 | ForEach-Object{(\$_ -split '\s+')[2]};"
                ."\"";*/

        } else {
            return "
                echo $( timeout 1 tail -f {$this->logPath} | grep -m 1 'Accepted' | awk '/Accepted/ {print $2 \"|\" $8}' );
                echo \"|\"; 
                echo $( timeout 1 tail -f {$this->logPath} | grep -m 1 'network' | awk '/network/ {print $3}' );
            ";
        }  
        return '';
    }

    public function start( string $algo, string $host, string $user, string $pass = 'x', ?string $threads = null, string $args = '' ): bool
    {
        $output = '';
        if ($threads) {
            $args = '-t ' . $threads . ' ' . $args;
        }

        if ($this->os == self::OS_WINDOWS) {
            $command = "psexec -s -d -i 1 \"{$this->path}\" -a $algo -o $host -u $user -p $pass $args > \"{$this->logPath}\"";
            $output = self::execWithLogger($this->ssh, $command, $this->logger, "$this->host ".__FUNCTION__.' Exec');
            return stripos($output, 'with process ID') !== false;
        } else {
            // output in file. See: tail -f <logPath>
            $command = "
                timeout 1 echo '{$this->password}' | sudo -S screen -dmS cpuminer bash -c '\"{$this->path}\" -a $algo -o $host -u $user -p $pass $args > \"{$this->logPath}\"';
                timeout 1 echo '{$this->password}' | sudo -S screen -ls | awk \'{print $1}\';
            ";
            $output = self::execWithLogger($this->ssh, $command, $this->logger, "$this->host ".__FUNCTION__.' Exec');
            return stripos($output, $this->name) !== false; //  $this->name == cpuminer
        }
        return false;
    }
}