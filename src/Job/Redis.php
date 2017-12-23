<?php

namespace easySwoole\Queue\Job;

use easySwoole\Queue\Connector\Redis as RedisQueue;

/**
 * Class Redis
 * @author : evalor <master@evalor.cn>
 * @package easySwoole\Queue\Job
 */
class Redis extends Job
{
    /* @var RedisQueue */
    protected $Redis;

    protected $RedisJob;

    /**
     * Redis constructor.
     * @param RedisQueue $Redis
     * @param string $Job
     * @param string $queueName
     */
    function __construct(RedisQueue $Redis, $Job, $queueName)
    {
        $this->queueName = $queueName;
        $this->Redis     = $Redis;
        $this->RedisJob  = $Job;
    }

    /**
     * 解析并执行任务
     * @author : evalor <master@evalor.cn>
     * @throws \Exception
     */
    function fire()
    {
        $this->resolveAndFire(json_decode($this->getRawBody(), true));
    }

    /**
     * 将任务送回队列
     * @param int $delay
     * @author : evalor <master@evalor.cn>
     * @return mixed|void
     */
    function release($delay = 0)
    {
        parent::release($delay);
        $this->delete();
        $this->Redis->release($this->queueName, $this->RedisJob, $delay, $this->attempts() + 1);
    }

    /**
     * 获取任务的重试次数
     * @author : evalor <master@evalor.cn>
     * @return int
     */
    function attempts()
    {
        return json_decode($this->RedisJob, true)['attempts'];
    }

    /**
     * 删除任务
     * @author : evalor <master@evalor.cn>
     */
    function delete()
    {
        parent::delete();
        $this->Redis->deleteReserved($this->queueName, $this->RedisJob);
    }

    /**
     * 获取当前任务的ID
     * @author : evalor <master@evalor.cn>
     * @return string
     */
    function getJobId()
    {
        return json_decode($this->RedisJob, true)['id'];
    }

    /**
     * 获取任务的原始载荷信息
     * @author : evalor <master@evalor.cn>
     * @return string
     */
    function getRawBody()
    {
        return $this->RedisJob;
    }

    /**
     * 获取当前的 Predis 实例
     * @author : evalor <master@evalor.cn>
     * @return \Predis\Client
     */
    function getPredis()
    {
        return $this->Redis->GetConnector();
    }
}