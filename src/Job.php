<?php


namespace EasySwoole\Queue;


class Job
{
    protected mixed $jobData;
    protected string $jobId;
    protected string $nodeId;
    protected int $delayTime = 0;
    protected int $retryTimes = 0;
    protected int $runTimes = 0;
    protected int $waitConfirmTime = 10;
    protected ?string $queueReceiptId = null;

    /**
     * @return mixed
     */
    public function getJobData()
    {
        return $this->jobData;
    }

    /**
     * @param mixed $jobData
     */
    public function setJobData($jobData): void
    {
        $this->jobData = $jobData;
    }

    /**
     * @return mixed
     */
    public function getJobId()
    {
        return $this->jobId;
    }

    /**
     * @param mixed $jobId
     */
    public function setJobId($jobId): void
    {
        $this->jobId = $jobId;
    }

    /**
     * @return mixed
     */
    public function getNodeId():?string
    {
        return $this->nodeId;
    }

    /**
     * @param mixed $nodeId
     */
    public function setNodeId(?string $nodeId): void
    {
        $this->nodeId = $nodeId;
    }

    /**
     * @return int
     */
    public function getDelayTime(): int
    {
        return $this->delayTime;
    }

    /**
     * @param int $delayTime
     */
    public function setDelayTime(int $delayTime): void
    {
        if($delayTime <= 0){
            $delayTime = 3;
        }
        $this->delayTime = $delayTime;
    }

    /**
     * @return int
     */
    public function getRetryTimes(): int
    {
        return $this->retryTimes;
    }

    /**
     * @param int $retryTimes
     */
    public function setRetryTimes(int $retryTimes): void
    {
        $this->retryTimes = $retryTimes;
    }

    /**
     * @return int
     */
    public function getRunTimes(): int
    {
        return $this->runTimes;
    }

    /**
     * @param int $runTimes
     */
    public function setRunTimes(int $runTimes): void
    {
        $this->runTimes = $runTimes;
    }

    /**
     * @return int
     */
    public function getWaitConfirmTime(): int
    {
        return $this->waitConfirmTime;
    }

    /**
     * @param int $waitConfirmTime
     */
    public function setWaitConfirmTime(int $waitConfirmTime): void
    {
        $this->waitConfirmTime = $waitConfirmTime;
    }

    /**
     * Redis driver 内部使用的消费回执，业务代码无需设置。
     */
    public function getQueueReceiptId(): ?string
    {
        return $this->queueReceiptId;
    }

    public function setQueueReceiptId(?string $queueReceiptId): void
    {
        $this->queueReceiptId = $queueReceiptId;
    }
}
