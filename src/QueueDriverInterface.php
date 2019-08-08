<?php


namespace EasySwoole\Queue;


interface QueueDriverInterface
{
    public function push(Job $job):bool ;

    public function pop(float $timeout = 3.0):?Job;

    public function size():?int ;
}