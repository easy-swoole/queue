<?php

namespace easySwoole\Queue\Connector;

use easySwoole\Queue\Contracts\Job;
use easySwoole\Queue\Job\Redis as RedisJob;
use Predis\Client;

/**
 * Redis 队列驱动
 * Class Redis
 * @author : evalor <master@evalor.cn>
 * @package easySwoole\Queue\Connector
 */
class Redis extends Connector
{
    /* @var Client $instance */
    protected $instance = null;

    protected $options = [
        'default'    => 'default',
        'host'       => '127.0.0.1',
        'password'   => '',
        'ttr'        => 60,
        'port'       => 6379,
        'select'     => 0,
        'timeout'    => 5,
        'persistent' => true
    ];

    /**
     * 初始化 Redis 驱动
     * Beanstalkd constructor.
     * @param array $options
     */
    function __construct($options = [])
    {
        if (!empty($options)) {
            $this->options = array_merge($this->options, $options);
        }
    }

    /**
     * 获得 Predis 驱动实例
     * @author : evalor <master@evalor.cn>
     */
    private function connect()
    {
        if (is_null($this->instance)) {
            $parameters = [
                'scheme'     => 'tcp',
                'host'       => $this->options['host'],
                'port'       => $this->options['port'],
                'persistent' => $this->options['persistent'],
                'timeout'    => $this->options['timeout']
            ];
            if ($this->options['select'] != 0) $parameters['database'] = $this->options['select'];
            if ($this->options['password'] != '') $parameters['password'] = $this->options['password'];
            $this->instance = new Client($parameters);
        }
    }

    /**
     * 解析队列名称
     * @param string $queueName 队列名称
     * @author : evalor <master@evalor.cn>
     * @return mixed
     */
    private function resolveName($queueName)
    {
        $name = $queueName ?: $this->options['default'];
        return 'queues:' . $name;
    }

    /**
     * 移除过期任务
     * @param string $from 需要移除的key
     * @param int $time 超时时间戳
     * @author : evalor <master@evalor.cn>
     */
    protected function removeExpiredJobs($from, $time)
    {
        $this->instance->zremrangebyscore($from, '-inf', $time);
    }

    /**
     * 重新发布到期任务
     * @param string $to 需要移动到的队列
     * @param mixed $jobs 任务
     * @param bool $attempt 是否增加重试次数
     * @author : evalor <master@evalor.cn>
     * @return bool|int
     */
    protected function pushExpiredJobsOntoNewQueue($to, $jobs, $attempt = true)
    {
        if (!is_array($jobs)) return false;
        if ($attempt) {
            foreach ($jobs as &$job) {
                $attempts = json_decode($job, true)['attempts'];
                $job      = $this->setMeta($job, 'attempts', $attempts + 1);
            }
        }
        return $this->instance->rpush($to, $jobs);
    }

    /**
     * 获取所有到期任务
     * @param string $from 需要获取的key
     * @param int $time 超时时间戳
     * @author : evalor <master@evalor.cn>
     * @return array
     */
    protected function getExpiredJobs($from, $time)
    {
        return $this->instance->zrangebyscore($from, '-inf', $time);
    }

    /**
     * 移动延迟任务
     * @param string $from 移出的集合
     * @param string $to 移入的集合
     * @param bool $attempt 是否增加重试次数
     * @author : evalor <master@evalor.cn>
     */
    protected function migrateExpiredJobs($from, $to, $attempt = true)
    {
        $this->instance->watch($from);
        $jobs = $this->getExpiredJobs($from, $time = time());
        if (count($jobs) > 0) {
            $this->instance->transaction(function () use ($from, $to, $time, $jobs, $attempt) {
                $this->removeExpiredJobs($from, $time);
                $this->pushExpiredJobsOntoNewQueue($to, $jobs, $attempt);
            });
        }
        $this->instance->unwatch();
    }

    /**
     * 创建任务载荷
     * @param $job
     * @param string $data
     * @param null $queue
     * @author : evalor <master@evalor.cn>
     * @return mixed|string
     */
    protected function createPayload($job, $data = '', $queue = null)
    {
        $payload = $this->setMeta(parent::createPayload($job, $data), 'id', $this->getRandomId());
        return $this->setMeta($payload, 'attempts', 1);
    }

