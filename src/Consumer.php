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
                    Coroutine::sleep(0.001);
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
}
