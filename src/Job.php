<?php


namespace EasySwoole\Queue;


use EasySwoole\Spl\SplBean;

class Job extends SplBean
{
    protected $jobData;
    protected $jobId;

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
}