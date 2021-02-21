<?php


namespace EasySwoole\Queue;


use EasySwoole\Utility\Random;

class Producer
{
    private $driver;
    private $nodeId;

    function __construct(QueueDriverInterface $driver,string $nodeId)
    {
        $this->driver = $driver;
        $this->nodeId = $nodeId;
    }

    function push(Job $job):bool
    {
        if(empty($job->getJobId())){
            $job->setJobId(substr(md5(Random::character(8).$this->nodeId.microtime(true)),8,16));
        }
        $job->setNodeId($this->nodeId);
        return $this->driver->push($job);
    }
}