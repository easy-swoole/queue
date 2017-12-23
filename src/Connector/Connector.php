<?php

namespace easySwoole\Queue\Connector;

use easySwoole\Queue\Contracts\Connector as ConnectorContracts;

/**
 * 队列驱动连接器
 * Class Connector
 * @author : evalor <master@evalor.cn>
 * @package easySwoole\Queue\Connector
 */
abstract class Connector implements ConnectorContracts
{
    protected $options = [];

    /**
     * 设置载荷的元信息
     * @param $payload
     * @param $key
     * @param $value
     * @author : evalor <master@evalor.cn>
     * @return mixed|string
     */
    protected function setMeta($payload, $key, $value)
    {
        $payload       = json_decode($payload, true);
        $payload[$key] = $value;
        $payload       = json_encode($payload);
        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new \InvalidArgumentException('Unable to create payload: ' . json_last_error_msg());
        }
        return $payload;
    }

    /**
     * 根据给定的作业和数据创建业务载荷数组
     * @param $Job
     * @param string $data
     * @author : evalor <master@evalor.cn>
     * @return string
     */
    protected function createPayload($Job, $data = '')
    {
        if (is_object($Job)) {
            $payload = json_encode([
                'job'  => 'easyswoole\Queue\CallQueuedHandler@call',
                'data' => [
                    'commandName' => get_class($Job),
                    'command'     => serialize(clone $Job),
                ],
            ]);
        } else {
            $payload = json_encode($this->createPlainPayload($Job, $data));
        }
        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new \InvalidArgumentException('Unable to create payload: ' . json_last_error_msg());
        }
        return $payload;
    }

    /**
     * 根据给定的作业和数据创建基础的载荷数组
     * @param $Job
     * @param $data
     * @author : evalor <master@evalor.cn>
     * @return array
     */
    protected function createPlainPayload($Job, $data)
    {
        return ['job' => $Job, 'data' => $data];
    }

    /**
     * 获取随机任务ID
     * @param int $length
     * @author : evalor <master@evalor.cn>
     * @return string
     */
    protected function getRandomId($length = 16)
    {
        $pool = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        return mb_substr(str_shuffle(str_repeat($pool, $length)), 0, $length, 'UTF-8');
    }

    /**
     * 将一个新任务推入到指定的队列中
     * @param string $queueName 队列名称
     * @param mixed $Job 任务类的名称或实例
     * @param string $data 携带给任务的数据
     * @author : evalor <master@evalor.cn>
     * @return mixed
     */
    function pushOn($queueName, $Job, $data = '')
    {
        return $this->push($Job, $data, $queueName);
    }

    /**
     * 将一个延时任务推入到指定的队列中
     * @param string $queueName 队列名称
     * @param \DateTimeInterface|\DateInterval|int $delay 延时的时间
     * @param mixed $Job 任务类的名称或实例
     * @param string $data 携带给任务的数据
     * @return mixed
     */
    function laterOn($queueName, $delay, $Job, $data = '')
    {
        return $this->later($delay, $Job, $data, $queueName);
    }

    /**
     * 将批量任务推入队列中
     * @param mixed $Jobs 任务类的名称或实例
     * @param string $data 携带给任务的数据
     * @param string $queueName 队列名称
     */
    function bulk($Jobs, $data = '', $queueName = null)
    {
        foreach ((array)$Jobs as $Job) {
            $this->push($Job, $data, $queueName);
        }
    }
}