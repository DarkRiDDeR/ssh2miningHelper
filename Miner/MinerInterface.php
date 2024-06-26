<?php

interface MinerInterface
{
    /**
     * @return false | int
     */
    static public function getMinerProcessName( string $os = '' ): string;
    public function getParseLogCommand( string $cpu = '' ): string;
    public function getStatisticsFromMinerLog( string $cpu = '' ): array;
    public function start( string $algo, string $host, string $user, string $pass = 'x', ?string $threads = null, string $args = ''): bool;
}