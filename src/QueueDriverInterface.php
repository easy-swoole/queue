<?php


namespace EasySwoole\Queue;


interface QueueDriverInterface
{
    public function push(Job $job,float $timeout = 3.0): bool;

    public function pop(float $timeout = 3.0, array $params = []): ?Job;

    public function info(): ?array;

    public function confirm(Job $job,float $timeout = 3.0): bool;

    public function flush():bool;
}
