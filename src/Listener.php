<?php

namespace easySwoole\Queue;

use easySwoole\Queue\Contracts\Job as JobContracts;

/**
 * 任务监听器
 * Class Listener
 * @author : evalor <master@evalor.cn>
 * @package easySwoole\Queue
 */
class Listener
{
    protected $delay;
    protected $sleep;
    protected $tries;

    /**
     * Listener constructor.
     * @param int $delay 任务抛出异常且未被删除时 可以再次获取的延迟时间
     * @param int $sleep 如果队列中没有任务 休息多少秒后继续查询
     * @param int $tries 任务允许的失败次数上限 超过次数则执行失败逻辑
     */
    function __construct($delay = 0, $sleep = 3, $tries = 0)
    {
        $this->delay = $delay;
        $this->sleep = $sleep;
        $this->tries = $tries;
    }

    /**
     * 开始监听任务
     * @param null $queueName
     * @author : evalor <master@evalor.cn>
     * @return FireResult
     */
    function listen($queueName = null)
    {
        $Job = $this->fetchJob($queueName);
        if (!is_null($Job)) {
            return $this->fireJob($Job);
        }
        $this->sleep($this->sleep);
        return new FireResult();
    }

    /**
     * 获取下一任务
     * @param string|null $queueName 需要获取的队列名称
     * @author : evalor <master@evalor.cn>
     * @return JobContracts|null
     */
    protected function fetchJob($queueName)
    {
        if (!$queueName) return Queue::pop();
        foreach (explode(',', $queueName) as $name) {
            $Job = Queue::pop($name);
            if ($Job instanceof JobContracts) return $Job;
        }

        return null;
    }

    /**
     * 执行任务逻辑
     * @param JobContracts $Job
     * @author : evalor <master@evalor.cn>
     * @return FireResult
     */
    protected function fireJob(JobContracts $Job)
    {
        if ($this->tries > 0 && $Job->attempts() > $this->tries) {
            $this->failedJob($Job, new \Exception("Job {$Job->getJobId()} More than the maximum retrial times"));
        }

        try {
            $Job->fire();
            return new FireResult($Job, false);
        } catch (\Exception $e) {
            if (!$Job->isDeleted()) {
                $Job->release($this->delay);
            }
            return new FireResult($Job, true);
        }

    }

    /**
     * 任务失败逻辑处理
     * @param $Job
     * @param \Exception $e
     * @author : evalor <master@evalor.cn>
     * @return FireResult
     */
    protected function failedJob(JobContracts $Job, \Exception $e)
    {
        if (!$Job->isDeleted()) {
            $Job->delete();
            $Job->failed($e);
        }
        return new FireResult($Job, true);
    }

    /**
     * 休息指定的秒数
     * @param $seconds
     * @author : evalor <master@evalor.cn>
     */
    protected function sleep($seconds)
    {
        sleep($seconds);
    }
}