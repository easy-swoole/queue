<?php


namespace EasySwoole\Queue;


class RedisDriver implements QueueDriverInterface
{

    public function push(Job $job): bool
    {
        // TODO: Implement push() method.
    }

    public function pop(float $timeout = 3.0): ?Job
    {
        // TODO: Implement pop() method.
    }

    public function size(): ?int
    {
        // TODO: Implement size() method.
    }
}