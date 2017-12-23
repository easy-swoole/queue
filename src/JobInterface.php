<?php

namespace easySwoole\Queue;

use easySwoole\Queue\Contracts\Job as JobContracts;

/**
 * 任务的实现类
 * Interface JobInterface
 * @author : evalor <master@evalor.cn>
 * @package easySwoole\Queue
 */
interface JobInterface
{
    /**
     * 执行任务的处理方法
     * @param JobContracts $Job
     * @param $data
     * @author : evalor <master@evalor.cn>
     * @return mixed
     */
    function fire(JobContracts $Job, $data);

    /**
     * 执行任务的失败处理
     * @param $data
     * @param \Exception $e
     * @author : evalor <master@evalor.cn>
     * @return mixed
     */
    function failed($data, \Exception $e);
}