<?php

namespace easySwoole\Queue;

/**
 * 任务执行结果
 * Class FireResult
 * @author : evalor <master@evalor.cn>
 * @package easySwoole\Queue
 */
class FireResult
{
    protected $Job;
    protected $isFailed;

    /**
     * FireResult constructor.
     * @param null $Job
     * @param bool $isFailed
     */
    function __construct($Job = null, $isFailed = false)
    {
        $this->Job      = $Job;
        $this->isFailed = $isFailed;
    }

    function Job()
    {
        return $this->Job;
    }

    function isFailed()
    {
        return $this->isFailed;
    }
}