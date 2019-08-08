<?php


namespace EasySwoole\Queue;


use Swoole\Atomic\Long;

class Queue
{
    private $driver;
    private $atomic;

    function __construct(QueueDriverInterface $driver)
    {
        $this->driver = $driver;
        $this->atomic = new Long(0);
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
        return new Producer($this->driver,$this->atomic);
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
}