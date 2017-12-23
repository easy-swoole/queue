<?php

namespace easySwoole\Queue;

use easySwoole\Queue\Job\Job;

/**
 * 调用指定类的方法
 * Class CallQueuedHandler
 * @author : evalor <master@evalor.cn>
 * @package easySwoole\Queue
 */
class CallQueuedHandler
{
    /**
     * 调用任务的执行方法
     * @param Job $Job
     * @param array $data
     * @author : evalor <master@evalor.cn>
     */
    function call(Job $Job, array $data)
    {
        $command = unserialize($data['command']);
        call_user_func([$command, 'handle']);
        if (!$Job->isDeletedOrReleased()) {
            $Job->delete();
        }
    }

    /**
     * 调用任务的失败方法
     * @param array $data
     * @author : evalor <master@evalor.cn>
     */
    public function failed(array $data)
    {
        $command = unserialize($data['command']);
        if (method_exists($command, 'failed')) {
            $command->failed();
        }
    }
}