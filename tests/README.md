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
