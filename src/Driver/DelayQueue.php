<?php
/**
 * @CreateTime:   2021/1/12 11:24 下午
 * @Author:       huizhang  <2788828128@qq.com>
 * @Copyright:    copyright(2020) Easyswoole all rights reserved
 * @Description:  延迟队列
 */

namespace EasySwoole\Queue\Driver;

use EasySwoole\Queue\Exception\Exception;
use EasySwoole\Queue\Job;
use EasySwoole\Queue\QueueDriverInterface;
use EasySwoole\Redis\Redis;
use EasySwoole\Redis\Response;
use EasySwoole\RedisPool\Pool;

class DelayQueue implements QueueDriverInterface
{
    protected $pool;
    protected $queueName;
    private $scriptSha1;

    public function __construct(Pool $pool, string $queueName = 'delay_queue')
    {
        $this->pool = $pool;
        $this->queueName = $queueName;
    }

    public function push(Job $job): bool
    {
        $res = $this->pool->invoke(function (Redis $redis) use ($job) {
            $data = serialize($job);
            return $redis->zAdd($this->queueName, time(), $data);
        });
        return !empty($res);
    }

    public function pop(float $timeout = 3.0, array $params = []): ?Job
    {
        if (!is_numeric($params['delay_time'])) {
            throw new Exception('The delay_time must be numeric!');
        }
        if ($params['delay_time'] < 0) {
            throw new Exception('The delay_time must be greater than or equal to 0!');
        }

        return $this->pool->invoke(function (Redis $redis) use ($params) {
            $job = null;
            if (empty($this->scriptSha1)) {
                $script = <<<EOF
local jobs = redis.call('ZRANGEBYSCORE', KEYS[1], '-inf', ARGV[1], 'LIMIT', 0, 1);if #jobs > 0 then  redis.call('ZREM', KEYS[1], unpack(jobs));  return jobs;else return {};end
EOF;
                $loadResult = $redis->rawCommand(['SCRIPT', 'LOAD', $script]);
                $this->scriptSha1 = $loadResult->getData();
            }
            /** @var $data Response */
            $data = $redis->rawCommand(['EVALSHA', $this->scriptSha1, 1, $this->queueName, time() - $params['delay_time']]);
            if ($data->getStatus() === 0) {
                $jobs = $data->getData();
            }
            if (count($jobs) === 1) {
                $job = unserialize($jobs[0]);
            }
            return $job;
        });
    }

    public function size(): ?int
    {
        return $this->pool->invoke(function (Redis $redis) {
            return $redis->zCount($this->queueName, 1, time()+100);
        });
    }
}
