<?php
require '/var/www/oasis/php/vendor/autoload.php';
require_once '/var/www/oasis/php/common.php';
ini_set('memory_limit','12800M');
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class Chat implements MessageComponentInterface {
    protected $_clients;

    public function __construct() {
        $this->_clients = array();
    }

	public function onOpen(ConnectionInterface $conn) {
    }

	public function get_peer_sock_addr($c) {
		$conn_p = $c->getConnection();
		$conn = $conn_p->{'conn'};
		$w_flag = $conn->isWritable();
		$addr = $conn->getRemoteAddress();
		return $addr.":".$w_flag;
	}

	public function close_client($client) {
		global $g_logger;
		foreach ($this->_clients as $name => $c) {
            if ($c === $client) {
                $g_logger->info("WS close client :$name");
                unset($this->_clients[$name]);
            }
        }
		$client->close();
	}

    public function onMessage(ConnectionInterface $from, $data) {
		global $g_logger;
	//	$g_logger->info("WS onMessage $data");
		$d_data = json_decode($data, true);
		$et = $d_data["EVENT_TYPE"];
		if ("CHECK_IN" == $et) {
			$username = $d_data["NAME"];
            $g_logger->info("WS client check in: $username");
            $this->_clients[$username] = $from;

            $msg = array('EVENT_TYPE' => $et, 'NAME' => $username);
            foreach ($this->_clients as $key => $c) {
                $c->send(json_encode($msg));
            }
            return;
        }
		if ("GET_ONLINE_USER" == $et) {
            $names = array_keys($this->_clients);
            $msg = array('EVENT_TYPE' => $et, 'LIST' => $names); 
            $from->send(json_encode($msg));
            return;
        }
        if ('SAY' == $et) {
			$text= $d_data["TEXT"];
            $username = $d_data['NAME'];
            $payload = array('EVENT_TYPE' => $et, 'NAME' =>$username,  'TEXT' => $text, 'TIME' => time());
            foreach ($this->_clients as $key => $c) {
                $g_logger->info("WS send word".json_encode($payload));
                $c->send(json_encode($payload));
            }
            return;
        }

        if ('GO_PAGE' == $et) {
			$username = $d_data["NAME"];
			$page = $d_data["PAGE"];
            $payload = array('EVENT_TYPE' => $et, 'PAGE' => $page, 'TIME' => time());
            foreach ($this->_clients as $key => $c) {
                if ($key != $username) {
                    $g_logger->info("WS send page go".json_encode($payload));
                    $c->send(json_encode($payload));
                }
            }
            return;
        }

        if ('MOUSE_MOVE' == $et) {
            global $lasttime;
            if ($lasttime == 0) {
                $lasttime = microtime(true);
            } else {
                $now = microtime(true);
                $diff = ($now - $lasttime)*1000;
                if ($diff > 70 || $diff < 20) {
                    //$g_logger->info("WS send mouse move now $now, last $lasttime, diff:".$diff);
                }
                $lasttime = $now;
            }
			$username = $d_data["NAME"];
            $time = $d_data['T'];
            $payload = array('EVENT_TYPE' => $et, 'X' => $d_data['X'], 'Y' => $d_data['Y'], 'TIME' => $time);
            foreach ($this->_clients as $key => $c) {
                if ($key != $username) {
                    // stub
                    $c->send(json_encode($payload));
                }
            }
            return;
        }
    }

    public function onClose(ConnectionInterface $conn) {
		$this->close_client($conn);
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
		global $g_logger;
		$g_logger->info("WS onError".$e->getMessage());
		$this->close_client($conn);
    }
}

$lasttime = 0;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;

	$server = IoServer::factory(
        new WsServer(
            new Chat()
        )
      , 8311
    );

    $server->run();
