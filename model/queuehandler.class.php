<?php

/**
* This class handles data distrubution between threads in synchronized fashion
* so that each record is processed only once. It handles downloading more records,
* merging records into input queue, merging and unsetting of queues and sending callbacks
* to main server.
* @author: Saqib Razzaq
* @uses : Threaded class : { http://php.net/manual/en/class.threaded.php }
*/
class QueueHandler extends Threaded {

  public function __construct($photosData) {
    // holds records still to be processed
    $this->allInput = $photosData;

    // holds successfully processed records, waiting to be sent back
    $this->allOutput = array();

    // holds failed records, waiting to be sent back
    $this->allFailed = array();

    // determines state of processing
    $this->threadsRunning = true;

    // send records callback when queue exceeds this
    $this->recordsCallbackAfter = CALLBACK_AFTER;

    // number of iterations, includes both successful and failed items
    $this->processed = 0;

    // more records to fetch after queue goes below this
    $this->moreRecordsAfter = MORE_AFTER;

    // number of more records to fetch
    $this->moreRecordsToFetch = MORE_TO_FETCH;

    // set to true when downloaded data is being merged
    $this->isLoadingInput = false;

    // total records that failed
    $this->failed = 0;

    // start time of while process
    $this->startedAt = microtime(true);

    // peak records processed per minute
    $this->perMinutePeak = 0;
    $this->pointAtLastPerMinutePeakTrack = 0;
    $this->lastPerMinutePeakTrackedAt = $this->startedAt;

    // set to true when no_data is returned by API
    $this->endOfResults = false;
    $this->shutdown = false;
    $this->servername = SERVER_NAME;
  }

  // Once this function is called, processing
  // is gracefully closed to initiate another batch
  public function shutdown() {
    $this->shutdown = true;
  }

  public function shuttingDown() {
    return $this->shutdown;
  }

  private function performChecks() {
    $currentTime = microtime(true);
    if (count($this->allOutput) >= $this->recordsCallbackAfter || count($this->allFailed) >= $this->recordsCallbackAfter || $lastThread) {
      $updateStatus = $this->updateRecords($this->allOutput, $this->allFailed);
    } elseif ($this->endOfResults || $this->shuttingDown()) {
      if (count($this->allInput) < 1 && count($this->allOutput) > 1) {
        $updateStatus = $this->updateRecords($this->allOutput, $this->allFailed);
        $this->threadsRunning = false;
      }
    }

    // per minute
    if ($currentTime - $this->lastPerMinutePeakTrackedAt > 60) {
      $peakFile = PEAK_DIRECTORY . '/peak_per_minute.txt';
      $peak = 0;
      if (file_exists($peakFile)) {
        $peak = file_get_contents($peakFile);
      }

      $inThisSession = ($this->processed - $this->pointAtLastPerMinutePeakTrack);
      if ($inThisSession > $peak) {
        file_put_contents($peakFile, $inThisSession);
        $this->perMinutePeak = $inThisSession;
      }

      $this->pointAtLastPerMinutePeakTrack = $this->processed;
      $this->lastPerMinutePeakTrackedAt = $currentTime;
    }
  }

  public function getNext($workerNumber = false) {
    // You won't notice the difference of having or
    // not having these functions if you only run a few
    // batches. But once iterations count goes into
    // hundreds of thousands and over million, this
    // does make a difference
    gc_collect_cycles();
    gc_mem_caches();
    $this->performChecks();
    $dataNow = $this->allInput->shift();

    if (empty($dataNow['vehicle_id'])) {
      $this->failed = $this->failed + 1;
      return false;
    }

    $perMinutePeak = $this->processed - $this->pointAtLastPerMinutePeakTrack;
    $runningMinutesRaw = (microtime(true) - $this->startedAt) / 60;
    $runningMinutes = round($runningMinutesRaw, 2);
    $averagePerMinute = round($this->processed / $runningMinutesRaw);

    // gracefull shutdown
    if ($runningMinutes >= 30 && !$this->shuttingDown()) {
      echo "Initiating graceful shutdown \n";
      $this->shutdown();
    }

    if ($this->shuttingDown()) {
      $shutdownMessage = ":: Shutting down active";
    } else {
      $d = false;
    }

    echo "p:> " . $this->processed . " : I: " . count($this->allInput) . ' : O: ' . count($this->allOutput) . ' : F: [Tot: ' . $this->failed . ' | Q: ' . count($this->allFailed) . "] : p/M: $this->perMinutePeak | $perMinutePeak : Mins: $runningMinutes [av: $averagePerMinute] : server: $this->servername : $shutdownMessage \n";
    $this->processed = $this->processed + 1;
    return $dataNow;
  }

