# Queue

EasySwoole Queue 提供普通任务、延迟任务和需要确认的可信任务。当前提供 Redis 和 RabbitMQ 驱动，并内置连接池；Redis 驱动同时支持单机和 Redis Cluster。

## 安装

```bash
composer require easyswoole/queue
```

运行环境要求：

- PHP 8.1+
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
| `popPollInterval` | `0.05` | ✓ | ✓ | 后台消费空闲时的轮询间隔，单位为秒 |

连接池还可以使用 `minObjectNum`、`maxObjectNum`、`getObjectTimeout` 等 `EasySwoole\Pool\Config` 配置项。

## RabbitMQ 配置

RabbitMQ 驱动基于 `easyswoole/amqp`，网络 I/O 使用 Swoole 协程 Socket。在 Swoole 协程内调用驱动即可。

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
| `heartbeat` | `0` | AMQP 心跳间隔；0 表示关闭 |
| `tls` | `null` | TLS 配置，使用 `setTls(new \EasySwoole\AMQP\TlsConfig(...))` |
| `popPollInterval` | `0.05` | 后台消费空闲时的轮询间隔，单位为秒 |
| `queuePrefix` | `easyswoole.queue.` | RabbitMQ 队列名称前缀 |
| `prefetchCount` | `100` | 消费后台协程持有的未确认可信任务上限，包含本地缓冲区中的可信任务 |
| `consumerBufferSize` | `1000` | Broker 消息进入 `pop()` 前的本地协程缓冲区容量 |

RabbitMQ 驱动使用一个独立的后台消费长连接，通过手动确认模式的 `basic.get` 领取消息，并把任务分发到本地协程缓冲区。普通任务进入本地缓冲区后立即 ACK，可信任务则等待业务调用 `confirm()`。 可信任务的确认期限从 `pop()` 把任务交给业务时开始计算。ACK、确认超时和重投都由拥有原 Channel 的后台协程统一执行，因此每条可信任务不会单独占用连接；同一进程内同时处理的可信任务数量主要由 `prefetchCount` 控制。

发布和消费分别使用连接池：发布池隔离多个业务协程的并发写入，消费池固定为 `minObjectNum=1`、`maxObjectNum=1`，其长连接不占用发布池配额。可信任务确认超时后，驱动会先用 Publisher Confirm 发布带有最新 `runTimes` 的任务，再 ACK 原消息。进程或连接在确认前异常中断时，RabbitMQ 会重新投递尚未 ACK 的原消息；业务仍需保持幂等。

可信任务场景可根据单进程实际并发任务数和处理耗时调整 `prefetchCount`。达到上限时，后台协程暂停领取可信任务，直到已有任务确认或超时。

生产环境可启用非零 `heartbeat`。AMQP 客户端会发送协商后的心跳，连接池也会对闲置连接执行 AMQP 往返探活。

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

可信任务可能被重复投递，业务处理逻辑必须保证幂等。Redis 驱动在每次领取可信任务时生成 128 位随机回执，以 22 字符的无填充 Base64URL 写入 Job 的 `queueReceiptId`。业务代码应使用本次 `pop()` 返回的 Job 进行确认。

### Redis 任务去重

Redis 驱动以 `topic + jobId` 标识任务。只要同一任务仍在待消费、延迟或处理中，重复 `push()` 就返回成功，并保留原任务的内容和计划执行时间。不同 topic 可以使用相同的 `jobId`。普通任务出队后、可信任务确认成功或因达到执行次数上限被清理后，可以再次提交同一个 ID。

升级时先停止同一 Redis 队列上运行的旧版生产者和消费者。新版驱动在首次入队、领取或确认时为旧数据建立索引，已有重复 ID 优先保留处理中记录，其次保留待消费记录，最后是延迟记录。保留下来的处理中任务沿用原回执和确认期限，延迟任务沿用原计划执行时间。建立索引会遍历该 topic 的存量数据，大队列建议在维护窗口升级。

### Redis 队列保留键

以队列主题（topic）`a` 为例，Redis 驱动保留以下键。这些键由驱动按需创建，即使当前不存在也应保留。

