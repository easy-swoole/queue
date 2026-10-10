<?php

namespace EasySwoole\Queue\Driver\Redis;

use EasySwoole\Queue\AbstractInterface\QueueDriverInterface;
use EasySwoole\Queue\Job;
use Swoole\Coroutine;

/**
 * 基于 phpredis 的队列驱动。
 *
 * 普通任务使用 List 保存，延迟时间和处理中任务的确认期限使用 Sorted Set 保存。
 * 需要确认的任务采用“至少投递一次”语义，因此业务处理逻辑需要保证幂等。
 */
class Redis implements QueueDriverInterface
{
    /** 原子地将已经到期的延迟任务迁移到待执行队列。 */
    private const PROMOTE_DUE_SCRIPT = <<<'LUA'
local payloads = redis.call('ZRANGEBYSCORE', KEYS[1], '-inf', ARGV[1], 'LIMIT', 0, ARGV[2])
local moved = 0
for _, payload in ipairs(payloads) do
    if redis.call('ZREM', KEYS[1], payload) == 1 then
        redis.call('RPUSH', KEYS[2], payload)
        moved = moved + 1
    end
end
return moved
LUA;

    /** 原子地将确认超时的处理中任务恢复到待执行队列。 */
    private const RECOVER_EXPIRED_SCRIPT = <<<'LUA'
local receipts = redis.call('ZRANGEBYSCORE', KEYS[1], '-inf', ARGV[1], 'LIMIT', 0, ARGV[2])
local recovered = 0
for _, receipt in ipairs(receipts) do
    local payload = redis.call('HGET', KEYS[2], receipt)
    if payload then
        redis.call('RPUSH', KEYS[3], payload)
        recovered = recovered + 1
    end
    redis.call('HDEL', KEYS[2], receipt)
    redis.call('ZREM', KEYS[1], receipt)
end
return recovered
LUA;

    /**
     * 原子领取任务：从待执行队列删除任务，同时写入处理中 Hash 和确认期限 Sorted Set。
     * 这样即使消费者领取任务后崩溃，任务也能在确认超时后重新入队。
     */
    private const CLAIM_SCRIPT = <<<'LUA'
local payload = redis.call('LPOP', KEYS[1])
if not payload then
    return nil
end
local receipt = tostring(redis.call('INCR', KEYS[4]))
redis.call('HSET', KEYS[2], receipt, payload)
redis.call('ZADD', KEYS[3], ARGV[1] + ARGV[2], receipt)
return {receipt, payload}
LUA;

    /** 领取成功后，使用 Job 实际的确认时间更新处理中内容和确认期限。 */
    private const UPDATE_RESERVATION_SCRIPT = <<<'LUA'
if redis.call('HEXISTS', KEYS[1], ARGV[1]) == 0 then
    return 0
end
redis.call('HSET', KEYS[1], ARGV[1], ARGV[2])
redis.call('ZADD', KEYS[2], ARGV[3], ARGV[1])
return 1
LUA;

    /** 原子确认任务，同时删除处理中内容和对应的确认期限。 */
    private const ACK_SCRIPT = <<<'LUA'
local removed = redis.call('HDEL', KEYS[1], ARGV[1])
redis.call('ZREM', KEYS[2], ARGV[1])
return removed
LUA;

    /** Redis 连接及连接池配置。 */
    protected Config $config;

    /** 驱动是否已经绑定队列主题。 */
    protected bool $hasInit = false;

    /** 当前驱动实例对应的队列主题。 */
    protected string $topicName;

    /** 当前 Queue 节点标识，预留给分布式消费场景。 */
    protected ?string $nodeId = null;

    /** Redis/RedisCluster 连接池。 */
    protected Pool $pool;

    /** 上一次维护延迟队列和处理中队列的秒级时间戳。 */
    protected ?int $lastMaintenanceAt = null;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function init(string $topicName, ?string $nodeId): bool
    {
        if ($this->hasInit) {
            return true;
        }

        $this->topicName = $topicName;
        $this->nodeId = $nodeId;
        $this->pool = new Pool($this->config);
        $this->hasInit = true;
        return true;
    }

