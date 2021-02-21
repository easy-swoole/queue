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

    public function __construct(RedisConfig $config,string $queueName = 'easy_queue')
    {
        $this->pool = new Pool($config);
        $this->queueName = $queueName;
    }

    public function push(Job $job): bool
    {
        // TODO: Implement push() method.
    }

    public function pop(float $timeout = 3.0, array $params = []): ?Job
    {
        // TODO: Implement pop() method.
    }

    public function size(): ?int
    {
        // TODO: Implement size() method.
    }
}