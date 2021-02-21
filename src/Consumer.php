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

    function listen(callable $call,array $params = [])
    {
        $this->enableListen = true;
        while ($this->enableListen) {
            $job = $this->driver->pop(0.1, $params);
            if ($job) {
                call_user_func($call, $job);
            } else {
                Coroutine::sleep(0.001);
            }
        }
    }

    function stopListen(): Consumer
    {
        $this->enableListen = false;
        return $this;
    }
}
