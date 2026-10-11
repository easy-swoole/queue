# Queue

EasySwoole Queue 提供普通任务、延迟任务和需要确认的可信任务。当前 Redis 驱动支持 Redis 单机和 Redis Cluster，并内置连接池。

## 安装

```bash
composer require easyswoole/queue
```

运行环境要求：

- PHP 8.0+
- Swoole 5.1+
- phpredis 扩展
- 协程环境

phpredis 是阻塞客户端，在启动协程前需要启用 TCP Hook：

```php
use Swoole\Coroutine;

Coroutine::set(['hook_flags' => SWOOLE_HOOK_TCP]);
```

## Redis 单机配置

```php
use EasySwoole\Queue\Driver\Redis\Config;
use EasySwoole\Queue\Driver\Redis\Redis as RedisDriver;
use EasySwoole\Queue\Queue;

$config = new Config();
$config->setHost('127.0.0.1');
$config->setPort(6379);
$config->setUsername(null);          // 未启用 ACL 时保持 null
$config->setPassword('password');
$config->setDatabase(0);             // Redis Cluster 只能使用数据库 0
$config->setConnectTimeout(3.0);
$config->setReadTimeout(3.0);

// setMinObjectNum() 应先于较小的 setMaxObjectNum() 调用。
$config->setMinObjectNum(1);
$config->setMaxObjectNum(20);

$queue = new Queue(new RedisDriver($config));
```

## Redis Cluster 配置

```php
use EasySwoole\Queue\Driver\Redis\Config;
use EasySwoole\Queue\Driver\Redis\Redis as RedisDriver;
use EasySwoole\Queue\Queue;

$config = new Config();
$config->setMode(Config::MODE_CLUSTER);
$config->setSeeds([
    '127.0.0.1:7001',
    '127.0.0.1:7002',
    '127.0.0.1:7003',
]);
$config->setUsername(null);
$config->setPassword('password');
$config->setConnectTimeout(3.0);
$config->setReadTimeout(3.0);
$config->setMinObjectNum(1);
$config->setMaxObjectNum(20);

$queue = new Queue(new RedisDriver($config));
```

Cluster 模式下，同一个 topic 使用的所有 Redis key 都带有相同的 hash tag，因此 Lua 脚本涉及的 key 会落在同一个 slot。

### Redis 驱动配置项

| 配置项 | 默认值 | 单机 | Cluster | 说明 |
| --- | ---: | :---: | :---: | --- |
| `mode` | `standalone` | ✓ | ✓ | `standalone` 或 `cluster` |
| `host` | `127.0.0.1` | ✓ |  | Redis 地址 |
| `port` | `6379` | ✓ |  | Redis 端口 |
| `seeds` | `[]` |  | ✓ | Cluster 种子节点 |
| `username` | `null` | ✓ | ✓ | Redis ACL 用户名 |
| `password` | `null` | ✓ | ✓ | Redis 密码 |
| `database` | `0` | ✓ |  | Cluster 只支持数据库 0 |
| `connectTimeout` | `3.0` | ✓ | ✓ | 连接超时，单位为秒 |
| `readTimeout` | `3.0` | ✓ | ✓ | 读取超时，单位为秒 |
| `retryInterval` | `0` | ✓ |  | 连接重试间隔，单位为毫秒 |
| `persistent` | `false` | ✓ | ✓ | 是否使用持久连接 |
| `persistentId` | `null` | ✓ | ✓ | 持久连接标识；Cluster 中作为集群名称 |
| `context` | `[]` | ✓ | ✓ | TLS/SSL 等流上下文参数 |
| `reservationTimeout` | `30` | ✓ | ✓ | 可信任务刚领取时的兜底可见性超时 |
| `migrationBatchSize` | `100` | ✓ | ✓ | 单次迁移到期任务的最大数量 |
| `popPollInterval` | `0.05` | ✓ | ✓ | 空队列短轮询间隔，单位为秒 |

连接池还可以使用 `minObjectNum`、`maxObjectNum`、`getObjectTimeout` 等 `EasySwoole\Pool\Config` 配置项。

## 普通任务

普通任务的 `retryTimes` 默认为 0。任务被成功取出后即从 Redis 删除，不需要调用 `confirm()`。

