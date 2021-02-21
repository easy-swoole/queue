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

    function listen(callable $call, float $breakTime = 0.01, float $waitTime = 0.1, int $maxCurrency = 128, array $params = [])
    {
        $this->enableListen = true;
        $running = 0;
        while ($this->enableListen) {
            if ($running >= $maxCurrency) {
                Coroutine::sleep($breakTime);
                continue;
            }
            $job = $this->driver->pop($waitTime, $params);
            if ($job) {
                ++$running;
                Coroutine::create(function () use (&$running, $call, $job) {
                    try {
                        call_user_func($call, $job);
                    } catch (\Throwable $throwable) {
                        throw $throwable;
                    } finally {
                        --$running;
                    }
                });
            } else {
                Coroutine::sleep($breakTime);
            }
        }
    }

    function stopListen(): Consumer
    {
        $this->enableListen = false;
        return $this;
    }
}
