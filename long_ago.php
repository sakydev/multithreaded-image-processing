<?php

$lastTime = trim(file_get_contents(__DIR__ . '/start_stamps/last_ran.txt'));
$currentTime = time();
$difference = round(($currentTime - $lastTime) / 60);
if (isset($argv['1'])) {
	echo $difference;
} else {
	echo "\n +++ Last $lastTime and now $currentTime : $difference minutes ago +++ \n \n";
}
