<?php

namespace easySwoole\Queue\Contracts;

/**
 * 连接器类需要实现的契约
 * Interface Connector
 * @author : evalor <master@evalor.cn>
 * @package easySwoole\Queue\Contracts
 */
interface Connector
{
    /**
     * 获取队列中当前等待执行的任务数
     * @param string $queueName 队列名称
     * @return int 任务数量
     */
    function size($queueName = null);

    /**
     * 将一个新任务推入到队列中
     * @param mixed $job 任务类的名称或实例
     * @param string $data 携带给任务的数据
     * @param null|string $queueName 队列名称
     * @author : evalor <master@evalor.cn>
     * @return mixed
     */
    function push($job, $data = '', $queueName = null);

    /**
     * 将一个新任务推入到指定的队列中
     * @param string $queueName 队列名称
     * @param mixed $job 任务类的名称或实例
     * @param string $data 携带给任务的数据
     * @author : evalor <master@evalor.cn>
     * @return mixed
     */
    function pushOn($queueName, $job, $data = '');

    /**
     * 向队列推送原始的载荷数据
     * @param string $payload 任务载荷
     * @param string $queueName 队列名称
     * @param array $options 额外的设置
     * @return mixed
     */
    function pushRaw($payload, $queueName = null, array $options = []);

    /**
     * 将一个延时任务推入到队列中
     * @param \DateTimeInterface|\DateInterval|int $delay 延时的时间
     * @param mixed $job 任务类的名称或实例
     * @param string $data 携带给任务的数据
     * @param string $queueName 队列名称
     * @return mixed
     */
    function later($delay, $job, $data = '', $queueName = null);

    /**
     * 将一个延时任务推入到指定的队列中
     * @param string $queueName 队列名称
     * @param \DateTimeInterface|\DateInterval|int $delay 延时的时间
     * @param mixed $job 任务类的名称或实例
     * @param string $data 携带给任务的数据
     * @return mixed
     */
    function laterOn($queueName, $delay, $job, $data = '');

    /**
     * 将批量任务推入队列中
     * @param mixed $jobs 任务类的名称或实例
     * @param string $data 携带给任务的数据
     * @param string $queueName 队列名称
     * @return mixed
     */
    function bulk($jobs, $data = '', $queueName = null);

    /**
     * 从队列中取出一个任务
     * @param string $queueName 队列名称
     * @author : evalor <master@evalor.cn>
     * @return Job|null
     */
    function pop($queueName = null);

    /**
     * 获取当前的驱动实例
     * @author : evalor <master@evalor.cn>
     * @return mixed
     */
    function GetConnector();
}