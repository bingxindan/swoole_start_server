#!/bin/sh

project=$2
root=$2
swoolepid="swoole.pid"
env=$(echo $3 | sed 's/[0-9]//g')
flag=$(echo $3 | sed 's/[^0-9]//g')

script="/Users/lauren/work/phpweb/swoole_start_server/lib/Server.php"
pidfile="/home/work${flag}/${root}/var/${swoolepid}"
pid=""

getPid () {
	pid=""
	if [ -f "$pidfile" ]; then
	    pid=$(cat $pidfile)
	fi
}

start () {
	getPid

    if [ -z "$pid" ]; then
    	echo "Starting : begin"
        /usr/local/php7/bin/php $script start $1 $2
        echo "Starting : finish"
    else
        echo "Starting : running"
    fi
}

stop () {
	getPid

    if [ -z "$pid" ]; then
        echo "Stopping : no master"
    else
    	echo "Stopping : begin"
        /usr/local/php7/bin/php $script stop $1 $2
        pids=$(ps aux | grep Swoole | grep $1$2 | awk '$0 = $2')
        for pid in $pids ; do
            kill -9 $pid
        done
        echo "Stopping : finish"
    fi
}

case "$1" in
  start)
    start $project $flag
    ;;
  stop)
    stop $project $flag
    ;;
  restart)
    stop $project $flag
    sleep 1.5
    start $project $flag
    ;;
  *)
    echo $"Usage: $0 {start|stop} project flag"
    ;;
esac
