<?php


namespace EasySwoole\Queue\Driver;


use EasySwoole\Queue\Job;
use EasySwoole\Queue\QueueDriverInterface;

class Redis implements QueueDriverInterface
{

    public function push(Job $job): bool
    {

    }

    public function pop(float $timeout = 3.0): ?Job
    {

    }

    public function size(): ?int
    {

    }
}