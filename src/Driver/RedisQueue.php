<?php


namespace EasySwoole\Queue\Driver;


use EasySwoole\Queue\Job;
use EasySwoole\Queue\QueueDriverInterface;
use EasySwoole\Redis\Config\RedisConfig;
use EasySwoole\RedisPool\Pool;

class RedisQueue implements QueueDriverInterface
{
    protected $pool;
    protected $queueName;
    protected $lastCheckDelay = null;

    public function __construct(RedisConfig $config,string $queueName = 'es_q')
    {
        $this->pool = new Pool($config);
        $this->queueName = $queueName;
    }

    public function push(Job $job): bool
    {
        if($job->getDelayTime() > 0){
            return $this->pool->invoke(function ($redis)use($job){
                /** @var $redis \EasySwoole\Redis\Redis */
                return $redis->zAdd("{$this->queueName}_d",time() + $job->getDelayTime(),serialize($job));
            },1);
        }else{
            return $this->pool->invoke(function($redis)use($job){
                /** @var $redis \EasySwoole\Redis\Redis */
                return $redis->rPush($this->queueName,serialize($job));
            },1);
        }
    }

    public function pop(float $timeout = 3.0, array $params = []): ?Job
    {
        //检查当前秒数的延迟任务是否存在未执行任务。
        if($this->lastCheckDelay != time()){
            $this->lastCheckDelay = time();
            //取出需要执行的，并放置到队列前面。
        }

    }

    public function info(): ?array
    {
        return [

        ];
    }
}