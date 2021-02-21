<?php


namespace EasySwoole\Queue\Driver;


use EasySwoole\Queue\Job;
use EasySwoole\Queue\QueueDriverInterface;

class RedisQueue implements QueueDriverInterface
{
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