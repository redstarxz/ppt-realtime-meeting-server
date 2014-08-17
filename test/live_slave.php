<?php
require '3party/ratchet/autoload.php';
require_once '3party/Wrench-2.0/lib/SplClassLoader.php';
require_once '3party/log4php/Logger.php';
$script_dir = dirname(__FILE__);
Logger::configure("$script_dir/config/slave_log4php.xml");
$classLoader = new SplClassLoader('Wrench', '3party/Wrench-2.0/lib');
$classLoader->register();
require_once '3party/Wrench-2.0/lib/Wrench/Client.php';

ini_set('memory_limit','12800M');
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class Slave implements MessageComponentInterface {
    protected $_clients;
    protected $g_logger;

    private static $last_x = array();
    private static $last_y = array();

    private static $last_page_num = array();

    private static $map = array(
            'CHECK_IN' => 'check_in',
            'SAY' => 'say_sendToDispatcher',
            'GO_PAGE' => 'sendToDispatcher',
            'MOUSE_MOVE' => 'sendToDispatcher',
            'LOGOUT' => 'sendToDispatcher',

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
    }

    private function get_member_list($d_data, ConnectionInterface $from)
    {
        $mid = $d_data['MID'];
        $ret = array();
        $room = isset($this->_clients[$mid]) ? $this->_clients[$mid] : array();
        foreach ($room as $drno => $info) {
            $ret[] = array('drno' => $drno, 'name' => $info['name'], 'is_speaker' => 0);
        }
        $from->send(json_encode($ret));
        $from->close();
        return;
    }

    private function say_sendToDispatcher($d_data, ConnectionInterface $from)
    {
        $mid = $d_data['MID'];
        $id = $d_data['DRNO'];
        $d_data['NAME'] = $this->_clients[$mid][$id]['name'];
        $this->sendToDispatcher($d_data, $from);
    }

    private function sendToDispatcher($payload, ConnectionInterface $from)
    {
		$this->g_logger->info('slave2dis begin:'. microtime(true));
        // short link to dis, do dis connect
        $this->ws = new swoole_client(SWOOLE_TCP|SWOOLE_KEEP);
        $this->ws->connect('127.0.0.1', 8312, 0.5);
        $this->ws->send(json_encode($payload));
		$this->g_logger->info('slave2dis end:'. microtime(true));
    }

	public function onOpen(ConnectionInterface $conn) {
    }

    public function onMessage(ConnectionInterface $from, $data) {
		$this->g_logger->info("slave WS onMessage $data");
		$d_data = json_decode($data, true);
		$et = $d_data["EVENT_TYPE"];

        if (isset(self::$map[$et])) {
            $func = self::$map[$et];
            $this->$func($d_data, $from);
            return;
        }
    }

    private function broadcast($mid, $payload)
    {
        $room = isset($this->_clients[$mid]) ? $this->_clients[$mid] : array();
        foreach ($room as $drno => $info) {
            $conn = $info['conn_fd'];
            // deal conn failed and unset
            try {
                $conn->send($payload);
            } catch (Exception $e) {
                $this->g_logger->info("WS slave conn force close: ".$e->getMessage());
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
            $conn->close();
            $this->_clients[$mid][$id] = array('name' => $username, 'conn_fd' => $from);
        } else {
            $this->_clients[$mid][$id] = array('name' => $username, 'conn_fd' => $from);
            $this->sendToDispatcher($d_data, $from);
        }
        // send mouse x, y to from
        $this->sendLastPosToNewer($d_data, $from);
        // send last ppt page to from
        $this->sendLastPageToNewer($d_data, $from);
        return;
    }

    private function sendLastPageToNewer($d_data, ConnectionInterface $from)
    {
        $mid = $d_data['MID'];
        $page = isset(self::$last_page_num[$mid]) ? self::$last_page_num[$mid] : 1;
        $payload = array('EVENT_TYPE' => 'GO_PAGE', 'PAGE' => $page, 'TIME' => time());
        $from->send(json_encode($payload));
        return;
    }

    private function sendLastPosToNewer($d_data, ConnectionInterface $from)
    {
        $mid = $d_data['MID'];
        $x = isset(self::$last_x[$mid]) ? self::$last_x[$mid] : 0;
        $y = isset(self::$last_y[$mid]) ? self::$last_y[$mid] : 0;
        $payload = array('EVENT_TYPE' => 'MOUSE_MOVE', 'X' => $x, 'Y' => $y, 'TIME' => time());
        $from->send(json_encode($payload));
        return;
    }

    private function do_check_in($d_data, ConnectionInterface $from)
    {
        $et = substr($d_data['EVENT_TYPE'], 3);
        $username = $d_data['NAME'];
        $mid = $d_data['MID'];
        $drno = $d_data['DRNO'];
        $payload = array('EVENT_TYPE' => $et, 'NAME' =>$username, 'DRNO' =>$drno,  'TIME' => time());
        $this->broadcast($mid, json_encode($payload));
        return;
    }

    private function do_say($d_data, ConnectionInterface $from)
    {
        $et = substr($d_data['EVENT_TYPE'], 3);
        $text= $d_data["TEXT"];
        $username = $d_data['NAME'];
        $mid = $d_data['MID'];
        $payload = array('EVENT_TYPE' => $et, 'NAME' =>$username,  'TEXT' => $text, 'TIME' => time());
        $this->broadcast($mid, json_encode($payload));
        return;
    }

    private function do_go_page($d_data, ConnectionInterface $from)
    {
        $et = substr($d_data['EVENT_TYPE'], 3);
        $page = $d_data["PAGE"];
        $time = $d_data['T'];
        $mid = $d_data['MID'];
        self::$last_page_num[$mid] = $page;
        $payload = array('EVENT_TYPE' => $et, 'PAGE' => $page, 'TIME' => $time);
        $this->broadcast($mid, json_encode($payload));
        return;
    }

    private function do_mouse_move($d_data, ConnectionInterface $from)
    {
        $et = substr($d_data['EVENT_TYPE'], 3);
        $time = $d_data['T'];
        $mid = $d_data['MID'];
        self::$last_x[$mid] = $d_data['X'];
        self::$last_y[$mid] = $d_data['Y'];
        $payload = array('EVENT_TYPE' => $et, 'X' => $d_data['X'], 'Y' => $d_data['Y'], 'TIME' => $time);
        $this->broadcast($mid, json_encode($payload));
        return;
    }

    private function do_leave_meeting($d_data, ConnectionInterface $from)
    {
        $et = substr($d_data['EVENT_TYPE'], 3);
        $mid = $d_data['MID'];
        $drno = $d_data['DRNO'];
        $payload = array('EVENT_TYPE' => $et, 'NAME' => $d_data['NAME'], 'TIME' => time());
        $this->broadcast($mid, json_encode($payload));
        $this->close_client_by_drno($drno, $mid, $from);
        return;
    }

    private function do_set_speaker($d_data, ConnectionInterface $from)
    {
        $mid = $d_data['MID'];
        $drno = $d_data['DRNO'];
        if (isset($this->_clients[$mid][$drno])) {
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

    private function close_client_by_drno($drno, $mid, ConnectionInterface $from)
    {
        if (isset($this->_clients[$mid][$drno])) {
            unset($this->_clients[$mid][$drno]);
            $from->close();
        }
        return true;
    }

    public function onClose(ConnectionInterface $conn) {
        $this->close_client($conn);
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
		$this->g_logger->info("WS onError".$e->getMessage());
        $this->close_client($conn);
    }

    private function close_client($client)
    {
		foreach ($this->_clients as $mid => $room) {
            foreach ($room as $drno => $info) {
                $conn = $info['conn_fd'];
                if ($conn === $client) {
                    $this->g_logger->info("slave WS close client drno:$drno");
                    unset($this->_clients[$mid][$drno]);
                }
            }
        }
        $client->close();
    }

}
if ($argc != 2) {
    die('usage: php  xxx.php port(int)');
}
if (!is_numeric($argv[1])) {
    die('usage: php  xxx.php port(int)');
}
$port = intval($argv[1]);
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;

	$server = IoServer::factory(
        new WsServer(
            new Slave()
        )
      , $port
    );

    $server->run();
