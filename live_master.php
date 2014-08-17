<?php
require '3party/ratchet/autoload.php';
require_once '3party/Wrench-2.0/lib/SplClassLoader.php';
require_once '3party/log4php/Logger.php';
$script_dir = dirname(__FILE__);
Logger::configure("$script_dir/config/master_log4php.xml");
$classLoader = new SplClassLoader('Wrench', '3party/Wrench-2.0/lib');
$classLoader->register();
require_once '3party/Wrench-2.0/lib/Wrench/Client.php';

ini_set('memory_limit','12800M');
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class Master implements MessageComponentInterface {
    protected $_clients;
    protected $g_logger;
    protected $ws = null;

    private static $last_x = array();
    private static $last_y = array();

    private static $mouse_sample_ct = array();

    private static $map = array(
            'CHECK_IN' => 'check_in',
            'SAY' => 'say_sendToDispatcher',
            'GO_PAGE' => 'sendToDispatcher',
            'LOGOUT' => 'sendToDispatcher',
            'MOUSE_MOVE' => 'mouse_sendToDispatcher',

            'DI_CHECK_IN' => 'do_check_in',
            'DI_SAY' => 'do_say',
            'DI_GO_PAGE' => 'do_go_page',
            'DI_MOUSE_MOVE' => 'do_mouse_move',
            'DI_LOGOUT' => 'do_leave_meeting',

            'SET_SPEAKER' => 'do_set_speaker',
            'UNSET_SPEAKER' => 'do_unset_speaker',
            'MEMBER_LIST' => 'get_member_list',
        );
    public function __construct() {
        $this->_clients = array();
        $this->g_logger = Logger::getLogger('live');
        $this->ws = new Wrench\Client('ws://127.0.0.1:8312/live_dispatch', 'http://admin.drsoon.com');
    }

    public function __destruct()
    {
        if ($this->ws->isConnected()) {
            $this->ws->disconnect();
        }
    }

    private function get_member_list($d_data, ConnectionInterface $from)
    {
        $mid = $d_data['MID'];
        $ret = array();
        $room = isset($this->_clients[$mid]) ? $this->_clients[$mid] : array();
        foreach ($room as $drno => $info) {
            if (empty($info['status'])) {
                $ret[] = array('drno' => $drno, 'name' => $info['name'], 'is_speaker' => $info['is_speaker']);
            }
        }
        $from->send(json_encode($ret));
        // need close or it will wait 5s 
        $from->close();
        return;
    }

    private function say_sendToDispatcher($d_data, ConnectionInterface $from)
    {
        $mid = $d_data['MID'];
        $id = $d_data['DRNO'];
        $d_data['NAME'] = $this->_clients[$mid][$id]['name'];
        // do when exists
        if ($this->_clients[$mid][$id]['conn_fd'] === $from) {
            $this->sendToDispatcher($d_data, $from);
        }
    }

    private function mouse_sendToDispatcher($d_data, ConnectionInterface $from)
    {
        $mid = $d_data['MID'];
        if (isset(self::$last_x[$mid]) && isset(self::$last_y[$mid])) {
            if (!bccomp($d_data['X'], self::$last_x[$mid], 4) && 
                    !bccomp($d_data['Y'] , self::$last_y[$mid], 4)) {
                self::$mouse_sample_ct[$mid] += 1;
                if (self::$mouse_sample_ct[$mid] < 100)
                    return;
            }
        }
        // 保证鼠标坐标能传送到每个check in 
        self::$mouse_sample_ct[$mid] = 0;

        self::$last_x[$mid] = $d_data['X'];
        self::$last_y[$mid] = $d_data['Y'];
        $this->sendToDispatcher($d_data, $from);
    }

    private function sendToDispatcher($payload, ConnectionInterface $from)
    {
        // long time link to dis, if needed, do heaert beat check
        if (!$this->ws->isConnected()) {
            try {
                $this->ws->connect();
            } catch (Exception $e) {
                $this->g_logger->info('master2Dispatcher connect error:'.$e->getMessage());
            }
        }
        try {
            $ret = $this->ws->sendData(json_encode($payload));
            if ($ret === false) {
                $this->g_logger->info('master2Dispatcher send msg return flase');
                $this->ws->disconnect();
            }
        } catch (Exception $e) {
            $this->g_logger->info('master2Dispatcher send error:'.$e->getMessage());
            $this->ws->disconnect();
        }
    }

	public function onOpen(ConnectionInterface $conn) {
    }

    public function onMessage(ConnectionInterface $from, $data) {
		$this->g_logger->info("master WS onMessage $data");
		$d_data = json_decode($data, true);
		$et = $d_data["EVENT_TYPE"];
        if (isset(self::$map[$et])) {
            $func = self::$map[$et];
            // todo send raw data to reduce cpu json_encode
            $this->$func($d_data, $from);
            return;
        }
    }

    private function broadcast($mid, $payload)
    {
        $room = isset($this->_clients[$mid]) ? $this->_clients[$mid] : array();
        foreach ($room as $drno => $info) {
            $conn = $info['conn_fd'];
            try {
                $conn->send($payload);
            } catch (Exception $e) {
                $this->g_logger->info("WS master conn force close: ".$e->getMessage());
                $this->close_client_by_drno($drno, $mid, $conn);
            }
        }
    }

    private function check_in($d_data, ConnectionInterface $from)
    {
        $et = $d_data['EVENT_TYPE'];
        $id = $d_data["DRNO"];
        $username = $d_data["NAME"];
        $mid = $d_data['MID'];
        
        $this->g_logger->info("WS client check in: $username");
        if (isset($this->_clients[$mid][$id])) {
            $conn = $this->_clients[$mid][$id]['conn_fd'];
            // 只有客户主动关闭才会清理socket
            if (!empty($this->_clients[$mid][$id]['status'])) {
                $conn->close();
            }
            // 保持conn原有属性， 赋值新conn_fd
            $this->_clients[$mid][$id]['conn_fd'] = $from;
        } else {
            $this->_clients[$mid][$id] = array('name' => $username, 'conn_fd' => $from, 'is_speaker' => 1);
        }
        $this->_clients[$mid][$id]['status'] = 0;
        // 需要广播出去
        $d_data['IS_SPEAKER'] = 1;
        $this->sendToDispatcher($d_data, $from);
        return;
    }

    private function do_check_in($d_data, ConnectionInterface $from)
    {
        $et = substr($d_data['EVENT_TYPE'], 3);
        $d_data['EVENT_TYPE'] = $et;
        $d_data['TIME'] = time();
        $mid = $d_data['MID'];
        $this->broadcast($mid, json_encode($d_data));
        return;
    }
    private function do_say($d_data, ConnectionInterface $from)
    {
        $et = substr($d_data['EVENT_TYPE'], 3);
        $text= $d_data["TEXT"];
        $username = $d_data['NAME'];
        $mid = $d_data['MID'];
        $drno = $d_data['DRNO'];
        $payload = array('EVENT_TYPE' => $et, 'DRNO' =>$drno,  'NAME' =>$username,  'TEXT' => $text, 'TIME' => time());
        $this->broadcast($mid, json_encode($payload));
        return;
    }

    private function do_go_page($d_data, ConnectionInterface $from)
    {
        $et = substr($d_data['EVENT_TYPE'], 3);
        $page = $d_data["PAGE"];
        $time = $d_data['T'];
        $mid = $d_data['MID'];
        // put here or tick time?
        $payload = array('EVENT_TYPE' => $et, 'PAGE' => $page, 'TIME' => $time);
        $this->broadcast($mid, json_encode($payload));
        return;
    }

    private function do_mouse_move($d_data, ConnectionInterface $from)
    {
        $et = substr($d_data['EVENT_TYPE'], 3);
        $time = $d_data['T'];
        $mid = $d_data['MID'];
        $payload = array('EVENT_TYPE' => $et, 'X' => $d_data['X'], 'Y' => $d_data['Y'], 'TIME' => $time);
        $this->broadcast($mid, json_encode($payload));
        return;
    }

    // 这个from是dis, 不能close
    private function do_leave_meeting($d_data, ConnectionInterface $from)
    {
        $et = substr($d_data['EVENT_TYPE'], 3);
        $mid = $d_data['MID'];
        $drno = $d_data['DRNO'];
        $payload = array('EVENT_TYPE' => $et, 'DRNO' => $drno, 'NAME' => $d_data['NAME'], 'TIME' => time());
        $this->broadcast($mid, json_encode($payload));
        return;
    }

    private function do_set_speaker($d_data, ConnectionInterface $from)
    {
        $mid = $d_data['MID'];
        $drno = $d_data['DRNO'];
        if (isset($this->_clients[$mid][$drno])) {
            $this->g_logger->info("master set speaker".json_encode($d_data));
            $this->_clients[$mid][$drno]['is_speaker'] = 1;
        }
        $this->broadcast($mid, json_encode($d_data));
        $from->close();
        return;
    }

    private function do_unset_speaker($d_data, ConnectionInterface $from)
    {
        $mid = $d_data['MID'];
        $drno = $d_data['DRNO'];
        if (isset($this->_clients[$mid][$drno])) {
            $this->_clients[$mid][$drno]['is_speaker'] = 0;
        }
        $this->broadcast($mid, json_encode($d_data));
        $from->close();
        return;
    }

    public function onClose(ConnectionInterface $conn) {
        $this->close_client($conn);
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
		$this->g_logger->info("master WS onError:".$e->getMessage());
        // unset client 
        $this->close_client($conn);
    }

    private function close_client_by_drno($drno, $mid, ConnectionInterface $from)
    {
        if (isset($this->_clients[$mid][$drno])) {
            // 客户端的conn,只在异常情况下关闭
            $from->close();
        }
        return true;
    }

    private function close_client($client)
    {
		foreach ($this->_clients as $mid => $room) {
            foreach ($room as $drno => $info) {
                $conn = $info['conn_fd'];
                if ($conn === $client) {
                    $this->g_logger->info("master WS close client drno:$drno");
                    $payload = array('EVENT_TYPE' => 'LOGOUT', 'MID' => $mid, 'DRNO' => $drno, 'NAME' => $info['name'], 'IS_SPEAKER' => $info['is_speaker']);
                    $this->_clients[$mid][$drno]['status'] = 1;
                    $this->sendToDispatcher($payload, $client);
                    // 客户端主动退出或error, 关闭对应的socket
                    $conn->close();
                    // do not unset for info keep
                    //unset($this->_clients[$mid][$drno]);
                }
            }
        }
        // 这里需不需要再次close?
        //$client->close();
    }

}

use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;

	$server = IoServer::factory(
        new WsServer(
            new Master()
        )
      , 8311
    );

    $server->run();
