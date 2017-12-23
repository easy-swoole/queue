<?php

namespace easySwoole\Queue\Contracts;

/**
 * 任务类需要实现的契约
 * Interface Job
 * @author : evalor <master@evalor.cn>
 * @package easySwoole\Queue\Contracts
 */
interface Job
{
    /**
     * 获取当前任务的ID
     * @author : evalor <master@evalor.cn>
     * @return string
     */
    function getJobId();

    /**
     * 获取当前任务解码后的载荷
     * @author : evalor <master@evalor.cn>
     * @return array
     */
    function payload();

    /**
     * 执行Job的处理逻辑
     * @return mixed
     */
    function fire();

    /**
     * 将任务重新发布到消息队列
     * @param  int $delay
     * @return mixed
     */
    function release($delay = 0);

    /**
     * 从消息队列中删除任务
     * @return void
     */
    function delete();

    /**
     * 确认任务是否有删除标记
     * @return bool
     */
    function isDeleted();

    /**
     * 确认任务是否有删除或重新发布标记
     * @return bool
     */
    function isDeletedOrReleased();

    /**
     * 获取任务当前已经重试的次数
     * @return int
     */
    function attempts();

    /**
     * 执行Job的失败处理逻辑
     * @param  \Throwable $e
     * @return void
     */
    function failed($e);

    /**
     * 获取Job处理类的类名
     * @return string
     */
    function getName();

    /**
     * 获取当前任务所在的队列名称
     * @return string
     */
    function getQueue();

    /**
     * 获取当前任务的原始文本内容
     * @return string
     */
    function getRawBody();
}