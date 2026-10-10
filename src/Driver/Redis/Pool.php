<?php

namespace EasySwoole\Queue\Driver\Redis;

use EasySwoole\Pool\AbstractPool;
use EasySwoole\Pool\ObjectInterface;

class Pool extends AbstractPool
{

    protected function createObject(): ObjectInterface
    {
        $config = $this->getConfig();

        if ($config->getMode() === Config::MODE_CLUSTER) {
            return new ClusterClient(
                $config->getPersistentId(),
                $config->getSeeds(),
                $config->getConnectTimeout(),
                $config->getReadTimeout(),
                $config->isPersistent(),
                $config->getAuth(),
                $config->getContext()
            );
        }

        $client = new Client();
        $connectMethod = $config->isPersistent() ? 'pconnect' : 'connect';
        $connected = $client->{$connectMethod}(
            $config->getHost(),
            $config->getPort(),
            $config->getConnectTimeout(),
            $config->getPersistentId(),
            $config->getRetryInterval(),
            $config->getReadTimeout(),
            $config->getContext()
        );

        if (!$connected) {
            throw new \RuntimeException('failed to connect to redis server');
        }

        $auth = $config->getAuth();
        if ($auth !== null && !$client->auth($auth)) {
            $client->close();
            throw new \RuntimeException('redis authentication failed');
        }

        if ($config->getDatabase() !== 0 && !$client->select($config->getDatabase())) {
            $client->close();
            throw new \RuntimeException('failed to select redis database');
        }

        return $client;
    }
}