    public function push(Job $job, float $timeout = 3.0): bool
    {
        // 回执只属于某一次消费，重新入队时不能持久化旧回执。
        $payload = $this->encodeJob($job);

        return $this->invoke(function (Client|ClusterClient $redis) use ($job, $payload): bool {
            // 延迟任务以计划执行时间为 score 保存。
            if ($job->getDelayTime() > 0) {
                return $redis->zAdd(
                    $this->delayQueueKey(),
                    time() + $job->getDelayTime(),
                    $payload
                ) !== false;
            }

            // 普通任务直接追加到待执行 List 尾部。
            return $redis->rPush($this->runningQueueKey(), $payload) !== false;
        }, $timeout);
    }

    /**
     * 获取一个任务。
     *
     * timeout 控制获取连接以及等待任务的时长；等待期间使用协程友好的短轮询，
     * 每次真正领取任务仍由 Lua 保证“出队 + 加入处理中集合”的原子性。
     */
    public function pop(float $timeout = 3.0, ?array $params = null): ?Job
    {
        $waitTime = max(0.0, $timeout);

        return $this->invoke(function (Client|ClusterClient $redis) use ($waitTime): ?Job {
            $stopAt = microtime(true) + $waitTime;

            do {
                $now = time();
                // 每秒最多执行一次延迟任务迁移和超时任务恢复，避免每次轮询重复维护。
                if ($this->lastMaintenanceAt !== $now) {
                    $this->lastMaintenanceAt = $now;
                    $this->promoteDueJobs($redis, $now);
                    $this->recoverExpiredJobs($redis, $now);
                }

                // 领取任务时先写入兜底确认期限，避免领取成功后进程立即崩溃造成任务丢失。
                $claimed = $redis->eval(self::CLAIM_SCRIPT, [
                    $this->runningQueueKey(),
                    $this->processingQueueKey(),
                    $this->processingDeadlineKey(),
                    $this->receiptSequenceKey(),
                    $now,
                    $this->config->getReservationTimeout(),
                ], 4);

                if (is_array($claimed) && isset($claimed[0], $claimed[1])) {
                    $receipt = (string) $claimed[0];
                    $job = $this->decodeJob((string) $claimed[1]);
                    // 无法解析的非法数据直接从处理中集合移除，避免形成永久死循环。
                    if ($job === null) {
                        $this->acknowledge($redis, $receipt);
                        continue;
                    }

                    // 已经达到最大执行次数的任务不再投递。
                    if ($job->getRetryTimes() !== 0 && $job->getRunTimes() >= $job->getRetryTimes()) {
                        $this->acknowledge($redis, $receipt);
                        continue;
                    }

                    $job->setRunTimes($job->getRunTimes() + 1);
                    // retryTimes=0 表示普通任务，不进入等待确认流程。
                    if ($job->getRetryTimes() === 0) {
                        $this->acknowledge($redis, $receipt);
                        return $job;
                    }

                    // 处理中保存的 Job 不携带本次回执；超时恢复后会生成新的消费回执。
                    $reservedJob = clone $job;
                    $reservedJob->setQueueReceiptId(null);
                    $updated = $redis->eval(self::UPDATE_RESERVATION_SCRIPT, [
                        $this->processingQueueKey(),
                        $this->processingDeadlineKey(),
                        $receipt,
                        $this->encodeJob($reservedJob),
                        time() + max(1, $job->getWaitConfirmTime()),
                    ], 2);
                    if ((int) $updated !== 1) {
                        throw new \RuntimeException('redis queue reservation was lost');
                    }

                    // 回执随 Job 返回给消费者，confirm() 使用它精确确认本次投递。
                    $job->setQueueReceiptId($receipt);
                    return $job;
                }

                if ($waitTime <= 0 || microtime(true) >= $stopAt) {
                    return null;
                }
                $this->pause(min($this->config->getPopPollInterval(), $stopAt - microtime(true)));
            } while (true);
        }, $timeout);
    }

    public function info(): ?array
    {
        return $this->invoke(fn (Client|ClusterClient $redis): array => [
            'runningQueue' => $redis->lLen($this->runningQueueKey()),
            'delayQueue' => $redis->zCard($this->delayQueueKey()),
        ]);
    }

