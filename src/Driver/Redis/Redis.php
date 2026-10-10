<?php

namespace EasySwoole\Queue\Driver\Redis;

use EasySwoole\Queue\AbstractInterface\QueueDriverInterface;
use EasySwoole\Queue\Job;

class Redis implements QueueDriverInterface
{

    public function init(string $topicName, ?string $nodeId): bool
    {
        // TODO: Implement init() method.
    }

    public function push(Job $job, float $timeout = 3.0): bool
    {
        // TODO: Implement push() method.
    }

    public function pop(float $timeout = 3.0, ?array $params = null): ?Job
    {
        // TODO: Implement pop() method.
    }

    public function info(): ?array
    {
        // TODO: Implement info() method.
    }

    public function confirm(Job $job, float $timeout = 3.0): bool
    {
        // TODO: Implement confirm() method.
    }

    public function flush(): bool
    {
        // TODO: Implement flush() method.
    }

    public function __clone()
    {
        // TODO: Implement __clone() method.
    }
}