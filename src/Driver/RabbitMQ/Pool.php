<?php

namespace EasySwoole\Queue\Driver\RabbitMQ;

use EasySwoole\Pool\AbstractPool;
use EasySwoole\Pool\ObjectInterface;

class Pool extends AbstractPool
{
    protected function createObject(): ObjectInterface
    {
        /** @var Config $config */
        $config = $this->getConfig();
        return new Client($config);
    }
}
