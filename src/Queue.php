<?php


namespace EasySwoole\Queue;


use EasySwoole\Queue\Exception\Exception;
use EasySwoole\Utility\Random;

class Queue
{
    private $driver;
    private $nodeId;

    private $consumer = [];
    private $producer = [];

    function __construct(QueueDriverInterface $driver)
    {
        $this->driver = $driver;
        $this->nodeId = Random::character(6);
    }

    function queueDriver():QueueDriverInterface
    {
        return $this->driver;
    }

    function consumer(string $topic,bool $renew = false):Consumer
    {
        if((!$renew) || !isset($this->consumer[$topic])){
            $driver = clone $this->driver;
            if(!$driver->init($topic,$this->nodeId)){
                throw new Exception("init queue topic:{$topic} driver fail");
            }
            $temp = new Consumer($driver);
            $this->consumer[$topic] = $temp;
        }
        return $this->consumer[$topic];
    }

    function producer(string $topic,bool $renew = false):Producer
    {
        if(($renew) || !isset($this->producer[$topic])){
            $driver = clone $this->driver;
            if(!$driver->init($topic,$this->nodeId)){
                throw new Exception("init queue topic:{$topic} driver fail");
            }
            $temp = new Producer($driver,$this->nodeId);
            $this->producer[$topic] = $temp;
        }
        return $this->producer[$topic];
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