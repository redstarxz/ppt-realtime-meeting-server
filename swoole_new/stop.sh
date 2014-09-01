#! /bin/bash
#stop live_master
echo -e "stoping live_master.....";
ps aux|grep live_master.php|grep -v grep|awk '{print $2;}'|xargs kill 
ps aux|grep live_master.php
echo -e "live_master stoped";

#stop live_slave
ps aux|grep live_slave.php|grep -v grep|awk '{print $2;}'|xargs kill 
echo -e "stoping live_slave...";
ps aux|grep live_slave.php
echo -e "live_slave stoped";

#stop live_dispatcher
echo -e "stoping live_dispatcher......";
ps aux|grep live_dispatcher.php|grep -v grep|awk '{print $2;}'|xargs kill 
ps aux|grep live_dispatcher.php
echo -e "live_dispatcher stoped";

echo -e "\n all stoped! done!"