```php
use EasySwoole\Queue\Job;

$job = new Job();
$job->setJobData(['orderId' => 1001]);
$queue->producer('order')->push($job);

$job = $queue->consumer('order')->pop(3.0);
if ($job !== null) {
    var_dump($job->getJobData());
}
```

`pop($timeout)` 在队列为空时使用协程短轮询等待，每次轮询结束都会归还 Redis 连接，不会在整个等待期间独占连接池连接。

## 延迟任务

```php
$job = new Job();
$job->setJobData(['orderId' => 1001]);
$job->setDelayTime(5);
$queue->producer('order')->push($job);
```

任务在延迟时间到达后才能被消费者取出。普通延迟任务和可信延迟任务分别存储，是否可信仍由 `retryTimes` 决定。

## 可信任务

当 `retryTimes` 大于 0 时，任务使用“至少投递一次”语义：

- `pop()` 会原子地将任务从待消费队列转移到处理中集合。
- 在 `waitConfirmTime` 时间内，任务不会被重复领取。
- 消费成功后必须确认 `pop()` 返回的 Job。
- 到期仍未确认时，任务会被重新放回待消费队列。
- 每次重新投递都会增加 `runTimes`，达到 `retryTimes` 后不再投递。

```php
$job = new Job();
$job->setJobData(['orderId' => 1001]);
$job->setRetryTimes(3);
$job->setWaitConfirmTime(10);
$queue->producer('order')->push($job);

$consumer = $queue->consumer('order');
$job = $consumer->pop(3.0);
if ($job !== null) {
    // 业务处理成功后，确认本次 pop 返回的 Job。
    $consumer->confirm($job);
}
```

可信任务可能被重复投递，业务处理逻辑必须保证幂等。消费回执由驱动写入 Job，业务代码不需要设置 `queueReceiptId`。

## 持续消费

```php
$consumer = $queue->consumer('order');
$consumer->setBreakTime(0.1);
$consumer->setOnBreak(function (): void {
    // 当前没有可消费任务。
});

$consumer->listen(
    function (Job $job) use ($consumer): void {
        try {
            // 处理任务……
            if ($job->getRetryTimes() > 0) {
                $consumer->confirm($job);
            }
        } catch (Throwable $throwable) {
            // 不确认可信任务，等待确认期限到达后重新投递。
        }
    },
    [],
    function (Throwable $throwable, ?Job $job): void {
        // 记录消费异常。
    }
);
```

## CLI 完整示例

```php
<?php

use EasySwoole\Queue\Driver\Redis\Config;
use EasySwoole\Queue\Driver\Redis\Redis as RedisDriver;
use EasySwoole\Queue\Job;
use EasySwoole\Queue\Queue;
use Swoole\Coroutine;

require 'vendor/autoload.php';

Coroutine::set(['hook_flags' => SWOOLE_HOOK_TCP]);

Coroutine\run(function (): void {
    $config = new Config();
    $config->setHost('127.0.0.1');
    $config->setPort(6379);
    $config->setPassword('password');
    $config->setMinObjectNum(1);
    $config->setMaxObjectNum(20);

    $queue = new Queue(new RedisDriver($config));
    $producer = $queue->producer('example');
    $consumer = $queue->consumer('example');

    $job = new Job();
    $job->setJobData('hello queue');
    $producer->push($job);

    $popped = $consumer->pop(1.0);
    if ($popped !== null) {
        var_dump($popped->getJobData());
    }
});
```

## 测试

Redis 集成测试会为每个用例创建唯一 topic，并在结束后自动清理对应 key。详细变量说明见 [`tests/README.md`](tests/README.md)。

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

当前测试覆盖：

- 普通任务 push/pop。
- 可信任务领取与确认。
- `waitConfirmTime` 内不会重复领取，超时后重新投递。
- 延迟任务到期前不可见、到期后可消费。

## 自定义驱动

自定义队列驱动需要实现 `EasySwoole\Queue\AbstractInterface\QueueDriverInterface`。驱动对象必须能够在初始化前被克隆；`Queue` 会为不同 topic 的 Producer 和 Consumer 分别克隆并调用一次 `init()`。
