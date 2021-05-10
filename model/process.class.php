<?php

/**
* This class handles main work regarding image processing. It keeps running dowhile loop which keeps running unless stopped with
* $qeueHandler->threadsRunning = false;
* This class handles downloading of images, merging into BIN and byte range extraction and
* authorizing and upload to backblaze. After one record is processed, next one is fetched from
* $queueHandler until all records are processd on it or it is terminated manually
* @author: Saqib Razzaq
* @uses : Threaded class : { http://php.net/manual/en/class.threaded.php }
*/
class Process extends Threaded  {

  public function __construct($workerNumber) {
    $this->dataPath = '/root';
    $this->imagesDirectoryFullPath = '/root/media';
    $this->proxyFilePath = '/root/config/proxies.txt';
    $this->backblaze = new Backblaze();
    $this->loadInput = false;
    $this->count = 0;
    $this->peak = 0;
    $this->workerNumber = $workerNumber;
    $this->totalIterations = 500000000;
    $this->point = 0;
  }

  public function run() {
    do {
      $imagesData = null;
      $queueHandler = null;
      // fetches worker object and breaks if returned false
      $queueHandler = !empty($this->worker->getQueue()) ? $this->worker->getQueue() : false;
      if (!$queueHandler->threadsRunning() || !$queueHandler) { break; }

      $queueHandler->synchronized(function($queueHandler) use (&$imagesData) {
        $imagesData = $queueHandler->getNext();
        $this->point = $queueHandler->processed;
        if (!$this->backblaze->accountAuthorized) {
          $this->backblaze->authorizeAccount();
        }
      }, $queueHandler);

      $queueHandler->synchronized(function($queueHandler) {
        if ($queueHandler->isMoreInputNeeded() && !$queueHandler->shuttingDown()) {
          $queueHandler->loadInput();
        }
      }, $queueHandler);

      if (!empty($imagesData) && !empty($imagesData->image_id)) {
        // 2018-11-21
        $theDateFigures = str_replace('-', '', substr($imagesData->date_first, 0, 10));
        $theImageId = $imagesData->image_id;
        $theImageSource = $imagesData->source;
        // fetches 3 image links to be processed, the rest are ignored
        $imageLinks = $this->getImageLinks($imagesData);
        // locally download images for current record
        $localImageLinks = array();

        // loop through links, download images and stack them in $localImageLinks array
        for ($i = 0; $i < 3; $i++) {
          if (count($localImageLinks) > 0) {
            break;
          }

          foreach ($imageLinks as $imageKey => $originalImage) {
            if (!strstr($originalImage, 'http') && !strstr($originalImage, 'www.')) {
              echo "Skipping $originalImage \n";
              continue;
            }

            $incrementedKey = $imageKey + 1;
            $imageExtension = pathinfo($originalImage, PATHINFO_EXTENSION);

            if (strlen($imageExtension) > 4) {
              $imageExtension = substr($imageExtension, 0, 4);
            }

            if (empty($imageExtension) || $imageExtension == 'php') {
              $imageExtension = 'jpg';
            }

            // 36588_20181108_8_1.jpe
            // {image_id}_{first_seen}_{source_int_id}_{image_number}
            $originalImageName = $theImageId . '_' . $theDateFigures . '_' . $theImageSource . '_' . $incrementedKey . '.' . $imageExtension;
            $originalImagePath = $this->imagesDirectoryFullPath . '/' . $originalImageName;
            $imageDownloaded = $this->downloadImageCurl($originalImage, $originalImagePath);

            if ($imageDownloaded) {
              $localImageLinks[$incrementedKey] = $imageDownloaded;
            } else {
              echo "Unable to download $originalImage \n";
            }
          }
        }

        if (!empty($localImageLinks)) {
          $this->totalIterations = $this->totalIterations - 1;
          $doneImageNames = array();
          foreach ($localImageLinks as $key => $currentImageLink) {
            if ($this->backblaze->uploadFile($currentImageLink, 2)) {
              $doneImageNames[] = basename($currentImageLink);
            }
            unlink($currentImageLink);
          }

          if (!empty($doneImageNames)) {
            $queueHandler->addOutput($theImageId . '~' . $theImageSource, implode('|', $doneImageNames));
          } else {
            $queueHandler->addFailed($theImageId . '~' . $theImageSource);
          }
        } else {
          $queueHandler->addInvalid($theImageId . '~' . $theImageSource);
        }
      } else {
        if ($queueHandler->shuttingDown()) {
          break;
        }

        $sleep = 10;
        sleep($sleep);
        echo "Invalid data, sleeping for $sleep \n";
      }
    }
    while ($this->totalIterations > 1);
  }

  /**
  * Download an image using wget
  * @param : { string } { $imageUrl } { url of image to be downloaded }
  * @param : { string } { $imageFile } { path to save image at }
  */
  public function downloadImageWget($imageUrl, $imageFile) {
    $command = "wget -q $imageUrl -t 3 -O $imageFile";
    exec($command);
    if (file_exists($imageFile) && filesize($imageFile) > 0) {
      return $imageFile;
    }
  }

  public function downloadImageCurl($imageUrl, $imageFile) {
    $imageUrl = trim($imageUrl);
    $proxy = $this->getRandomProxy();
    $fp = fopen($imageFile, 'w+');
    $ch = curl_init($imageUrl);
    curl_setopt($ch, CURLOPT_PROXY, $proxy);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    curl_exec($ch);
    $error = curl_error($ch);

    if (!empty($error)) {
      echo "Error for $imageUrl \n";
      var_dump($error);
      @unlink($imageFile);
      return false;
    }

    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $httpErrors = array(401, 402, 403, 404, 405, 406, 415, 500, 501, 502, 503, 504);

    if (in_array($httpcode, $httpErrors)) {
      @unlink($imageFile);
      return false;
    }

    curl_close($ch);
    fclose($fp);
    if (file_exists($imageFile) && filesize($imageFile) > 5000) { // 5KB
      return $imageFile;
    }
  }

  /**
  * Get image links to be downloaded
  * @param : { string } { $vehicleArray } { list of image ids/links separated by | }
  */
  public function getImageLinks($vehicleArray) {
    if (strstr($vehicleArray['images'], '|')) {
      $imagesArray = explode('|', $vehicleArray['images']);
    } else {
      $imagesArray = array($vehicleArray['images']);
    }

    $imagesArray = array_chunk($imagesArray, 3)['0'];
    return $imagesArray;
  }

  public function getRandomProxy() {
    $lines = file($this->proxyFilePath) ;
    return $lines[array_rand($lines)] ;
  }
}