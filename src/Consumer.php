<?php


namespace EasySwoole\Queue;


use Swoole\Coroutine;

class Consumer
{
    private $driver;
    private $enableListen = false;

    function __construct(QueueDriverInterface $driver)
    {
        $this->driver = $driver;
    }

    function pop(float $timeout = 3.0):?Job
    {
        return  $this->driver->pop($timeout);
    }

    function listen(callable $call,float $breakTime = 0.01,float $waitTime = 3.0)
    {
        $this->enableListen = true;
        while ($this->enableListen){
            $job = $this->driver->pop($waitTime);
            if($job){
                call_user_func($call,$job);
            }else{
                Coroutine::sleep($breakTime);
            }
        }
    }

    function stopListen():Consumer
    {
        $this->enableListen = false;
        return $this;
    }
}