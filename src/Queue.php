<?php


namespace EasySwoole\Queue;


use EasySwoole\Utility\Random;

class Queue
{
    private $driver;
    private $nodeId;

    private $consumer;
    private $producer;

    function __construct(QueueDriverInterface $driver)
    {
        $this->driver = $driver;
        $this->nodeId = Random::character(6);
    }

    function queueDriver():QueueDriverInterface
    {
        return $this->driver;
    }

    function consumer(bool $renew = false):Consumer
    {
        if(!$renew){
            $this->consumer = new Consumer($this->driver);
        }
        if($this->consumer == null){
            $this->consumer = new Consumer($this->driver);
        }
        return $this->consumer;
    }

    function producer(bool $renew = false):Producer
    {
        if(!$renew){
            $this->producer = new Producer($this->driver, $this->nodeId);
        }
        if($this->producer == null){
            $this->producer = new Producer($this->driver, $this->nodeId);
        }
        return $this->producer;
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