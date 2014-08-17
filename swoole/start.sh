#! /bin/bash

c=`netstat -anp |grep -e '831[1-7]' |grep EST|wc -l`
if [ $c -gt  0 ]; then
    echo -e "start failed! process not stop yet!"
    exit
fi

# start live_master
php live_master.php >> /tmp/daemon_live.log 2>&1 & 
ps aux|grep live_master.php
echo -e "live_master started!"
sleep 1
# start live_slave
php live_slave.php 8313 >> /tmp/daemon_live.log 2>&1 &
php live_slave.php 8314 >> /tmp/daemon_live.log 2>&1 &
php live_slave.php 8315 >> /tmp/daemon_live.log 2>&1 &
php live_slave.php 8316 >> /tmp/daemon_live.log 2>&1 &
php live_slave.php 8317 >> /tmp/daemon_live.log 2>&1 &

ps aux|grep live_slave.php
echo -e "live_slave started!"

sleep 1
# start live_slave
# start live_dispatcher
php live_dispatcher.php >> /tmp/daemon_live.log 2>&1 &

ps aux|grep live_dispatcher.php
echo -e "live_dispatcher started!"

