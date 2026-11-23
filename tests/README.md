# Redis 驱动测试

测试会连接真实 Redis，并为每个用例创建唯一的队列 topic。用例结束后会自动清理对应 key。

单机 Redis 示例：

```bash
export REDIS_MODE=standalone
export REDIS_HOST=127.0.0.1
export REDIS_PORT=6379
export REDIS_PASSWORD='your-password'
export REDIS_DATABASE=0
export REDIS_POOL_MIN=1
export REDIS_POOL_MAX=5
composer test
```

Redis Cluster 示例：

```bash
export REDIS_MODE=cluster
export REDIS_CLUSTER_SEEDS='127.0.0.1:7001,127.0.0.1:7002,127.0.0.1:7003'
export REDIS_PASSWORD='your-password'
composer test
```

可选变量：`REDIS_USERNAME`、`REDIS_CONNECT_TIMEOUT`、`REDIS_READ_TIMEOUT`、
`REDIS_POP_POLL_INTERVAL` 和 `REDIS_RESERVATION_TIMEOUT`。

## RabbitMQ 驱动测试

RabbitMQ 测试会连接实际 Broker，并通过 `easyswoole/amqp` 的协程 Socket 发送和接收消息。每个用例使用独立 topic，结束后自动清理。

```bash
export RABBITMQ_HOST=127.0.0.1
export RABBITMQ_PORT=5672
export RABBITMQ_USERNAME=easyswoole
export RABBITMQ_PASSWORD='your-password'
export RABBITMQ_VHOST=/easyswoole
export RABBITMQ_POOL_MIN=1
export RABBITMQ_POOL_MAX=5
export RABBITMQ_PREFETCH_COUNT=100
export RABBITMQ_CONSUMER_BUFFER_SIZE=1000
vendor/bin/phpunit tests/RabbitMQDriverTest.php
```

可选变量：`RABBITMQ_CONNECT_TIMEOUT`、`RABBITMQ_READ_WRITE_TIMEOUT`、
`RABBITMQ_HEARTBEAT`、`RABBITMQ_POP_POLL_INTERVAL`、`RABBITMQ_PREFETCH_COUNT`
和 `RABBITMQ_CONSUMER_BUFFER_SIZE`。
