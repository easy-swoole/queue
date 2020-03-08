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

    function listen(callable $call, float $breakTime = 0.01,float $waitTime = 3.0, int $maxCurrency=128)
    {
        $this->enableListen = true;
        $running = 0;
        while ($this->enableListen){
            if ($running >= $maxCurrency) {
                continue;
            }
            $job = $this->driver->pop($waitTime);
            if($job){
                ++$running;
                Coroutine::create(function () use(&$running, $call, $job){
                    call_user_func($call,$job);
                    --$running;
                });
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
