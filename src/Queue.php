<?php


namespace EasySwoole\Queue;


use EasySwoole\Queue\AbstractInterface\QueueDriverInterface;
use EasySwoole\Queue\Exception\Exception;
use EasySwoole\Utility\Random;

class Queue
{
    private QueueDriverInterface $driver;
    private string $nodeId;

    private array $consumer = [];
    private array $producer = [];

    function __construct(QueueDriverInterface $driver)
    {
        $this->driver = $driver;
        $this->nodeId = Random::character(6);
    }

    function queueDriver():QueueDriverInterface
    {
        return $this->driver;
    }

    function consumer(string $topic):Consumer
    {
        if(!isset($this->consumer[$topic])){
            $driver = clone $this->driver;
            if(!$driver->init($topic,$this->nodeId)){
                throw new Exception("init queue topic:{$topic} driver fail");
            }
            $temp = new Consumer($driver);
            $this->consumer[$topic] = $temp;
        }
        return $this->consumer[$topic];
    }

    function producer(string $topic):Producer
    {
        if(!isset($this->producer[$topic])){
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


    public function getNodeId():string
    {
        return $this->nodeId;
    }

    public function setNodeId(string $nodeId): void
    {
        $this->nodeId = $nodeId;
    }
}