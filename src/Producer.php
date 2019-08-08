<?php


namespace EasySwoole\Queue;


use Swoole\Atomic\Long;

class Producer
{
    private $atomic;
    private $driver;

    function __construct(QueueDriverInterface $driver,Long $atomic)
    {
        $this->atomic = $atomic;
        $this->driver = $driver;
    }

    function push(Job $job)
    {
        $id = $this->atomic->add(1);
        if($id > 0){
            $job->setJobId($id);
            $ret = $this->driver->push($job);
            if($ret){
                return $id;
            }
        }
        return 0;
    }
}