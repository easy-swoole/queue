<?php


namespace EasySwoole\Queue;


use Swoole\Coroutine;

class Consumer
{
    private $driver;

    private $enableListen = false;
    private $onBreak;
    private $breakTime = 0.01;

    function __construct(QueueDriverInterface $driver)
    {
        $this->driver = $driver;
    }

    function pop(float $timeout = 3.0, array $params = []): ?Job
    {
        return $this->driver->pop($timeout, $params);
    }

    function listen(callable $call,array $params = [],?callable $onException = null)
    {
        $this->enableListen = true;
        while ($this->enableListen) {
            $job = null;
            try{
                $job = $this->driver->pop(0.1, $params);
                if ($job) {
                    call_user_func($call, $job);
                } else {
                    if($this->onBreak){
                        call_user_func($this->onBreak,$this);
                    }
                    Coroutine::sleep($this->breakTime);
                }
            }catch (\Throwable $throwable){
                if($onException){
                    call_user_func($onException,$throwable,$job);
                }else{
                    throw $throwable;
                }
            }
        }
    }

    function confirm(Job $job):bool
    {
        return $this->driver->confirm($job);
    }

    function stopListen(): Consumer
    {
        $this->enableListen = false;
        return $this;
    }

    function setOnBreak(callable $call): Consumer
    {
        $this->onBreak = $call;
        return $this;
    }

    function setBreakTime(float $time): Consumer
    {
        $this->breakTime = $time;
        return $this;
    }
}
