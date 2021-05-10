<?php

/**
* This class works as bride between qeue and actual data processing class
* @author: Saqib Razzaq
* @version: 1.0
*/

class ThreadHandler extends Worker {
  public $queueHandler;

  public function __construct($queueHandler) {
    $this->queueHandler = $queueHandler;
  }
  
  public function run() {
    
  }
  
  public function getQueue() {
    return $this->queueHandler;
  }
}