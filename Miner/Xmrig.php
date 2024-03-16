<?php
require_once __DIR__ . '/MinerAbstract.php';
require_once __DIR__ . '/MinerInterface.php';

class Xmrig extends MinerAbstract implements MinerInterface
{
    
    static function getMinerProcessName(string $os = ''): string
    {
        return 'xmrig';
    }

    /**
     * @return string "pool | time | hashrate"
     */
    public function getParseLogCommand( string $cpu = '' ): string
    {
        if ($this->os == self::OS_WINDOWS) {
            // не должно быть переводов строк для powershell
            return 
                "powershell -Command \""
                    ."Select-String -Path '{$this->logPath}' -Pattern 'new job' | Select -Last 1 | ForEach-Object{(\$_ -split '\s+')[6]};"
                    ."'|';"
                    ."Select-String -Path '{$this->logPath}' -Pattern 'speed' | Select -Last 1 | ForEach-Object{(\$_ -split '\s+')[1,5] -Join '|'};"
                ."\"";
        } else {
            return "
                echo $( timeout 1 tail -n -20 -f {$this->logPath} | grep -m 1 'new job' | awk '/new job/ {print $7}' );
                echo \"|\"; 
                echo $( timeout 1 tail -n -20 -f {$this->logPath} | grep -m 1 'speed' | awk '/speed/ {print $2 \"|\" $6}' );
            ";
        }  
    }

    public function start( string $algo, string $host, string $user, string $pass = 'x', ?string $threads = null, string $args = '' ): bool
    {
        $output = '';
        if ($threads) {
            $args = '-t ' . $threads . ' ' . $args;
        }

        if ($this->os == self::OS_WINDOWS) {
            $command = "psexec -s -d -i 1 \"{$this->path}\" -a $algo -o $host -u $user -p $pass --log-file=\"{$this->logPath}\" $args ";
            $output = self::execWithLogger($this->ssh, $command, $this->logger, "$this->host ".__FUNCTION__.' Exec');
            return stripos($output, 'with process ID') !== false;
        } else {
            $command = "
                timeout 1 echo '{$this->password}' | sudo -S screen -dmS xmrig \"{$this->path}\" -a $algo -o $host -u $user -p $pass --randomx-1gb-pages --log-file=\"{$this->logPath}\" $args;
                timeout 1 echo '{$this->password}' | sudo -S screen -ls | awk '{print $1}';
            ";
            $output = self::execWithLogger($this->ssh, $command, $this->logger, "$this->host ".__FUNCTION__.' Exec');
            return stripos($output, $this->name) !== false; //  $this->name == xmrig
        }
        return false;
    }
}