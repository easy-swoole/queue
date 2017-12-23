<?php

namespace easySwoole\Queue\Connector;

use Pheanstalk\Pheanstalk;
use Pheanstalk\Job as PheanstalkJob;
use easySwoole\Queue\Job\Beanstalkd as BeanstalkdJob;

/**
 * Beanstalkd 队列驱动
 * Class Beanstalkd
 * @author : evalor <master@evalor.cn>
 * @package easySwoole\Queue\Connector
 */
class Beanstalkd extends Connector
{
    /* @var Pheanstalk $instance */
    protected $instance = null;

    protected $options = [
        'host'       => '127.0.0.1',
        'port'       => Pheanstalk::DEFAULT_PORT,
        'default'    => Pheanstalk::DEFAULT_TUBE,
        'ttr'        => Pheanstalk::DEFAULT_TTR,
        'timeout'    => null,
        'persistent' => true
    ];

    /**
     * 初始化 Beanstalkd 驱动
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
     * 获得 Pheanstalk 驱动实例
     * @author : evalor <master@evalor.cn>
     */
    private function connect()
    {
        if (is_null($this->instance)) {
            $this->instance = new Pheanstalk(
                $this->options['host'],
                $this->options['port'],
                $this->options['timeout'],
                $this->options['persistent']
            );
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
        return $queueName ?: $this->options['default'];
    }

    /**
     * 获取队列中当前等待执行的任务数
     * @param string $queueName 队列名称
     * @return int 任务数量
     */
    function size($queueName = null)
    {
        $this->connect();
        return (int)$this->instance->statsTube($this->resolveName($queueName))->current_jobs_ready;
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
        return $this->instance
            ->useTube($this->resolveName($queueName))
            ->put($payload, Pheanstalk::DEFAULT_PRIORITY, Pheanstalk::DEFAULT_DELAY, $this->options['ttr']);
    }

    /**
     * 将一个延时任务推入到队列中
     * @param \DateTimeInterface|\DateInterval|int $delay 延时的时间
     * @param mixed $job 任务类的名称或实例
     * @param string $data 携带给任务的数据
     * @param string $queueName 队列名称
     * @return mixed
     */
    function later($delay, $job, $data = '', $queueName = null)
    {
        $this->connect();
        return $this->instance
            ->useTube($this->resolveName($queueName))
            ->put($this->createPayload($job, $data), Pheanstalk::DEFAULT_PRIORITY, $delay, $this->options['ttr']);
    }

    /**
     * 从队列中取出一个任务
     * @param string $queueName 队列名称
     * @author : evalor <master@evalor.cn>
     * @return BeanstalkdJob|null
     */
    function pop($queueName = null)
    {
        $this->connect();
        $Job = $this->instance->watchOnly($this->resolveName($queueName))->reserve();
        if ($Job instanceof PheanstalkJob) {
            return new BeanstalkdJob($this->instance, $Job, $this->resolveName($queueName));
        }
        return null;
    }

    /**
     * 获取当前的驱动实例
     * @author : evalor <master@evalor.cn>
     * @return Pheanstalk
     */
    function GetConnector()
    {
        $this->connect();
        return $this->instance;
    }
}