<?php

namespace EasySwoole\Queue\Driver\RabbitMQ;

use EasySwoole\Pool\ObjectInterface;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;

/** RabbitMQ 连接对象，每个连接分别维护发布和消费 Channel。 */
class Client implements ObjectInterface
{
    private AMQPStreamConnection $connection;
    private AMQPChannel $publishChannel;
    private AMQPChannel $consumeChannel;
    private array $declaredTopics = [];
    private int $heartbeat;

    public function __construct(Config $config)
    {
        $this->heartbeat = $config->getHeartbeat();
        $this->connection = new AMQPStreamConnection(
            $config->getHost(),
            $config->getPort(),
            $config->getUsername(),
            $config->getPassword(),
            $config->getVirtualHost(),
            false,
            'AMQPLAIN',
            null,
            'en_US',
            $config->getConnectTimeout(),
            $config->getReadWriteTimeout(),
            $config->getContext() === [] ? null : $config->getContext(),
            $config->isKeepalive(),
            $config->getHeartbeat()
        );

        $this->publishChannel = $this->connection->channel();
        // Publisher Confirm 用于确认消息已经被 RabbitMQ Broker 接收。
        $this->publishChannel->confirm_select();
        $this->consumeChannel = $this->connection->channel();
    }

    public function publishChannel(): AMQPChannel
    {
        return $this->publishChannel;
    }

    public function consumeChannel(): AMQPChannel
    {
        return $this->consumeChannel;
    }

    /** 超时重投使用事务 Channel 后，重建消费 Channel，避免后续 ACK 滞留在事务中。 */
    public function resetConsumeChannel(): void
    {
        if ($this->consumeChannel->is_open()) {
            $this->consumeChannel->close();
        }
        $this->consumeChannel = $this->connection->channel();
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
            if (isset($this->publishChannel) && $this->publishChannel->is_open()) {
                $this->publishChannel->close();
            }
        } catch (\Throwable) {
        }
        try {
            if (isset($this->consumeChannel) && $this->consumeChannel->is_open()) {
                $this->consumeChannel->close();
            }
        } catch (\Throwable) {
        }
        try {
            if (isset($this->connection) && $this->connection->isConnected()) {
                $this->connection->close();
            }
        } catch (\Throwable) {
        }
    }

    public function objectRestore(): void
    {
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
            if (!$this->connection->isConnected()
                || !$this->publishChannel->is_open()
                || !$this->consumeChannel->is_open()
            ) {
                return false;
            }

            if ($this->heartbeat > 0
                && microtime(true) - $this->connection->getLastActivity() >= $this->heartbeat
            ) {
                // 仅对达到心跳周期的闲置连接执行真实 AMQP 往返；它会处理已经到达的
                // heartbeat/关闭帧，同时避免给持续活跃的连接增加逐次借用开销。
                $this->publishChannel->basic_qos(null, 0, false);
            }

            // heartbeat 开启时会检查 Broker 活跃时间并按需发送心跳；
            // 检查失败抛出的异常会使连接被 Pool 淘汰。
            $this->connection->checkHeartBeat();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
