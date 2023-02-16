<?php

namespace EasySwoole\Queue\Driver\Pool;

use EasySwoole\Pool\AbstractPool;
use EasySwoole\Redis\Config;
use EasySwoole\Redis\Redis;
use EasySwoole\Redis\RedisCluster;

class RedisPool extends AbstractPool
{
    protected function createObject()
    {
        /** @var Config $config */
        $config = $this->getConfig()->getExtraConf();
        if($config instanceof RedisCluster){
            $info = new RedisCluster($config);
            $info->connect();
            return $info;
        }else{
            $info = new Redis($config);
            $info->connect();
            return $info;
        }
    }

    /**
     * @param int|null $num
     * @return int
     * 屏蔽在定时周期检查的时候，出现连接创建出错，导致进程退出。
     */
    public function keepMin(?int $num = null): int
    {
        try{
            return parent::keepMin($num);
        }catch (\Throwable $throwable){
            /** @var Config $config */
            $config = $this->getConfig()->getExtraConf();
            trigger_error("redis connection {$config->getHost()}:{$config->getPort()} ".$throwable->getMessage());
            return $this->status(true)['created'];
        }
    }

    /**
     * @param Redis $item
     * @return bool
     */
    protected function itemIntervalCheck($item): bool
    {
        if(time() - $item->__lastPingTime > 10){
            try{
                return $item->ping();
            }catch (\Throwable $throwable){
                //异常说明该链接出错了，return false 进行回收
                return false;
            }
        }else{
            return true;
        }
    }
}