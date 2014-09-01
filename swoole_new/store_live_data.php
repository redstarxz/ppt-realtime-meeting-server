<?php
require_once 'pheanstalk/pheanstalk_init.php';
class Live_Store
{
    protected $pheanstalk;

    private static $payload_type = array(
        'DI_GO_PAGE' => 'save_go_page',
        'DI_MOUSE_MOVE' => 'save_mouse_move',
        'DI_SAY' => 'save_say',
    );

    public function __construct()
    {
        $this->pheanstalk = new Pheanstalk_Pheanstalk('127.0.0.1');
        $this->mongo = new Mongo('127.0.0.1');
    }

    private function save_go_page($data)
    {
        // save to mongodb table meeting go page 
        $conn = $this->mongo->selectDB('ppt_live')->selectCollection('go_page');
        $conn->ensureIndex(
            array('MID' =>1, '_D_TIME' => 1), 
            array('unique' => false, 'backgroup' => true)
        );
        $conn->insert($data);
    }

    private function save_mouse_move($data)
    {
        // save to mongodb table meeting go page 
        $conn = $this->mongo->selectDB('ppt_live')->selectCollection('mouse_move');
        $conn->ensureIndex(
            array('MID' =>1, '_D_TIME' => 1), 
            array('unique' => false, 'backgroup' => true)
        );
        $conn->insert($data);
    }

    private function save_say($data)
    {
        // save to mongodb table meeting go page 
        $conn = $this->mongo->selectDB('ppt_live')->selectCollection('ppt_say');
        $conn->ensureIndex(
            array('MID' =>1, '_D_TIME' => 1), 
            array('unique' => false, 'backgroup' => true) 
        );
        $conn->insert($data);
    }

    public function get_job_from_queue()
    {
        // 阻塞读
        $job = $this->pheanstalk
            ->watch('live_ppt_payload')
            ->ignore('default')
            ->reserve();

        $data = json_decode($job->getData(), true);
        if (!isset($data['EVENT_TYPE'])) {
            $this->pheanstalk->delete($job);
            return;
        }
        $type = $data['EVENT_TYPE'];
        if (!isset(self::$payload_type[$type])) {
            $this->pheanstalk->delete($job);
            return;
        }
        $func = self::$payload_type[$type];
        try {
            $this->$func($data); 
            $this->pheanstalk->delete($job);
        } catch (Exception $e) {
            echo $e->getMessage().':'.json_encode($data), "\n";   
            $this->pheanstalk->release($job, 10);
        }
    }

}
$store = new Live_Store();
while (1) {
    $store->get_job_from_queue();
}

