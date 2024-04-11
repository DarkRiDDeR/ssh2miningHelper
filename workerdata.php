<?php
/*
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
*/
define('OPEN_HARDWARE_MONITOR_DEFAULT_PORT', 8085);
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");


require_once "config.php";
require_once "./Miner/MinerAbstract.php";
require_once "./Miner/Xmrig.php";
require_once "./Miner/CpuMiner.php";
require_once "./Miner/Rqiner.php";
use phpseclib3\Net\SSH2;

$return = [];

foreach($arr as $v)
{
	$time = 0;

	$arWorker = [
		'id' 			=> $v['worker'], 
		'temperature' 	=> '---', 
		'time' 			=> '---', 
		'hashrate' 		=> '---', 
		'pool' 			=> '---', 
		'solutions'		=> 0,
		'session'		=> 'offline', 
	];
	$ping_result = false;

	// --- PING --- //
	if (strcasecmp(substr(PHP_OS, 0, 3), 'WIN') == 0) { // for OS Windows
		$ping_result = shell_exec("ping -n 1 " . $v['host']);
		$ping_result = stripos($ping_result, "Packets: Sent = 1, Received = 1") !== false;
	} else {
		$ping_result = shell_exec("ping -c 1 " . $v['host']);
		$ping_result = stripos($ping_result, "1 packets transmitted, 1 received") !== false;
	}

	if (!$ping_result)
	{
		goto finishWorker;
	}

	$ssh = null;
	$os = isset($v['os']) ? strtoupper(trim($v['os'])) : '';

	// OS Windows
	if ($os  == "WIN")
	{
		try {
			// --- TEMPERATURES from Open Hardware Monitor --- //
			$ohmPort = $v['port'] ?? OPEN_HARDWARE_MONITOR_DEFAULT_PORT;
			$ctx = stream_context_create(['http'=> ['timeout' => 1]]);
			$jd = @file_get_contents("http://{$v['host']}:{$ohmPort}/data.json", false, $ctx);

			if ($jd === false) {
				$error = error_get_last();
				throw new Error('HTTP request failed: ' . $error['message']);
			} else {
				$jd = mb_convert_encoding($jd, 'UTF-8', 'WINDOWS-1251');
				$jd = json_decode($jd, true, 512, JSON_THROW_ON_ERROR); // data from  Web server
				$arWorker['temperature'] = [];
				if ($jd['Text'] == "Sensor" && isset($jd['Children'][0]['Children'])){
					foreach($jd['Children'][0]['Children'] as $arDevice) { // CPU, GPU RAM
						if (preg_match('/^(Intel|AMD)\s/iu', $arDevice['Text'])) {
							foreach ($arDevice['Children'] as $arGroup) { // CLocks, Temperatures, Powers
								if (strtolower($arGroup['Text']) == 'temperatures') {
									foreach ($arGroup['Children'] as $arTempers) {
										if (strtolower($arTempers['Text']) == 'cpu package'){
											$arWorker['temperature'][] = preg_replace('/^.*?([\d,\.]+).*$/u', '+$1 °C', $arTempers['Value']);
										}
									}
								}
							}
						}
					}
				}
			}
		} catch (\Exception $e) {
			//print_r(['Error getting temperature for ' . $v['host'], $e]);
			goto finishWorker;
		}
		
		try {
			// --- Создаем новый объект SSH2 и подключаемся к серверу --- //
			$ssh = new SSH2($v['host']);
			if (!$ssh->login($v['user'], $v['pass'])) {
				goto finishWorker;
			}
		} catch (\Exception $e) {
			goto finishWorker;
		}

	// --- Other OS --- //
	} else { 
		try {
			// --- Создаем новый объект SSH2 и подключаемся к серверу --- //
			$ssh = new SSH2($v['host']);
			if (!$ssh->login($v['user'], $v['pass'])) {
				goto finishWorker;
			}
		} catch (\Exception $e) {
			goto finishWorker;
		}

		// --- TEMPERATURES --- //
		try {
			$command 	= "echo $(timeout 1 sensors 2>/dev/null | awk '/(Tctl|Package id [0-9]):/ {print $0}')";
			$output 	= $ssh->exec($command);

			$temperMatches = [];
			preg_match_all('/\S:\s+(\S+)/iu', $output, $temperMatches);
			if ($temperMatches[1])
				$arWorker['temperature'] = $temperMatches[1];

		} catch (\Exception $e) {
			goto finishWorker;
		}

		// --- QUBIC --- //
		if (!empty( $v['miners']['qubic']['log'])) {
			try {
				$command 	= "echo $( timeout 0.5 tail -f {$v['miners']['qubic']['log']} | grep -m 1 \"avg\" )";
				$output 	= $ssh->exec($command);
				$expl 		= explode("|", $output);
				$first		= explode("INFO", $expl[0]??[]);
				$SOL		= (str_contains(($expl[1] ?? ''), 'SOL:')) ? explode("/", $expl[1] ?? '') : 0;
				$time 		= trim($first[0]??0);

				$timezone 	= new DateTimeZone($time_zone);
				$date 		= new DateTime($time, new DateTimeZone('UTC'));
				$date->setTimezone($timezone);

				if( (time()-strtotime($date->format('Y-m-d H:i:s'))) < 60)
				{
					$arWorker['session']	= "QUBIC";
					$arWorker['time'] 		= $date->format('H:i:s');
					$arWorker['hashrate'] 	= (str_contains(($expl[3] ?? 'it/s'), '')) ? intval(trim($expl[3])) : 0;
					$arWorker['pool'] 		= (str_contains(($first[1] ?? ''), 'E:')) ? ($first[1] ?? '').($expl[1] ?? '') : '';
					$arWorker['solutions']	= isset($SOL[1]) ? (int)$SOL[1] : 0;

					goto finishWorker;
				}

			} catch (\Exception $e) {
				//goto finishWorker;
			}
		}
		//-->
	}

	$arProcesses = MinerAbstract::detectAllProcesses($ssh, $v['host'], $v['pass'], [], $os);
	if (in_array(Xmrig::getMinerProcessName($os), $arProcesses))
	{
		$xmrig = new Xmrig($ssh, $v['host'], $v['pass'], $v['miners']['xmrig']['path'], $v['miners']['xmrig']['log'], $os);
		//$cpu = $xmrig->getCpuFamily();
		$arStatistics = $xmrig->getStatisticsFromMinerLog();
		$arWorker = array_merge($arWorker, $arStatistics);
		$arWorker['session']  = 'xmrig'; // . $cpu
		goto finishWorker;

	} elseif (in_array(Cpuminer::getMinerProcessName($os), $arProcesses)) {
		$cpuminer = new Cpuminer($ssh, $v['host'], $v['pass'], $v['miners']['cpuminer']['path'], $v['miners']['cpuminer']['log'], $os);
		//$cpu = $cpuminer->getCpuFamily();
		$arStatistics = [];
		$arStatistics = $cpuminer->getStatisticsFromMinerLog();
		$arWorker = array_merge($arWorker, $arStatistics);
		$arWorker['session']  = 'cpuminer';// . ".$cpu";
		goto finishWorker;
	} elseif (in_array(Rqiner::getMinerProcessName($os), $arProcesses)) {
		$rqiner = new Rqiner($ssh, $v['host'], $v['pass'], $v['miners']['rqiner']['path'], $v['miners']['rqiner']['log'], $os);
		$arStatistics = [];
		$arStatistics = $rqiner->getStatisticsFromMinerLog();
		$arWorker = array_merge($arWorker, $arStatistics);
		$arWorker['session']  = 'rqiner';
		goto finishWorker;
	}

	
	// <-- IF no xmrig and no cpuminer then PC is online but this must to be reload. See index.php trbl_worker
	$arWorker['session'] = "OFF";
	
	// FINISH WORKER
	finishWorker: $return[$v['host']] = $arWorker;
}

echo json_encode($return);
exit;

?>
