<?php


namespace EasySwoole\Queue;


class Job
{
    protected $jobData;
    protected $jobId;
    protected $nodeId;
    protected $delayTime = 0;

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
        $this->delayTime = $delayTime;
    }
}
