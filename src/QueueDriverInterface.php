<?php


namespace EasySwoole\Queue;


interface QueueDriverInterface
{
    public function init(string $topicName,?string $nodeId):bool;
    public function push(Job $job,float $timeout = 3.0): bool;

    public function pop(float $timeout = 3.0, ?array $params = null): ?Job;

    public function info(): ?array;

    public function confirm(Job $job,float $timeout = 3.0): bool;

    public function flush():bool;

    public function __clone();
}
