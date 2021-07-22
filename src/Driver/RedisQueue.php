<?php


namespace EasySwoole\Queue\Driver;


use EasySwoole\Queue\Job;
use EasySwoole\Queue\QueueDriverInterface;
use EasySwoole\Redis\Config\RedisConfig;
use EasySwoole\Redis\Redis;
use EasySwoole\RedisPool\Pool;
use Swoole\Coroutine;

class RedisQueue implements QueueDriverInterface
{
    protected $pool;
    protected $queueName;
    protected $lastCheckDelay = null;

    public function __construct(RedisConfig $config,string $queueName = 'es_q')
    {
        $this->pool = new Pool($config);
        $this->queueName = $queueName;
    }

    public function push(Job $job,float $timeout = 3.0): bool
    {
        if($job->getDelayTime() > 0){
            return $this->pool->invoke(function ($redis)use($job){
                /** @var $redis Redis */
                return $redis->zAdd("{$this->queueName}_d",time() + $job->getDelayTime(),serialize($job));
            },$timeout);
        }else{
            return $this->pool->invoke(function($redis)use($job){
                /** @var $redis Redis */
                return $redis->rPush($this->queueName,serialize($job));
            },$timeout);
        }
    }

    public function pop(float $timeout = 3.0, array $params = []): ?Job
    {
        //检查当前秒数的延迟任务是否存在未执行任务。
        if($this->lastCheckDelay != time()){
            $this->lastCheckDelay = time();
            Coroutine::create(function ()use($timeout){
                $this->pool->invoke(function ($redis){
                    /** @var $redis Redis */
                    $list = $redis->zCount("{$this->queueName}_d",0,$this->lastCheckDelay);
                    if($list > 0){
                        $jobs = $redis->zPopmin("{$this->queueName}_d",$list);
                        if(is_array($jobs)){
                            foreach ($jobs as $tempJob => $time){
                                if($time > $this->lastCheckDelay){
                                    $redis->zAdd("{$this->queueName}_d",$time,$tempJob);
                                }else{
                                    //插入到队列头
                                    $redis->lPush($this->queueName,$tempJob);
                                }
                            }
                        }
                    }
                },$timeout);
            });
        }
        $job = $this->pool->invoke(function ($redis)use($params){
            /** @var $redis Redis */
            if(isset($params['waitTime'])){
                return $redis->bLPop($this->queueName,$params['waitTime']);
            }
            return $redis->lPop($this->queueName);
        },$timeout);
        if($job){
            $job = unserialize($job);
        }
        if(!$job instanceof Job){
            return null;
        }
        //需要确认的任务
        if($job->getRetryTimes() != 0){
            //到达最大的执行次数
            if($job->getRunTimes() >= $job->getRetryTimes()){
                $this->pool->invoke(function ($redis)use($job){
                    /** @var $redis Redis */
                    $redis->hDel("{$this->queueName}_c",$job->getJobId());
                },$timeout);
                return null;
            }
            //如果不是第一次执行
            if($job->getRunTimes() !== 0){
                $hashConfirm = $this->pool->invoke(function ($redis)use($job){
                    /** @var $redis Redis */
                    return $redis->hGet("{$this->queueName}_c",$job->getJobId());
                },$timeout);
                //说明该任务已经被确认删除
                if($hashConfirm != 1){
                    return null;
                }
            }
            //丢到延迟队列中。
            $temp = clone $job;
            $temp->setRunTimes($temp->getRunTimes() + 1);
            $temp->setDelayTime($temp->getWaitConfirmTime());
            $this->push($temp);
            //标记为待确认。
            $this->pool->invoke(function ($redis)use($temp){
                /** @var $redis Redis */
                $redis->hSet("{$this->queueName}_c",$temp->getJobId(),1);
            },$timeout);
        }
        $job->setRunTimes($job->getRunTimes() + 1);
        return $job;
    }

    public function confirm(Job $job,float $timeout = 3.0): bool
    {
        if($job->getRetryTimes() != 0){
            $this->pool->invoke(function ($redis)use($job){
                /** @var $redis Redis */
                $redis->hDel("{$this->queueName}_c",$job->getJobId());
            });
            return true;
        }else{
            return false;
        }
    }


    public function info(): ?array
    {
        return $this->pool->invoke(function ($redis){
            /** @var $redis Redis */
            return [
                'runningQueue'=>$redis->lLen($this->queueName),
                'delayQueue'=>$redis->zCard("{$this->queueName}_d")
            ];
        });
    }

    public function flush():bool
    {
        $this->pool->invoke(function ($redis){
            /** @var $redis Redis */
            $redis->del("{$this->queueName}_c");
            $redis->del("{$this->queueName}_d");
            $redis->del("{$this->queueName}");
        });
        return true;
    }
}
