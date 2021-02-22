<?php


namespace EasySwoole\Queue;


use EasySwoole\Utility\Random;

class Queue
{
    private $driver;
    private $nodeId;

    function __construct(QueueDriverInterface $driver)
    {
        $this->driver = $driver;
        $this->nodeId = Random::character(6);
    }

    function queueDriver():QueueDriverInterface
    {
        return $this->driver;
    }

    function consumer():Consumer
    {
        return new Consumer($this->driver);
    }

    function producer():Producer
    {
        return new Producer($this->driver, $this->nodeId);
    }

    function info():?array
    {
        return $this->driver->info();
    }

    /**
     * @return bool|string
     */
    public function getNodeId()
    {
        return $this->nodeId;
    }

    /**
     * @param bool|string $nodeId
     */
    public function setNodeId($nodeId): void
    {
        $this->nodeId = $nodeId;
    }
}