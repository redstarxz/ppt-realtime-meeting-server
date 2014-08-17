<?php
require_once 'pheanstalk/pheanstalk_init.php';
while (1) {
    get_job_from_queue();
}

    function get_job_from_queue()
    {
        $pheanstalk = new Pheanstalk_Pheanstalk('127.0.0.1');
        // 阻塞读
        $job = $pheanstalk
            ->watch('live_ppt_payload')
            ->ignore('default')
            ->reserve();
            $pheanstalk->delete($job);
    }
        
