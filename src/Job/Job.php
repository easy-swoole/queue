<?php

namespace easySwoole\Queue\Job;

use easySwoole\Queue\Contracts\Job as JobContracts;

abstract class Job implements JobContracts
{
    protected $queueName;
    protected $instance;
    protected $deleted  = false;
    protected $released = false;
    protected $failed   = false;

    /**
     * 获取任务的原始载荷信息
     * @author : evalor <master@evalor.cn>
     * @return string
     */
    abstract function getRawBody();

    /**
     * 删除任务
     * @author : evalor <master@evalor.cn>
     */
    public function delete()
    {
        $this->deleted = true;
    }

    /**
     * 任务是否被删除
     * @author : evalor <master@evalor.cn>
     * @return bool
     */
    public function isDeleted()
    {
        return $this->deleted;
    }

    /**
     * 重新发布任务
     * @param int $delay
     * @author : evalor <master@evalor.cn>
     */
    public function release($delay = 0)
    {
        $this->released = true;
    }

    /**
     * 是否重新发布
     * @author : evalor <master@evalor.cn>
     * @return bool
     */
    public function isReleased()
    {
        return $this->released;
    }

    /**
     * 是否删除或重新发布
     * @author : evalor <master@evalor.cn>
     * @return bool
     */
    public function isDeletedOrReleased()
    {
        return $this->isDeleted() || $this->isReleased();
    }

    /**
     * 确认任务是否标记为失败
     * @return bool
     */
    public function hasFailed()
    {
        return $this->failed;
    }

    /**
     * 将任务标记为失败
     * @return void
     */
    public function markAsFailed()
    {
        $this->failed = true;
    }

    /**
     * 获取任务的载荷文本
     * @author : evalor <master@evalor.cn>
     * @return array
     */
    function payload()
    {
        return json_decode($this->getRawBody(), true);
    }

    /**
     * 执行任务的失败处理
     * @param \Throwable $e
     * @author : evalor <master@evalor.cn>
     * @throws \Exception
     */
    function failed($e)
    {
        $this->markAsFailed();
        list($class, $method) = $this->parseJob($this->getName());
        $this->instance = $this->resolve($class);
        if ($this->instance && method_exists($this->instance, 'failed')) {
            $this->instance->failed($this->payload()['data'], $e);
        }
    }

    /**
     * 获取任务所属的队列名称
     * @return string
     */
    function getName()
    {
        return $this->payload()['job'];
    }

    /**
     * 解析并执行任务
     * @author : evalor <master@evalor.cn>
     * @param array $payload
     * @return string|void
     * @throws \Exception
     */
    function resolveAndFire(array $payload)
    {
        list($class, $method) = $this->parseJob($payload['job']);
        $this->instance = $this->resolve($class);
        if ($this->instance) {
            $this->instance->{$method}($this, $this->payload()['data']);
        }
    }

    /**
     * 解析任务的执行类
     * @param $Job
     * @author : evalor <master@evalor.cn>
     * @return array
     */
    function parseJob($Job)
    {
        $segments = explode('@', $Job);
        return count($segments) > 1 ? $segments : [$segments[0], 'fire'];
    }

    /**
     * 解析任务
     * @param $name
     * @author : evalor <master@evalor.cn>
     * @return mixed
     * @throws \Exception
     */
    protected function resolve($name)
    {
        if (class_exists($name)) return new $name();
        throw new \Exception('Job handler ', $name . ' not found');
    }

    /**
     * Get the name of the queue the job belongs to.
     * @return string
     */
    function getQueue()
    {
        return $this->queueName;
    }
}