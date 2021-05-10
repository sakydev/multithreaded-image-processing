<?php

class Backblaze  {
  public $dataPath;
  public $accountId;
  public $apiKey;
  public $bucketId;
  public $apiUrl;
  public $accountAuthorizationToken;
  public $downloadUrl;
  public $uploadUrl;
  public $uploadAuthToken;
  public $accountAuthorized;
  public $backblazeDataFile;
  public $output;
  public $lastCheck;
  public $authCallsFile;
  public $authHistoryFile;

  public function __construct() {
    $this->dataPath = CONFIG_DIRECTORY;
    $this->backblazeInit(B2_ACCOUNT_ID, B2_API_KEY, B2_BUCKET_ID);
  }

    /**
  * Initialize Backblaze
  * @param : { string } { $accountId } { Backblaze main account id }
  * @param : { string } { $apiKey } { currently acive Backblaze api key }
  * @param : { string } { $bucketId } { id of Backblaze bucket to upload images to }
  */
  public function backblazeInit($accountId, $apiKey, $bucketId) {
    $this->accountId = $accountId;
    $this->apiKey = $apiKey;
    $this->bucketId = $bucketId;

    $rootDirectory = '/root';
    $this->backblazeDataFile = "{$rootDirectory}config/backblaze_data.txt";
    $this->authCallsFile = "{$rootDirectory}auth/auth_calls.txt";
    $this->authHistoryFile = "{$rootDirectory}auth/auth_history.txt";

    $currentTime = time();
    if (file_exists($this->backblazeDataFile)) {
      $lastModifiedTime = filemtime($this->backblazeDataFile);
    } else {
      $lastModifiedTime = 0;
    }

    $timeDiff = $currentTime - $lastModifiedTime;
    # if file was modified more than 10 minutes ago, we need to re-authenticate
    if ($timeDiff < 21600 && file_exists($this->backblazeDataFile)) { // 21600 = 6 minutes
      echo "Already authorized, $lastModifiedTime \n";
      $dataJson = file_get_contents($this->backblazeDataFile);
      $dataExtracted = json_decode($dataJson, true);
      $this->apiUrl = !empty($dataExtracted['api_url']) ? $dataExtracted['api_url'] : false;
      $this->accountAuthorizationToken = !empty($dataExtracted['auth_token']) ? $dataExtracted['auth_token'] : false;
      $this->downloadUrl = !empty($dataExtracted['download_url']) ? $dataExtracted['download_url'] : false;

      if (!empty($this->accountAuthorizationToken)) {
        $this->accountAuthorized = true;
      }
    } else {
      echo "New auth needed \n";
      $this->authorizeAccount();
    }

    $this->lastCheck = microtime(true);
  }

  /**
  * Authorize Backblaze preparing file to be uploaded
  * @return { boolean }
  */
  public function authorizeUpload() {
    if ($this->accountAuthorized) {
      $url = $this->apiUrl .  '/b2api/v1/b2_get_upload_url';
      $serverOutput = $this->curlAuthorizeUpload($url, $this->bucketId, $this->accountAuthorizationToken);

      if (!empty($serverOutput)) {
        $serverData = json_decode($serverOutput, true);
      }

      if (!isset($serverData['status']) && isset($serverData['authorizationToken']) && isset($serverData['uploadUrl'])) {
        $this->uploadAuthToken = $serverData['authorizationToken'];
        $this->uploadUrl = $serverData['uploadUrl'];
        return true;
      }
    }
  }

