<?php


namespace EasySwoole\Queue;


use EasySwoole\Utility\Random;
use Swoole\Atomic\Long;

class Queue
{
    private $driver;
    private $atomic;
    private $nodeId;

    function __construct(QueueDriverInterface $driver)
    {
        $this->driver = $driver;
        $this->atomic = new Long(0);
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
        return new Producer($this->driver, $this->atomic, $this->nodeId);
    }

    function size():?int
    {
        return $this->driver->size();
    }

    function currentJobId():int
    {
        return $this->atomic->get();
    }

    function setJobStartId(int $id):Queue
    {
        $this->atomic->set($id);
        return $this;
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