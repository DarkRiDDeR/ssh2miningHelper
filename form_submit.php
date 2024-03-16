<?php
/*
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
*/

// Dont forget:  sudo visudo
// username ALL=(ALL) NOPASSWD: ALL

require_once "config.php";
require_once "./Miner/Xmrig.php";
require_once "./Miner/CpuMiner.php";
require_once "./Miner/Rqiner.php";
use phpseclib3\Net\SSH2;

if(isset($_POST['command']))
{
	$bd = "<div class=\"container-fluid\" style=\"margin-top:20px\">";
	foreach($arr as $v)
	{
		if(!in_array($v['worker'], $_POST['workers']))
		{
			continue;
		}
		$output = '';
		$arDebug = [];

		try {
			// Создаем новый объект SSH2 и подключаемся к серверу
			$ssh = new SSH2($v['host']);
			if (!$ssh->login($v['user'], $v['pass'])) {
				continue;
			}
		} catch (\Exception $e) {
			continue;
		}

		// --- CHECK QUBIC --- //
		$v['qubick_online'] = "false";

		if (!empty( $v['miners']['qubic'])) {

			try {
				$command 	= "echo $( timeout 0.5 tail -f {$v['miners']['qubic']['log']} | grep -m 1 \"INFO\" | awk '/INFO/ {print $1\" \"$2}' )";
				$output 	= $ssh->exec($command);
				$date 		= strtotime($output);
				$t_diff		= (time() - $date);

				if ($date !== false and $t_diff <= 60)
				{
					$v['qubick_online'] = $t_diff;
				}
			} catch (\Exception $e) {
				continue;
			}
		}
		// -->

		if($v['qubick_online'] == "false" and $_POST['miner'] != '')
		{
			$os = isset($v['os']) ? trim($v['os']) : '';
			$t = isset($_POST['theads']) && $_POST['theads']=='manual'? $v['theads']:null;
			if($_POST['miner'] == 'xmrig' && !empty($v['miners']['xmrig']))
			{
				$xmrig = new Xmrig($ssh, $v['host'], $v['pass'], $v['miners']['xmrig']['path'], $v['miners']['xmrig']['log'], $os);
				$arDebug['killAllMiners'] = $xmrig->killAllMiners();
				$arDebug['removeLog'] = $xmrig->removeLog();
				$arDebug['start'] = $xmrig->start($_POST['algo'], $_POST['host'], $_POST['user'].'.'.$v['worker'], $_POST['pass'], $t);		
			}
			elseif($_POST['miner'] == 'cpuminer' && !empty($v['miners']['cpuminer']))
			{
				$cpuminer = new Cpuminer($ssh, $v['host'], $v['pass'], $v['miners']['cpuminer']['path'], $v['miners']['cpuminer']['log'], $os);
				$arDebug['killAllMiners'] = $cpuminer->killAllMiners();
				//$arDebug['removeLog']	= $cpuminer->removeLog(); // вывод терминала пишется каждый раз в файл заново
				$arDebug['start'] = $cpuminer->start($_POST['algo'], $_POST['host'], $_POST['user'].'.'.$v['worker'], $_POST['pass'], $t);		
			}
			elseif($_POST['miner'] == 'rqiner' && !empty($v['miners']['rqiner']))
			{
				$rqiner = new Rqiner($ssh, $v['host'], $v['pass'], $v['miners']['rqiner']['path'], $v['miners']['rqiner']['log'], $os);
				$arDebug['killAllMiners'] = $rqiner->killAllMiners();
				$arDebug['removeLog']	= $rqiner->removeLog();
				$arDebug['start'] = $rqiner->start($_POST['algo'], $_POST['host'], $_POST['user'], $_POST['pass'], $t);		
			}
		}

		if ($_POST['command'])
		{
			try {
				$output = $ssh->exec($_POST['command']);
			} catch (\Exception $e) {
				continue;
			}
		}

		$bd  .= "
		<div class=\"row\">
			<div class=\"col-md-6\">
				<h3>Input: ".$v['host'] . "</h3>
				Miner: {$_POST['miner']}<br>
				Output: " . str_replace("\n", "<br>", $output) . "<br>
				<b>See logs in the folder /log/</b><br>
				Debug info: ". print_r($arDebug, true) ."
			</div>
		</div>
		<hr>";
	}
	$bd .= "<a href=\"\" class=\"btn btn-warning btn-block\">Home</a></div>";

	if($_POST['debug'] == "true")
	{
		echo json_encode(['debug' => $bd, 'return' => "OK", 'post_workers' => $_POST['workers']]);
	}
	else
	{
		echo json_encode(['return' => "OK", 'post_workers' => $_POST['workers']]);
	}
}
?>