    /**
     * 获取队列中当前等待执行的任务数
     * @param string $queueName 队列名称
     * @return int 任务数量
     */
    function size($queueName = null)
    {
        $this->connect();
        return (int)$this->instance->llen($this->resolveName($queueName));
    }

    /**
     * 将一个新任务推入到队列中
     * @param mixed $job 任务类的名称或实例
     * @param string $data 携带给任务的数据
     * @param null|string $queueName 队列名称
     * @author : evalor <master@evalor.cn>
     * @return mixed
     */
    function push($job, $data = '', $queueName = null)
    {
        return $this->pushRaw($this->createPayload($job, $data), $queueName);
    }

    /**
     * 向队列推送原始的载荷数据
     * @param string $payload 任务载荷
     * @param string $queueName 队列名称
     * @param array $options 额外的设置
     * @return mixed
     */
    function pushRaw($payload, $queueName = null, array $options = [])
    {
        $this->connect();
        $this->instance->rpush($this->resolveName($queueName), (array)$payload);
        return json_decode($payload, true)['id'];
    }

    /**
     * 将一个延时任务推入到队列中
     * @param int $delay 延时的时间
     * @param mixed $job 任务类的名称或实例
     * @param string $data 携带给任务的数据
     * @param string $queueName 队列名称
     * @return mixed
     */
    function later($delay, $job, $data = '', $queueName = null)
    {
        return $this->laterRaw($delay, $this->createPayload($job, $data), $queueName);
    }

    /**
     * 推送延迟任务的Payload
     * @param $delay
     * @param $payload
     * @param null $queueName
     * @author : evalor <master@evalor.cn>
     * @return null
     */
    function laterRaw($delay, $payload, $queueName = null)
    {
        $this->connect();
        $this->instance->zadd($this->resolveName($queueName) . ':delayed', time() + $delay, $payload);
        $id = json_decode($payload, true)['id'];
        return $id ? $id : null;
    }

    /**
     * 从队列中取出一个任务
     * @param string $queueName 队列名称
     * @author : evalor <master@evalor.cn>
     * @return Job|null
     */
    function pop($queueName = null)
    {
        $this->connect();
        $original  = $queueName ?: $this->options['default'];
        $queueName = $this->resolveName($queueName);

        // 检查延迟任务移动到执行队列
        $this->migrateExpiredJobs($queueName . ':delayed', $queueName, false);

        // 如果设置了超时时间 强制回收执行中的任务
        if (!is_null($this->options['ttr'])) $this->migrateExpiredJobs($queueName . ':reserved', $queueName);

        // 取出一个任务并将任务放入执行中集合
        $Job = $this->instance->lpop($queueName);
        if (!is_null($Job) && $Job !== false && json_decode($Job) && !is_int(json_decode($Job))) {
            $this->instance->zadd($queueName . ':reserved', time() + $this->options['ttr'], $Job);
            return new RedisJob($this, $Job, $original);
        }
        return null;
    }

    /**
     * 删除执行中的任务
     * @param string $queueName 队列名称
     * @param string $job 任务载荷文本内容
     * @author : evalor <master@evalor.cn>
     */
    function deleteReserved($queueName, $job)
    {
        $this->connect();
        $this->instance->zrem($this->resolveName($queueName) . ':reserved', $job);
    }

    /**
     * 重新发布任务
     * @param string $queueName 队列名称
     * @param string $payload 任务载荷
     * @param int $delay 延迟发布
     * @param int $attempts 重试次数
     * @author : evalor <master@evalor.cn>
     */
    public function release($queueName, $payload, $delay, $attempts)
    {
        $this->connect();
        $payload = $this->setMeta($payload, 'attempts', $attempts);
        $this->instance->zadd($this->resolveName($queueName) . ':delayed', time() + $delay, $payload);
    }

    /**
     * 获取当前的驱动实例
     * @author : evalor <master@evalor.cn>
     * @return Client
     */
    function GetConnector()
    {
        $this->connect();
        return $this->instance;
    }
}