  /**
  * Send curl request to authorize Backblaze acoount
  * @param : { string } { $url } { url to send request to }
  * @param : { base64_encoded_string } { $credentials } { accountid:apiKey }
  * @return: { JSON or Null }
  */
  public function curlAuthorizeAccount($url, $credentials) {
    $session = curl_init($url);
    $headers = array();
    $headers[] = 'Accept: application/json';
    $headers[] = 'Authorization: Basic ' . $credentials;
    curl_setopt($session, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($session, CURLOPT_HTTPGET, true);
    curl_setopt($session, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($session, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($session, CURLOPT_SSL_VERIFYPEER, false);
    $serverOutput = curl_exec($session);
    curl_close($session);

    return $serverOutput;
  }

  /**
  * Authorize Backblaze account
  * @action: update Backblaze data file
  * @uses : curlAuthorizeAccount()
  * @return : { JSON } { $jsonData } { contains api_url, auth_token and download_url }
  */
  public function authorizeAccount() {
    $count = (int) trim(file_get_contents($this->authCallsFile));
    $newCount = $count + 1;
    $historyString = "Auth #$newCount : " . date("Y/m/d H:i:s", time()) . "\n";
    file_put_contents($this->authCallsFile, $newCount);
    file_put_contents($this->authHistoryFile, $historyString, FILE_APPEND);

    $credentials = base64_encode($this->accountId . ':' . $this->apiKey);
    $url = 'https://api.backblazeb2.com/b2api/v1/b2_authorize_account';

    $this->output = $this->curlAuthorizeAccount($url, $credentials);
    $serverData = json_decode($this->output, true);
    if (!isset($serverData['status']) && isset($serverData['apiUrl']) && isset($serverData['authorizationToken']) && isset($serverData['downloadUrl'])) {
      $this->apiUrl = $serverData['apiUrl'];
      $this->accountAuthorizationToken = $serverData['authorizationToken'];
      $this->accountAuthorized = true;

      $jsonData = json_encode(array(
          'api_url' => $serverData['apiUrl'],
          'auth_token' => $serverData['authorizationToken'],
          'download_url' => $serverData['downloadUrl']
      ));

      pr("saving file");
      file_put_contents($this->backblazeDataFile, $jsonData);
      return $jsonData;
    }

    echo "Failed to autorize account \n";
    pr($serverData);
    $this->accountAuthorized = false;
    return false;
  }

  public function curlAuthorizeUpload($url, $bucketId, $accountAuthToken) {
    $postFields = json_encode(array('bucketId' => $bucketId));

    $session = curl_init($url);
    curl_setopt($session, CURLOPT_POSTFIELDS, $postFields);
    $headers = array();
    $headers[] = 'Authorization: ' . $accountAuthToken;
    curl_setopt($session, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($session, CURLOPT_POST, true);
    curl_setopt($session, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($session, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($session, CURLOPT_SSL_VERIFYPEER, false);
    $serverOutput = curl_exec($session);
    curl_close($session);

    return $serverOutput;
  }

  public function curlFileUpload($filePath, $fileName, $uploadUrl, $uploadAuthToken) {
    $fileSize = filesize($filePath);
    if (empty($fileSize) || $fileSize < 1) {
      return false;
    }

    $handle = fopen($filePath, 'r');
    $readFile = fread($handle, $fileSize);
    fclose($handle);

    $session = curl_init($uploadUrl);
    curl_setopt($session, CURLOPT_POSTFIELDS, $readFile);
    $headers = array();
    $headers[] = 'Authorization: ' . $uploadAuthToken;
    $headers[] = 'X-Bz-File-Name: ' . $fileName;
    $headers[] = 'Content-Type: ' . 'b2/x-auto';
    $fileSha = sha1_file($filePath);
    $headers[] = 'X-Bz-Content-Sha1: ' . $fileSha;
    curl_setopt($session, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($session, CURLOPT_POST, true);
    curl_setopt($session, CURLOPT_TIMEOUT, 5);
    curl_setopt($session, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($session, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($session, CURLOPT_SSL_VERIFYPEER, false);
    $data = curl_exec($session);
    curl_close($session);

    return $data;
  }

  public function uploadFile($filePath, $try = 1, $failedInQueue = false, $mimeType = 'b2/x-auto') {
    for ($i=0; $i < $try; $i++) {
      if ($this->authorizeUpload()) {
        $fileName = basename($filePath);
        $serverOutput = $this->curlFileUpload($filePath, $fileName, $this->uploadUrl, $this->uploadAuthToken);

        if (!empty($serverOutput)) {
          $serverJson = json_decode($serverOutput, true);
        }

        if (isset($serverJson['fileId']) && !empty($serverJson['fileId'])) {
          return $serverJson;
        }
      } else {
        if (!$this->accountAuthorized) {
          echo "Authorizing needed  _____________ \n";
          $this->authorizeAccount();
        } else {
          echo "Just failed \n";
          pr($serverOutput);
          if (!empty($serverOutput)) {
            $readable = json_decode($serverOutput, true);
            if (isset($readable['code']) && $readable['code'] == 'too_busy') {
              echo "Backblaze is too busy, sleeping for 5 seconds \n";
            }
          }
        }
      }
    }
  }
}