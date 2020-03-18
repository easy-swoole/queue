<?php


namespace EasySwoole\Queue;


use Swoole\Atomic\Long;

class Producer
{
    private $atomic;
    private $driver;
    private $nodeId;

    function __construct(QueueDriverInterface $driver,Long $atomic,?string $nodeId = null)
    {
        $this->atomic = $atomic;
        $this->driver = $driver;
        $this->nodeId = $nodeId;
    }

    function push(Job $job,bool $init = true)
    {
        $id = $this->atomic->add(1);
        if($id > 0){
            if($init){
                $job->setJobId($id);
                $job->setNodeId($this->nodeId);
            }
            $ret = $this->driver->push($job);
            if($ret){
                return $id;
            }
        }
        return 0;
    }
}