<?php

namespace EasySwoole\Queue\Driver\RabbitMQ;

use EasySwoole\AMQP\AMQP;
use EasySwoole\AMQP\Channel;
use EasySwoole\AMQP\Config as AmqpConfig;
use EasySwoole\Pool\ObjectInterface;

/** RabbitMQ 连接对象，每个连接分别维护发布和消费 Channel。 */
class Client implements ObjectInterface
{
    private AMQP $connection;
    private Channel $publishChannel;
    private ?Channel $consumeChannel = null;
    private float $lastUsedAt;
    private array $declaredTopics = [];

    public function __construct(private Config $config)
    {
        $this->connection = new AMQP(new AmqpConfig(
            host: $config->getHost(),
            port: $config->getPort(),
            user: $config->getUsername(),
            password: $config->getPassword(),
            vhost: $config->getVirtualHost(),
            connectTimeout: $config->getConnectTimeout(),
            readTimeout: $config->getReadWriteTimeout(),
            writeTimeout: $config->getReadWriteTimeout(),
            heartbeat: $config->getHeartbeat(),
            tls: $config->getTls(),
        ));

        try {
            $this->connection->connect();
            $this->publishChannel = $this->connection->channel();
            $this->lastUsedAt = microtime(true);
        } catch (\Throwable $error) {
            $this->gc();
            throw $error;
        }
    }

    public function publishChannel(): Channel
    {
        return $this->publishChannel;
    }

    public function consumeChannel(): Channel
    {
        return $this->consumeChannel ??= $this->connection->channel();
    }

    public function hasDeclaredTopic(string $topic, int $generation): bool
    {
        return ($this->declaredTopics[$topic] ?? null) === $generation;
    }

    public function markTopicDeclared(string $topic, int $generation): void
    {
        $this->declaredTopics[$topic] = $generation;
    }

    public function gc(): void
    {
        try {
            $this->connection->close();
        } catch (\Throwable) {
        }
    }

    public function objectRestore(): void
    {
        $this->lastUsedAt = microtime(true);
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
        if (!$this->connection->isConnected()) {
            return false;
        }

        $heartbeat = $this->config->getHeartbeat();
        if ($heartbeat > 0 && microtime(true) - $this->lastUsedAt >= $heartbeat) {
            try {
                $this->publishChannel->qos(0);
                $this->lastUsedAt = microtime(true);
            } catch (\Throwable) {
                return false;
            }
        }

        return true;
    }
}