  public function getFailedInQueue() {
    return count($this->allFailed);
  }

  public function threadsRunning() {
    return $this->threadsRunning;
  }

  public function isLoadingInput() {
    return $this->isLoadingInput;
  }

  public function setLoadingInput($value) {
    $this->isLoadingInput = $value;
  }

  public function isMoreInputNeeded() {
    return count($this->allInput) < $this->moreRecordsAfter ? true : false;
  }

  private function getRandomKey() {
    return $this->generateRandomString(5) . '_' . rand(100, 999);
  }

  public function addOutput($id, $value) {
    $this->allOutput[$id] = $value;
  }

  public function addFailed($value) {
    echo "Failed $value \n";
    $this->failed = $this->failed + 1;
    $this->allFailed[$value] = $value;
  }

  public function addInvalid($value) {
    echo "Invalid $value \n";
    $this->allFailed[$value] = 'invalid';
  }

  public function downloadNewInput() {
    $requestUrl = API_BASE_URL . "/getdata/?size=$this->moreRecordsToFetch&request=dev&server_id=" . SERVER_ID . "&api_key=" . API_KEY;
    $fetchedContents = $this->curlGet($requestUrl, 100); // get more records with 100 seconds timeout
    $data = json_decode($fetchedContents, true);

    if (empty($data) || isset($data['error'])) {
      if (isset($data['error'])) {
        $error = $data['error'];
      }
      echo "Failed to download new data (err: $error) @ $requestUrl \n";
      return false;
    }

    if (isset($data['end_of_results'])) {
      $this->endOfResults = true;
    } else {
      $this->endOfResults = false;
    }

    /**
    * This loop looks unnecessary but it must not be removed. Data returned by this function is used in $this->loadInput which then used with Threaded::merge and that behaves stragnely with default array keys and misses data. To avoid that, we generate our own unique keys
    */

    $response = array();
    foreach ($data as $key => $value) {
      $randKey = $this->generateRandomString(5) . '_' . $key . '_' . rand(100, 999);
      $response[$randKey] = $value;
    }

    return $response;
  }

  private function updateRecords($records, $failed) {
    $requestUrl = API_BASE_URL . '/finishimagesbatch/?api_key=' . API_KEY;
    $postData = array();

    $postData['json'] = json_encode($records);
    $postData['failed_json'] = !empty($failed) ? json_encode($failed) : false;
    $postData['image_source'] = 'b2';
    $postData['server_id'] = SERVER_ID;

    $json = $this->curlPost($requestUrl, $postData, 15);

    // pr($json);
    if (!empty($json)) {
      $readable = json_decode($json);
      if (isset($readable->state)) {
        // remove all items in output queue
        foreach ($this->allOutput as $key => $value) {
          $this->allOutput->shift();
        }

        // remove all items in failed queue
        foreach ($this->allFailed as $key => $value) {
          $this->allFailed->shift();
        }
        return true;
      }
    }
  }

  public function loadInput() {
    $this->allInput->merge($this->downloadNewInput());
  }

  private function generateRandomString($length = 10) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    return $randomString;
  }

  private function curlPost($url, $postdata, $timeout = 1) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);
    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
  }

  private function curlGet($url, $timeout = 100) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
  }
}
