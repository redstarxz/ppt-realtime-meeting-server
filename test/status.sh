#! /bin/bash

echo -e "run status:"
ps afx -o pid,args,etime,stime|grep live_ | grep -v grep

echo -e "total internal connection:"
c=`netstat -anp |grep -e '831[1-7]' |grep EST|grep '127.0.0.1'|wc -l`
echo `echo $c/2|bc`

echo -e "total outer connection:"
netstat -anp |grep -e '831[1-7]' |grep EST|grep -v '127.0.0.1'|wc -l  2> /dev/null
