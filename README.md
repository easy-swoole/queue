# Queue

EasySwoole Queue 提供普通任务、延迟任务和需要确认的可信任务。当前提供 Redis 和 RabbitMQ 驱动，并内置连接池；Redis 驱动同时支持单机和 Redis Cluster。

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

## RabbitMQ 配置

RabbitMQ 驱动基于 `php-amqplib/php-amqplib`。该客户端内部使用 `stream_select()`，
因此必须在协程启动前同时开启 TCP Hook 和 Stream Hook：

```php
use Swoole\Coroutine;

Coroutine::set([
    'hook_flags' => SWOOLE_HOOK_TCP | SWOOLE_HOOK_STREAM_FUNCTION,
]);
```

在提供 `SWOOLE_HOOK_STREAM_SELECT` 常量的 Swoole 版本中，它与
`SWOOLE_HOOK_STREAM_FUNCTION` 表示相同的 Stream Hook。

```php
use EasySwoole\Queue\Driver\RabbitMQ\Config;
use EasySwoole\Queue\Driver\RabbitMQ\RabbitMQ;
use EasySwoole\Queue\Queue;

$config = new Config();
$config->setHost('127.0.0.1');
$config->setPort(5672);
$config->setUsername('easyswoole');
$config->setPassword('password');
$config->setVirtualHost('/easyswoole');
$config->setConnectTimeout(3.0);
$config->setReadWriteTimeout(3.0);
$config->setHeartbeat(0);
$config->setMinObjectNum(1);
$config->setMaxObjectNum(20);

$queue = new Queue(new RabbitMQ($config));
```

| 配置项 | 默认值 | 说明 |
| --- | ---: | --- |
| `host` | `127.0.0.1` | RabbitMQ 地址 |
| `port` | `5672` | AMQP 端口 |
| `username` | `guest` | 登录用户名 |
| `password` | `guest` | 登录密码 |
| `virtualHost` | `/` | Virtual host |
| `connectTimeout` | `3.0` | 连接超时，单位为秒 |
| `readWriteTimeout` | `3.0` | 网络读写超时，单位为秒 |
| `keepalive` | `false` | 是否开启 TCP keepalive |
| `heartbeat` | `0` | AMQP 心跳间隔；0 表示关闭 |
| `context` | `[]` | TLS 等流上下文参数 |
| `popPollInterval` | `0.05` | 空队列短轮询间隔，单位为秒 |
| `queuePrefix` | `easyswoole.queue.` | RabbitMQ 队列名称前缀 |
| `prefetchCount` | `100` | 单个消费连接允许同时处理中、尚未确认的可信任务数 |
| `consumerBufferSize` | `1000` | Broker 消息进入 `pop()` 前的本地协程缓冲区容量 |

RabbitMQ 驱动使用一个独立的后台消费长连接，通过 `basic_consume` 接收消息，并把任务分发到本地协程缓冲区。普通任务进入本地缓冲区后立即 ACK，可信任务则等待业务调用 `confirm()`。ACK、确认超时和重投都由拥有原 Channel 的后台协程统一执行，因此每条可信任务不会单独占用连接；同一进程内同时处理的可信任务数量主要由 `prefetchCount` 控制。

发布和消费分别使用连接池：发布池隔离多个业务协程的并发写入，消费池固定为 `minObjectNum=1`、`maxObjectNum=1`，其长连接不占用发布池配额。可信任务确认超时后，驱动会先用 Publisher Confirm 发布带有最新 `runTimes` 的任务，再 ACK 原消息。进程或连接在确认前异常中断时，RabbitMQ 会重新投递尚未 ACK 的原消息；业务仍需保持幂等。

可信任务场景建议从默认的 `prefetchCount=100` 开始调优。该值过大会让消费协程连续接收过多未确认消息，增加业务协程提交 `confirm()` 的等待时间；应根据单进程实际并发任务数和处理耗时逐步调整。

生产环境建议启用非零 `heartbeat`，让连接池健康检查能够发现静默断线和 TCP 半开连接。`php-amqplib` 要求 `readWriteTimeout` 至少为 heartbeat 的两倍，例如：

```php
$config->setHeartbeat(10);
$config->setReadWriteTimeout(25.0);
```

`heartbeat=0` 时仍会检查连接和 Channel 的本地状态，但无法通过心跳发现静默断线。

RabbitMQ 延迟任务使用带消息 TTL 的延迟队列和 Dead Letter Exchange 实现。RabbitMQ 只会检查队首消息是否过期，因此同一延迟队列中混用不同延迟时间时，短延迟消息可能被前面的长延迟消息阻塞；需要严格定时精度的场景应使用固定 TTL 队列或延迟消息插件。

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

## 性能与可靠性基准

以下结果于 2026-10-11 在一台阿里云 2 核 2GB 实例上测得。Redis、RabbitMQ、PHP 客户端和压测程序均运行在同一台实例，通过 `127.0.0.1` 通信，因此结果主要反映队列驱动及服务端处理能力，不包含公网延迟。

测试环境和口径：

- 2 vCPU，实例标称 2GB 内存，操作系统可见约 1.6GiB。
- PHP 8.4.26、Swoole 6.2.2。
- Redis 6.2.22，单机模式，RDB 开启、AOF 关闭。
- RabbitMQ 4.3.6，持久化队列和持久化消息，Publisher Confirm 开启。
- 发布连接池最大连接数为 20，同时使用 20 个发布协程和 20 个消费协程；RabbitMQ 另有一个固定 `1/1` 的消费连接池。
- RabbitMQ 使用 `heartbeat=10`、`readWriteTimeout=25`、`prefetchCount=100`。
- 每个 Job 的 `jobData` 为 256 字节；每种驱动和任务模式每轮处理 20,000 条任务，连续执行三轮，表中为三轮中位数。

| 驱动 | 任务模式 | Push | Pop / Confirm |
| --- | --- | ---: | ---: |
| Redis | 普通任务 | 13,484 条/秒 | 9,257 条/秒 |
| Redis | 可信任务 | 13,511 条/秒 | 3,812 条/秒 |
| RabbitMQ | 普通任务 | 1,779 条/秒 | 4,417 条/秒 |
| RabbitMQ | 可信任务 | 1,764 条/秒 | 3,654 条/秒 |

可靠性检查结果：

- 共处理 240,000 条任务，其中可信任务 120,000 条；每轮均为 20,000 个唯一 Job、0 个重复、0 个错误，消费完成后队列数量为 0。
- 100 条 RabbitMQ 可信任务同时确认超时后全部重新投递，`runTimes` 均由 1 变为 2，重新确认后队列为空。
- 强制断开 RabbitMQ 消费连接后，旧回执全部确认失败；驱动自动重建消费连接，300 条任务全部恢复，测试中没有丢失或重复。
- 在 10,000 次并发发布期间由服务端关闭 21 个 RabbitMQ 连接，19 个在途 Publisher Confirm 明确抛出连接关闭异常，其余 9,981 条成功；连接池重建后追加 100 条全部成功，Broker 最终计数准确为 10,081，没有静默丢失。

这些数据用于展示上述固定环境下的参考性能，不代表不同硬件、持久化策略、消息大小和业务处理逻辑下的保证值。可信队列采用“至少投递一次”语义；网络在确认临界点中断时，调用方重试仍可能产生重复，因此业务处理必须保持幂等。

## 自定义驱动

自定义队列驱动需要实现 `EasySwoole\Queue\AbstractInterface\QueueDriverInterface`。驱动对象必须能够在初始化前被克隆；`Queue` 会为不同 topic 的 Producer 和 Consumer 分别克隆并调用一次 `init()`。
