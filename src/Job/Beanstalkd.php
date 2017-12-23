<?php

namespace easySwoole\Queue\Job;

use Pheanstalk\Pheanstalk;
use Pheanstalk\Job as PheanstalkJob;

/**
 * Class Beanstalkd
 * @author : evalor <master@evalor.cn>
 * @package easySwoole\Queue\Job
 */
class Beanstalkd extends Job
{
    /* @var Pheanstalk */
    protected $Pheanstalk;
    /* @var PheanstalkJob */
    protected $PheanstalkJob;

    /**
     * Beanstalkd constructor.
     * @param Pheanstalk $Pheanstalk instance
     * @param PheanstalkJob $Job
     * @param $queueName
     */
    function __construct(Pheanstalk $Pheanstalk, PheanstalkJob $Job, $queueName)
    {
        $this->Pheanstalk    = $Pheanstalk;
        $this->PheanstalkJob = $Job;
        $this->queueName     = $queueName;
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
     */
    function release($delay = 0)
    {
        parent::release($delay);
        $this->Pheanstalk->release($this->PheanstalkJob, Pheanstalk::DEFAULT_PRIORITY, $delay);
    }

    /**
     * 休眠一个任务
     * @author : evalor <master@evalor.cn>
     */
    function bury()
    {
        $this->Pheanstalk->bury($this->PheanstalkJob);
    }

    /**
     * 删除一个任务
     * @author : evalor <master@evalor.cn>
     */
    function delete()
    {
        parent::delete();
        $this->Pheanstalk->delete($this->PheanstalkJob);
    }

    /**
     * 获取任务的重试次数
     * @author : evalor <master@evalor.cn>
     * @return int
     */
    function attempts()
    {
        $stats = $this->Pheanstalk->statsJob($this->PheanstalkJob);
        return (int)$stats->reserves;
    }

    /**
     * 获取任务ID
     * @author : evalor <master@evalor.cn>
     * @return int|string
     */
    function getJobId()
    {
        return $this->PheanstalkJob->getId();
    }

    /**
     * 获取任务的原始载荷信息
     * @author : evalor <master@evalor.cn>
     * @return string
     */
    function getRawBody()
    {
        return $this->PheanstalkJob->getData();
    }

    /**
     * 获取当前的 Pheanstalk 实例
     * @author : evalor <master@evalor.cn>
     * @return Pheanstalk
     */
    function getPheanstalk()
    {
        return $this->Pheanstalk;
    }

    /**
     * 获取当前的 PheanstalkJob 实例
     * @author : evalor <master@evalor.cn>
     */
    function getPheanstalkJob()
    {
        return $this->PheanstalkJob;
    }
}