| 用途 | 单机模式 | Cluster 模式 |
| --- | --- | --- |
| 普通任务待消费列表 | `a` | `{a}` |
| 可信任务待消费列表 | `a_r` | `{a}_r` |
| 普通延迟任务 | `a_d` | `{a}_d` |
| 可信延迟任务 | `a_rd` | `{a}_rd` |
| 可信任务的处理中记录 | `a_p` | `{a}_p` |
| 可信任务的确认期限 | `a_pt` | `{a}_pt` |
| 出队顺序计数器 | `a_ps` | `{a}_ps` |
| 任务 ID 去重索引 | `a_ji` | `{a}_ji` |

在同一个 Redis 库中，业务代码不得将这些键用于缓存或其他业务数据，也不得直接覆盖、删除、重命名或设置过期时间。选择其他队列主题时，要确保其生成的键也不与这些保留键重名。例如，单机模式下主题 `a` 和 `a_p` 不能共用同一个 Redis 库，因为 `a_p` 同时会被用作前者的处理中 Hash 和后者的普通任务 List，可能导致可信任务丢失。

### 单机 Redis 可信队列吞吐

测试环境为 Apple M2、Redis 8.10.3、PHP 8.4.27 和 Swoole 6.2.4，驱动版本为 `8d073e9`。使用本机 TCP 连接，关闭 Redis 的 RDB 和 AOF 持久化。每组测 3 次，每次处理 12,000 条可信任务，业务数据为 1 KiB。领取后立即调用 `confirm()`，耗时仅包含队列操作。

| 测试场景 | 生产协程 | 消费协程 | 吞吐中位数（条/秒） |
| --- | ---: | ---: | ---: |
| 仅入队 | 1 | — | 15,457 |
| 仅入队 | 4 | — | 43,276 |
| 仅入队 | 16 | — | 54,074 |
| 预填队列后领取并确认 | — | 1 | 5,675 |
| 预填队列后领取并确认 | — | 4 | 15,997 |
| 预填队列后领取并确认 | — | 16 | 20,298 |
| 生产和消费同时运行，计至最后一条确认 | 1 | 1 | 5,289 |
| 生产和消费同时运行，计至最后一条确认 | 1 | 4 | 10,203 |
| 生产和消费同时运行，计至最后一条确认 | 1 | 16 | 9,707 |
| 生产和消费同时运行，计至最后一条确认 | 4 | 4 | 12,364 |
| 生产和消费同时运行，计至最后一条确认 | 4 | 16 | 14,228 |

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

## RabbitMQ 性能与可靠性测试

测试环境为 Apple M2、RabbitMQ 4.4.1、PHP 8.4.27、Swoole 6.2.4 和 `easyswoole/amqp` 1.0.1。客户端通过本机 TCP 连接 Broker，使用持久化队列和持久化消息，发布开启 Publisher Confirm。

每组测 3 轮，每轮 4,000 条任务，业务数据为 1 KiB。表中为吞吐中位数，单位为条/秒。预填后消费只计领取和确认阶段，端到端场景同时启动表中数量的生产和消费协程。可信任务领取后立即确认，测试未加入业务处理耗时。

| 任务模式 | 协程数 | 仅入队 | 预填后消费 | 端到端 |
| --- | ---: | ---: | ---: | ---: |
| 可信任务 | 1 | 6,607 | 8,522 | 5,266 |
| 可信任务 | 4 | 15,072 | 8,401 | 5,777 |
| 可信任务 | 16 | 25,853 | 8,125 | 6,334 |
| 普通任务 | 1 | 6,513 | 8,809 | 5,435 |
| 普通任务 | 4 | 14,987 | 8,739 | 6,250 |
| 普通任务 | 16 | 26,521 | 8,665 | 6,656 |

同一 topic 由一个后台协程串行执行 `basic.get`。本次测试中，增加消费协程没有提升预填队列的消费吞吐。

可靠性验证结果如下。

- 100 条可信任务集中超时后，全部重新投递并成功确认，旧回执均被拒绝。
- Broker 强制断开连接后，未确认任务成功恢复，旧回执无法确认新投递，断线后的 `flush()` 正常执行。
- 可信任务在本地缓冲区等待超过确认期限后，首次 `pop()` 仍可正常领取并确认。确认期限从实际交付给业务时开始计算。
- 完整测试通过 19 项、209 个断言。启用心跳后，RabbitMQ 专项测试通过 9 项、111 个断言。

## 自定义驱动

自定义队列驱动需要实现 `EasySwoole\Queue\AbstractInterface\QueueDriverInterface`。驱动对象必须能够在初始化前被克隆；`Queue` 会为不同 topic 的 Producer 和 Consumer 分别克隆并调用一次 `init()`。
