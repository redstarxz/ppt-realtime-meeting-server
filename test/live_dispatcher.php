<?php
require_once '3party/Wrench-2.0/lib/SplClassLoader.php';
require_once '3party/log4php/Logger.php';
require_once 'pheanstalk/pheanstalk_init.php';
$script_dir = dirname(__FILE__);
Logger::configure("$script_dir/config/dispatcher_log4php.xml");
$classLoader = new SplClassLoader('Wrench', '3party/Wrench-2.0/lib');
$classLoader->register();
require_once '3party/Wrench-2.0/lib/Wrench/Client.php';

ini_set('memory_limit','12800M');

class Dispatcher {
    public static $worker = array(
        8311 => 'ws://127.0.0.1:8311/live_slave',
        8313 => 'ws://127.0.0.1:8313/live_slave',
        8314 => 'ws://127.0.0.1:8314/live_slave',
        8315 => 'ws://127.0.0.1:8315/live_slave',
        8316 => 'ws://127.0.0.1:8316/live_slave',
        8317 => 'ws://127.0.0.1:8317/live_slave',
    );

    public static $queue = null;

    public static $conn_pool = array();

    public static $g_logger = null;

    const USE_TUBE = true;
}

foreach (Dispatcher::$worker as $k => $wr) {
    $ws = new Wrench\Client($wr, 'http://admin.drsoon.com');
    $ws->connect();
    Dispatcher::$conn_pool[$k] = $ws;
}
Dispatcher::$queue = new Pheanstalk_Pheanstalk('127.0.0.1');
Dispatcher::$g_logger = Logger::getLogger('live');


$serv = new swoole_server("127.0.0.1", 8312);
$serv->set(array(
            'worker_num' => 8,
            'package_eof' => '\r\n\r\n',
            'open_eof_check' => 1,
            'daemonize' => true, //是否作为守护进程
            ));
$serv->on('connect', function ($serv, $fd){
    });
$serv->on('receive', function ($serv, $fd, $from_id, $payload) {
        Dispatcher::$g_logger->info('dispatch start:'.microtime(true));
        $data = json_decode($payload, true);
        $data['EVENT_TYPE'] = 'DI_'.$data['EVENT_TYPE'];
        $data['_D_TIME'] = microtime(true);
        $di_payload = json_encode($data);
	    // do dispatch	
        foreach (Dispatcher::$conn_pool as $k => $ws) {
            $ws->sendData($di_payload);
        }
        Dispatcher::$g_logger->info('dispatch end:'.microtime(true));
        // to queue
        if (Dispatcher::USE_TUBE) {
            Dispatcher::$queue->useTube('live_ppt_payload')->put($di_payload);
        }
        Dispatcher::$g_logger->info('dispatch queue end:'.microtime(true));
    });
$serv->on('close', function ($serv, $fd) {
    echo "Client: Close.\n";
    });
$serv->start();
