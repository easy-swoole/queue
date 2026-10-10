<?php

namespace EasySwoole\Queue\Driver\Redis;

use EasySwoole\Pool\ObjectInterface;

class ClusterClient extends \RedisCluster implements ObjectInterface
{
    public function gc(): void
    {
        $this->close();
    }

    public function objectRestore(): void
    {
        $this->clearLastError();
    }

    public function beforeUse(): bool
    {
        return $this->isHealthy();
    }

    public function intervalCheck(): bool
    {
        return $this->isHealthy();
    }

    private function isHealthy(): bool
    {
        try {
            return $this->ping('{easyswoole-queue}:health-check') !== false;
        } catch (\Throwable) {
            return false;
        }
    }
}
