<?php
require '3party/ratchet/autoload.php';
require_once '3party/Wrench-2.0/lib/SplClassLoader.php';
require_once '3party/log4php/Logger.php';
require_once 'pheanstalk/pheanstalk_init.php';
$script_dir = dirname(__FILE__);
Logger::configure("$script_dir/config/dispatcher_log4php.xml");
$classLoader = new SplClassLoader('Wrench', '3party/Wrench-2.0/lib');
$classLoader->register();
require_once '3party/Wrench-2.0/lib/Wrench/Client.php';

ini_set('memory_limit','12800M');
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class Dispatcher implements MessageComponentInterface {
    protected $g_logger;
    private static $worker = array(
        8311 => 'ws://127.0.0.1:8311/live_master',
        8313 => 'ws://127.0.0.1:8313/live_slave',
        8314 => 'ws://127.0.0.1:8314/live_slave',
        8315 => 'ws://127.0.0.1:8315/live_slave',
        8316 => 'ws://127.0.0.1:8316/live_slave',
        8317 => 'ws://127.0.0.1:8317/live_slave',
    );

    private static $queue = null;

    private static $conn_pool = array();

    const USE_TUBE = true;

    public function __construct() {
        $this->g_logger = Logger::getLogger('live');
        // need to do heart beat 
        foreach (self::$worker as $k => $wr) {
            try { 
                $ws = new Wrench\Client($wr, 'http://admin.drsoon.com');
                $ws->connect();
                self::$conn_pool[$k] = $ws;
            } catch (Exception $e) {
                $this->g_logger->info("start dispatcher failed $wr,". $e->getMessage());
            }
        }
        // open conn to beanstalked
        $this->queue = new Pheanstalk_Pheanstalk('127.0.0.1');
    }

    public function  __destruct()
    {
        
    }

	public function onOpen(ConnectionInterface $conn) {
    }

    public function onMessage(ConnectionInterface $from, $payload) {
		$this->g_logger->info("Dispatcher WS onMessage $payload");
        $data = json_decode($payload, true);
        $data['EVENT_TYPE'] = 'DI_'.$data['EVENT_TYPE'];
        $data['_D_TIME'] = microtime(true);
        $di_payload = json_encode($data);
	    // do dispatch	
        foreach (self::$conn_pool as $k => &$ws) {
            if (!$ws->isConnected()) {
                try {
                    $ws->connect();
                } catch (Exception $e) {
                    $this->g_logger->info("Dispatcher connect to $k error:".$e->getMessage());
                }
            }
            try {
                $ret = $ws->sendData($di_payload);
                if ($ret === false) {
                    $this->g_logger->info("Dispatcher send to $k return false");
                    $ws->disconnect();
                }
            } catch (Exception $e) {
                $this->g_logger->info("Dispatcher send to $k error:".$e->getMessage());
                $ws->disconnect();
            }
        }
        // to queue
        if (self::USE_TUBE) {
            try {
                $this->queue->useTube('live_ppt_payload')->put($di_payload);
            } catch (Exception $e) {
                $this->g_logger->info("Dispatcher send to queue error:".$e->getMessage());
            }
        }
    }

    public function onClose(ConnectionInterface $conn) {
		$this->g_logger->info("dispatch WS on Close");
        $this->close_client($conn);
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
		$this->g_logger->info("dispatch WS onError:".$e->getMessage());
        $this->close_client($conn);
    }

    private function close_client($client)
    {
        $client->close();
    }

}

use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;

	$server = IoServer::factory(
        new WsServer(
            new Dispatcher()
        )
      , 8312
    );

    $server->run();
