<?php

namespace EasySwoole\Queue\Driver\Redis;

use EasySwoole\Queue\AbstractInterface\QueueDriverInterface;
use EasySwoole\Queue\Job;
use Swoole\Coroutine;

class Redis implements QueueDriverInterface
{
    protected Config $config;
    protected bool $hasInit = false;
    protected string $topicName;
    protected ?string $nodeId = null;
    protected Pool $pool;
    protected ?int $lastCheckDelay = null;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function init(string $topicName, ?string $nodeId): bool
    {
        if ($this->hasInit) {
            return true;
        }

        $this->topicName = $topicName;
        $this->nodeId = $nodeId;
        $this->pool = new Pool($this->config);
        $this->hasInit = true;
        return true;
    }

    public function push(Job $job, float $timeout = 3.0): bool
    {
        $payload = serialize($job);

        if ($job->getDelayTime() > 0) {
            return $this->invoke(
                fn (Client|ClusterClient $redis): bool => $redis->zAdd(
                    $this->delayQueueKey(),
                    time() + $job->getDelayTime(),
                    $payload
                ) !== false,
                $timeout
            );
        }

        return $this->invoke(
            fn (Client|ClusterClient $redis): bool => $redis->rPush(
                $this->runningQueueKey(),
                $payload
            ) !== false,
            $timeout
        );
    }

    public function pop(float $timeout = 3.0, ?array $params = null): ?Job
    {
        $this->moveDueJobs($timeout);

        $payload = $this->invoke(function (Client|ClusterClient $redis) use ($params) {
            if (isset($params['waitTime'])) {
                $result = $redis->blPop($this->runningQueueKey(), $params['waitTime']);
                if (!is_array($result)) {
                    return false;
                }

                return $result[array_key_last($result)] ?? false;
            }

            return $redis->lPop($this->runningQueueKey());
        }, $timeout);

        if (!is_string($payload)) {
            return null;
        }

        $job = unserialize($payload);
        if (!$job instanceof Job) {
            return null;
        }

        if ($job->getRetryTimes() !== 0) {
            if ($job->getRunTimes() >= $job->getRetryTimes()) {
                $this->invoke(
                    fn (Client|ClusterClient $redis) => $redis->hDel(
                        $this->confirmQueueKey(),
                        $job->getJobId()
                    ),
                    $timeout
                );
                return null;
            }

            if ($job->getRunTimes() !== 0) {
                $pending = $this->invoke(
                    fn (Client|ClusterClient $redis) => $redis->hGet(
                        $this->confirmQueueKey(),
                        $job->getJobId()
                    ),
                    $timeout
                );
                if ($pending != 1) {
                    return null;
                }
            }

            $retryJob = clone $job;
            $retryJob->setRunTimes($retryJob->getRunTimes() + 1);
            $retryJob->setDelayTime($retryJob->getWaitConfirmTime());
            $this->push($retryJob, $timeout);

            $this->invoke(
                fn (Client|ClusterClient $redis) => $redis->hSet(
                    $this->confirmQueueKey(),
                    $retryJob->getJobId(),
                    1
                ),
                $timeout
            );
        }

        $job->setRunTimes($job->getRunTimes() + 1);
        return $job;
    }

    public function info(): ?array
    {
        return $this->invoke(fn (Client|ClusterClient $redis): array => [
            'runningQueue' => $redis->lLen($this->runningQueueKey()),
            'delayQueue' => $redis->zCard($this->delayQueueKey()),
        ]);
    }

    public function confirm(Job $job, float $timeout = 3.0): bool
    {
        if ($job->getRetryTimes() === 0) {
            return false;
        }

        return $this->invoke(
            fn (Client|ClusterClient $redis): bool => $redis->hDel(
                $this->confirmQueueKey(),
                $job->getJobId()
            ) !== false,
            $timeout
        );
    }

    public function flush(): bool
    {
        return $this->invoke(function (Client|ClusterClient $redis): bool {
            foreach ([$this->confirmQueueKey(), $this->delayQueueKey(), $this->runningQueueKey()] as $key) {
                if ($redis->del($key) === false) {
                    return false;
                }
            }

            return true;
        });
    }

    public function __clone()
    {
        if ($this->hasInit) {
            throw new \RuntimeException('Redis queue driver init clone not supported');
        }
    }

    protected function moveDueJobs(float $timeout): void
    {
        $now = time();
        if ($this->lastCheckDelay === $now) {
            return;
        }
        $this->lastCheckDelay = $now;

        Coroutine::create(function () use ($now, $timeout): void {
            $this->invoke(function (Client|ClusterClient $redis) use ($now): void {
                $count = $redis->zCount($this->delayQueueKey(), 0, $now);
                if ($count <= 0) {
                    return;
                }

                $jobs = $redis->zPopMin($this->delayQueueKey(), $count);
                if (!is_array($jobs)) {
                    return;
                }

                foreach ($jobs as $payload => $runAt) {
                    if ($runAt > $now) {
                        $redis->zAdd($this->delayQueueKey(), $runAt, $payload);
                        continue;
                    }
                    $redis->lPush($this->runningQueueKey(), $payload);
                }
            }, $timeout);
        });
    }

    protected function invoke(callable $callback, ?float $timeout = null): mixed
    {
        if (!$this->hasInit) {
            throw new \RuntimeException('Redis queue driver has not been initialized');
        }

        return $this->pool->invoke($callback, $timeout);
    }

    protected function runningQueueKey(): string
    {
        return $this->topicName;
    }

    protected function delayQueueKey(): string
    {
        return "{$this->topicName}_d";
    }

    protected function confirmQueueKey(): string
    {
        return "{$this->topicName}_c";
    }
}
