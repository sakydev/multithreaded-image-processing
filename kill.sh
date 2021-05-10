#!/bin/bash

RUNNING="$(/usr/bin/php /root/long_ago.php true)"
if [ $RUNNING -le 40 ] # -le means lower than or equal to
then
  echo "$RUNNING does not exceed 40 min graceful limit, nothing to do";
else
  echo "$RUNNING exceeds 40 min graceful limit, killing";
  pkill php*
fi
