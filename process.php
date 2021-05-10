<?php

gc_enable();
ini_set('display_errors', 'On');
error_reporting(-1);
ini_set('memory_limit', -1);

function pr($a) { echo '<pre>'; print_r($a); echo '</pre>'; }
function pex($a, $b = 'pex') { pr($a); exit($b); }

define('API_BASE_URL', ''); # used for building API requests
define('API_KEY', ''); # used for authenticating API requests
define('B2_ACCOUNT_ID', '', true);
define('B2_API_KEY', '', true);
define('B2_BUCKET_ID', '', true);

require_once('root.inc.php');
require $ROOT . 'config/localconfigs.php';
require $ROOT . 'model/process.class.php';
require $ROOT . 'model/threadhandler.class.php';
require $ROOT . 'model/queuehandler.class.php';
require $ROOT . 'utils/backblaze.php';

function curlPost($url, $postdata, $timeout = 1) {
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);
  $response = curl_exec($ch);
  curl_close($ch);
  return $response;
}

# unlink(CONFIG_DIRECTORY . '/backblaze_data.txt');
$settingsFile = __DIR__ . '/config/settings.json';
$configurations = json_decode(file_get_contents($settingsFile), true);

if (empty($configurations)) {
  exit("Invalid configurations, exiting");
}

define('INITIAL_RECORDS', $configurations['init']);
define('MAX_THREADS', $configurations['threads']);
define('MORE_AFTER', $configurations['more_after']);
define('MORE_TO_FETCH', $configurations['more_to_fetch']);
define('CALLBACK_AFTER', $configurations['callback_after']);

$serverIdFilePath = $ROOT . 'config/serverid.txt';
if (file_exists($serverIdFilePath)) {
  define('SERVER_ID', trim(file_get_contents($serverIdFilePath)));
  define('SERVER_NAME', 'proc' . SERVER_ID);
} else {
  echo "This is either first run or serverid file has been deleted. Reuquesting serverid is one time process. \n Fetching server ID...\n";
  $currentServerIp = exec("/sbin/ip a | grep -Eo 'inet (addr:)?([0-9]*\.){3}[0-9]*' | grep -Eo '([0-9]*\.){3}[0-9]*' | grep -v '127.0.0.1'");
  if (empty($currentServerIp)) {
    exit('Unable to extract IP address');
  }
  $requestUrl = API_BASE_URL . '/getserverid/?ip=' . $currentServerIp . '&api_key=' . API_KEY;
  $serverIdCheck = json_decode(file_get_contents($requestUrl), true);
  if (isset($serverIdCheck['state'])) {
    $state = $serverIdCheck['state'];
    if ($state == 'success' && !empty($serverIdCheck['data'])) {
      $currentServerId = trim($serverIdCheck['data']);
      file_put_contents($serverIdFilePath, $currentServerId);
      echo "Server id is " . $currentServerId . "; Saved. \n";
      define('SERVER_ID', $currentServerId);
      define('SERVER_NAME', 'proc' . SERVER_ID);
    } else {
      echo "Sent IP was $currentServerIp @ $requestUrl and response is below \n";
      pr($serverIdCheck);
      exit('Unable to match server_id. Exiting..');
    }
  } else {
    exit('Unable to fetch server id initially');
  }
}

if (!defined('SERVER_ID')) {
  exit('No server_id is defined');
}

$dataRequestUrl = API_BASE_URL . '/getdatachunk/?api_key=' . API_KEY . '&request=dev&size=1000';
$carData = json_decode(file_get_contents($dataRequestUrl), true);
if (empty($carData)) {
  exit('No initial data could be fetched');
} elseif (isset($carData['end_of_results'])) {
  echo "end_of_results ecnountered ($dataRequestUrl). Script will sleep for 5 minutes and then exit";
  sleep(300);
  exit("Closed");
}

# for purpose of avoiding too many init request by threads
$backblaze = new Backblaze();

$queuehandler = new QueueHandler($carData);
$pool = new Pool(MAX_THREADS, 'ThreadHandler', [$queuehandler]);

echo "\n \n \n ***************************************************************** \n \n \n";
echo "Starting process with initial Data [ " . INITIAL_RECORDS . " ] and threads [ " . MAX_THREADS . " ] ";
echo "\n \n \n ***************************************************************** \n \n \n";

file_put_contents(__DIR__ . '/start_stamps/last_ran.txt', time());

for ($i = 0; $i < MAX_THREADS; $i++) {
  $pool->submit(new Process($i));
}

$pool->shutdown();
