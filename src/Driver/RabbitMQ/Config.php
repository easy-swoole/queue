<?php

namespace EasySwoole\Queue\Driver\RabbitMQ;

use EasySwoole\Pool\Exception\Exception;

/** RabbitMQ 连接、连接池和队列行为配置。 */
class Config extends \EasySwoole\Pool\Config
{
    /** 默认最大发布连接数；后台消费者使用独立连接，不占用此配额。 */
    protected int $maxObjectNum = 20;

    protected string $host = '127.0.0.1';
    protected int $port = 5672;
    protected string $username = 'guest';
    protected string $password = 'guest';
    protected string $virtualHost = '/';
    protected float $connectTimeout = 3.0;
    protected float $readWriteTimeout = 3.0;
    protected bool $keepalive = false;
    protected int $heartbeat = 0;
    protected array $context = [];
    protected float $popPollInterval = 0.05;
    protected string $queuePrefix = 'easyswoole.queue.';
    /** 单个消费 Channel 允许同时存在的未确认可信任务数。 */
    protected int $prefetchCount = 100;
    /** 从 RabbitMQ 接收到、等待 pop() 领取的本地任务缓冲区大小。 */
    protected int $consumerBufferSize = 1000;

    public function getHost(): string { return $this->host; }

    public function setHost(string $host): self
    {
        if ($host === '') {
            throw new Exception('rabbitmq host cannot be empty');
        }
        $this->host = $host;
        return $this;
    }

    public function getPort(): int { return $this->port; }

    public function setPort(int $port): self
    {
        if ($port < 1 || $port > 65535) {
            throw new Exception('rabbitmq port must be between 1 and 65535');
        }
        $this->port = $port;
        return $this;
    }

    public function getUsername(): string { return $this->username; }

    public function setUsername(string $username): self
    {
        if ($username === '') {
            throw new Exception('rabbitmq username cannot be empty');
        }
        $this->username = $username;
        return $this;
    }

    public function getPassword(): string { return $this->password; }

    public function setPassword(string $password): self
    {
        $this->password = $password;
        return $this;
    }

    public function getVirtualHost(): string { return $this->virtualHost; }

    public function setVirtualHost(string $virtualHost): self
    {
        if ($virtualHost === '') {
            throw new Exception('rabbitmq virtual host cannot be empty');
        }
        $this->virtualHost = $virtualHost;
        return $this;
    }

    public function getConnectTimeout(): float { return $this->connectTimeout; }

    public function setConnectTimeout(float $connectTimeout): self
    {
        if ($connectTimeout <= 0) {
            throw new Exception('rabbitmq connect timeout must be positive');
        }
        $this->connectTimeout = $connectTimeout;
        return $this;
    }

    public function getReadWriteTimeout(): float { return $this->readWriteTimeout; }

    public function setReadWriteTimeout(float $readWriteTimeout): self
    {
        if ($readWriteTimeout <= 0) {
            throw new Exception('rabbitmq read/write timeout must be positive');
        }
        $this->readWriteTimeout = $readWriteTimeout;
        return $this;
    }

    public function isKeepalive(): bool { return $this->keepalive; }

    public function setKeepalive(bool $keepalive): self
    {
        $this->keepalive = $keepalive;
        return $this;
    }

    public function getHeartbeat(): int { return $this->heartbeat; }

    public function setHeartbeat(int $heartbeat): self
    {
        if ($heartbeat < 0) {
            throw new Exception('rabbitmq heartbeat cannot be negative');
        }
        $this->heartbeat = $heartbeat;
        return $this;
    }

    public function getContext(): array { return $this->context; }

    public function setContext(array $context): self
    {
        $this->context = $context;
        return $this;
    }

    public function getPopPollInterval(): float { return $this->popPollInterval; }

    public function setPopPollInterval(float $popPollInterval): self
    {
        if ($popPollInterval <= 0) {
            throw new Exception('rabbitmq pop poll interval must be positive');
        }
        $this->popPollInterval = $popPollInterval;
        return $this;
    }

    public function getQueuePrefix(): string { return $this->queuePrefix; }

    public function setQueuePrefix(string $queuePrefix): self
    {
        if ($queuePrefix === '') {
            throw new Exception('rabbitmq queue prefix cannot be empty');
        }
        $this->queuePrefix = $queuePrefix;
        return $this;
    }

    public function getPrefetchCount(): int { return $this->prefetchCount; }

    public function setPrefetchCount(int $prefetchCount): self
    {
        if ($prefetchCount < 1) {
            throw new Exception('rabbitmq prefetch count must be positive');
        }
        $this->prefetchCount = $prefetchCount;
        return $this;
    }

    public function getConsumerBufferSize(): int { return $this->consumerBufferSize; }

    public function setConsumerBufferSize(int $consumerBufferSize): self
    {
        if ($consumerBufferSize < 1) {
            throw new Exception('rabbitmq consumer buffer size must be positive');
        }
        $this->consumerBufferSize = $consumerBufferSize;
        return $this;
    }

    protected function initialize(): void
    {
        parent::initialize();
        $this->setHost($this->host);
        $this->setPort($this->port);
        $this->setUsername($this->username);
        $this->setVirtualHost($this->virtualHost);
        $this->setConnectTimeout($this->connectTimeout);
        $this->setReadWriteTimeout($this->readWriteTimeout);
        $this->setHeartbeat($this->heartbeat);
        $this->setPopPollInterval($this->popPollInterval);
        $this->setQueuePrefix($this->queuePrefix);
        $this->setPrefetchCount($this->prefetchCount);
        $this->setConsumerBufferSize($this->consumerBufferSize);
    }
}
