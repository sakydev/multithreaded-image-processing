#!/bin/bash

RUNNING="$(ps aux|grep process.php|grep -v grep|wc -l)"
if [ $RUNNING -eq 0 ]
then
  echo "run.sh : start";
  cd /root && ./run.sh
else
  echo "Already running : skipping";
fi
