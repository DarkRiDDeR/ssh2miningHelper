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

	try {

		// --- Создаем новый объект SSH2 и подключаемся к серверу --- //
		$ssh = new SSH2($v['host']);
		if (!$ssh->login($v['user'], $v['pass'])) {
			goto finishWorker;
		}

	} catch (\Exception $e) {
		goto finishWorker;
	}


	$os = isset($v['os']) ? strtoupper(trim($v['os'])) : '';

	// OS Windows
	if ($os  == "WIN")
	{
		// --- TEMPERATURES from Open Hardware Monitor --- //
		$ohmPort = $v['port'] ?? OPEN_HARDWARE_MONITOR_DEFAULT_PORT;
		$ctx = stream_context_create(['http'=> ['timeout' => 1]]);
		$jd = @file_get_contents("http://{$v['host']}:{$ohmPort}/data.json", false, $ctx);
		if ($jd) {
			$jd = json_decode($jd, true); // data from  Web server
			$arWorker['temperature'] = [];
			if ($jd['Text'] == "Sensor" && isset($jd['Children'][0]['Children']))
			{
				foreach($jd['Children'][0]['Children'] as $arDevice) { // CPU, GPU RAM
					if (preg_match('/^(Intel|AMD)\s/iu', $arDevice['Text'])) {
						foreach ($arDevice['Children'] as $arGroup) { // CLocks, Temperatures, Powers
							if (strtolower($arGroup['Text']) == 'temperatures') {
								foreach ($arGroup['Children'] as $arTempers) {
									if (strtolower($arTempers['Text']) == 'cpu package'){
										$arWorker['temperature'][] = '+' . $arTempers['Value'];
									}
								}
							}
						}
					}
				}
			}
		}

	// --- Other OS --- //
	} else { 

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
				$command 	= "echo $( timeout 0.5 tail -f {$v['miners']['qubic']['log']} | grep -m 1 \"INFO\" | awk '/INFO/ {print $1\" \"$2\"|\"$12\"|\"$4\",\"$6\"|\"$7}' )";
				$output 	= $ssh->exec($command);
				$expl 		= explode("|", $output);
				$SOL		= (str_contains(($expl[2] ?? ''), 'SOL:')) ? explode("/", $expl[3] ?? '') : 0;

				if(strtotime($expl[0]) !== false and (time()-strtotime($expl[0])) < 60)
				{
					$arWorker['session']	= "QUBIC";
					$arWorker['time'] 		= $expl[0] ? date("H:i:s", strtotime($expl[0])) : '';
					$arWorker['hashrate'] 	= $expl[1] ?? 0; //round(((float)$expl[1] ?? 0));
					$arWorker['pool'] 		= (str_contains(($expl[2] ?? ''), 'SOL:')) ? ($expl[2] ?? '').($expl[3] ?? '') : '';
					$arWorker['solutions']	= isset($SOL[1]) ? (int)$SOL[0] : 0;

					goto finishWorker;
				}

			} catch (\Exception $e) {
				goto finishWorker;
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