    public function confirm(Job $job, float $timeout = 3.0): bool
    {
        if ($job->getRetryTimes() === 0) {
            return false;
        }

        $receipt = $job->getQueueReceiptId();
        if ($receipt === null) {
            return false;
        }

        return $this->invoke(function (Client|ClusterClient $redis) use ($receipt): bool {
            return $redis->eval(self::ACK_SCRIPT, [
                $this->processingQueueKey(),
                $this->processingDeadlineKey(),
                $receipt,
            ], 2) !== false;
        }, $timeout);
    }

    public function flush(): bool
    {
        return $this->invoke(function (Client|ClusterClient $redis): bool {
            foreach ($this->allQueueKeys() as $key) {
                if ($redis->del($key) === false) {
                    return false;
                }
            }
            return true;
        });
    }

    public function __clone()
    {
        if ($this->hasInit) {
            throw new \RuntimeException('Redis queue driver init clone not supported');
        }
    }

    protected function invoke(callable $callback, ?float $timeout = null): mixed
    {
        if (!$this->hasInit) {
            throw new \RuntimeException('Redis queue driver has not been initialized');
        }
        return $this->pool->invoke($callback, $timeout);
    }

    private function promoteDueJobs(Client|ClusterClient $redis, int $now): void
    {
        $redis->eval(self::PROMOTE_DUE_SCRIPT, [
            $this->delayQueueKey(),
            $this->runningQueueKey(),
            $now,
            $this->config->getMigrationBatchSize(),
        ], 2);
    }

    private function recoverExpiredJobs(Client|ClusterClient $redis, int $now): void
    {
        $redis->eval(self::RECOVER_EXPIRED_SCRIPT, [
            $this->processingDeadlineKey(),
            $this->processingQueueKey(),
            $this->runningQueueKey(),
            $now,
            $this->config->getMigrationBatchSize(),
        ], 3);
    }

    private function acknowledge(Client|ClusterClient $redis, string $receipt): void
    {
        $redis->eval(self::ACK_SCRIPT, [
            $this->processingQueueKey(),
            $this->processingDeadlineKey(),
            $receipt,
        ], 2);
    }

    private function encodeJob(Job $job): string
    {
        $job = clone $job;
        $job->setQueueReceiptId(null);
        return serialize($job);
    }

    private function decodeJob(string $payload): ?Job
    {
        $decoded = @unserialize($payload);
        return $decoded instanceof Job ? $decoded : null;
    }

    private function pause(float $seconds): void
    {
        if ($seconds < 0.001) {
            return;
        }
        if (Coroutine::getCid() > 0) {
            Coroutine::sleep($seconds);
            return;
        }
        usleep((int) ($seconds * 1_000_000));
    }

    private function keyPrefix(): string
    {
        // Cluster 的 Lua 脚本涉及多个 key，使用相同 hash tag 确保它们落在同一个 slot。
        if ($this->config->getMode() === Config::MODE_CLUSTER) {
            return '{' . $this->topicName . '}';
        }
        return $this->topicName;
    }

    private function runningQueueKey(): string
    {
        return $this->config->getMode() === Config::MODE_CLUSTER
            ? $this->keyPrefix() . ':running'
            : $this->keyPrefix();
    }

    /** 延迟任务 Sorted Set。 */
    private function delayQueueKey(): string { return $this->keyPrefix() . '_d'; }

    /** 处理中任务 Hash，field 为消费回执，value 为序列化 Job。 */
    private function processingQueueKey(): string { return $this->keyPrefix() . '_p'; }

    /** 处理中任务确认期限 Sorted Set，member 为消费回执。 */
    private function processingDeadlineKey(): string { return $this->keyPrefix() . '_pt'; }

    /** 生成当前主题内唯一消费回执的 Redis 自增键。 */
    private function receiptSequenceKey(): string { return $this->keyPrefix() . '_ps'; }

    private function allQueueKeys(): array
    {
        return [
            $this->runningQueueKey(),
            $this->delayQueueKey(),
            $this->processingQueueKey(),
            $this->processingDeadlineKey(),
            $this->receiptSequenceKey(),
        ];
    }
}
