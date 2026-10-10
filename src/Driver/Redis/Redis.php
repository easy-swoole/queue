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
    /**
     * 在一次 Redis 往返中完成延迟任务迁移、超时任务恢复和任务领取。
     * 返回值首项为任务类型：0 表示普通任务，消费后无需确认；其他值为可信任务的消费回执。
     */
    private const CLAIM_SCRIPT = <<<'LUA'
if ARGV[4] == '1' then
    local function promote(delayKey, readyKey)
        local payloads = redis.call('ZRANGEBYSCORE', delayKey, '-inf', ARGV[1], 'LIMIT', 0, ARGV[2])
        for _, payload in ipairs(payloads) do
            if redis.call('ZREM', delayKey, payload) == 1 then
                redis.call('RPUSH', readyKey, payload)
            end
        end
    end

    promote(KEYS[3], KEYS[1])
    promote(KEYS[4], KEYS[2])

    local receipts = redis.call('ZRANGEBYSCORE', KEYS[6], '-inf', ARGV[1], 'LIMIT', 0, ARGV[2])
    for _, receipt in ipairs(receipts) do
        local payload = redis.call('HGET', KEYS[5], receipt)
        if payload then
            redis.call('RPUSH', KEYS[2], payload)
        end
        redis.call('HDEL', KEYS[5], receipt)
        redis.call('ZREM', KEYS[6], receipt)
    end
end

local sequence = redis.call('INCR', KEYS[7])
local normalFirst = sequence % 2 == 0
local payload = nil

if normalFirst then
    payload = redis.call('LPOP', KEYS[1])
    if payload then
        return {'0', payload}
    end
    payload = redis.call('LPOP', KEYS[2])
else
    payload = redis.call('LPOP', KEYS[2])
    if not payload then
        payload = redis.call('LPOP', KEYS[1])
        if payload then
            return {'0', payload}
        end
    end
end

if not payload then
    return nil
end

local receipt = tostring(sequence)
redis.call('HSET', KEYS[5], receipt, payload)
redis.call('ZADD', KEYS[6], ARGV[1] + ARGV[3], receipt)
return {receipt, payload}
LUA;

    /** 一次读取普通、可信、延迟和处理中任务数量。 */
    private const INFO_SCRIPT = <<<'LUA'
return {
    redis.call('LLEN', KEYS[1]) + redis.call('LLEN', KEYS[2]),
    redis.call('ZCARD', KEYS[3]) + redis.call('ZCARD', KEYS[4]),
    redis.call('HLEN', KEYS[5])
}
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
            $reliable = $job->getRetryTimes() !== 0;

            // 延迟任务以计划执行时间为 score 保存。
            if ($job->getDelayTime() > 0) {
                return $redis->zAdd(
                    $reliable ? $this->reliableDelayQueueKey() : $this->normalDelayQueueKey(),
                    time() + $job->getDelayTime(),
                    $payload
                ) !== false;
            }

            // 普通任务直接追加到待执行 List 尾部。
            return $redis->rPush(
                $reliable ? $this->reliableQueueKey() : $this->normalQueueKey(),
                $payload
            ) !== false;
        }, $timeout);
    }

    /**
     * 获取一个任务。
     *
     * timeout 控制获取连接以及等待任务的时长；等待期间使用协程友好的短轮询，
     * 每轮轮询结束都会归还连接，真正领取任务仍由 Lua 保证“出队 + 加入处理中集合”的原子性。
     */
    public function pop(float $timeout = 3.0, ?array $params = null): ?Job
    {
        $waitTime = max(0.0, $timeout);
        $stopAt = microtime(true) + $waitTime;

        do {
            // 每轮只借用一次连接；回调结束后 invoke() 会立即把连接归还连接池。
            $result = $this->invoke(function (Client|ClusterClient $redis) {
                $now = time();
                // 每秒最多执行一次延迟任务迁移和超时任务恢复。
                $maintain = $this->lastMaintenanceAt !== $now;
                if ($maintain) {
                    $this->lastMaintenanceAt = $now;
                }

                // 普通任务直接出队；可信任务则原子地出队并写入处理中集合。
                $claimed = $this->evalScript($redis, self::CLAIM_SCRIPT, [
                    $this->normalQueueKey(),
                    $this->reliableQueueKey(),
                    $this->normalDelayQueueKey(),
                    $this->reliableDelayQueueKey(),
                    $this->processingQueueKey(),
                    $this->processingDeadlineKey(),
                    $this->receiptSequenceKey(),
                    $now,
                    $this->config->getMigrationBatchSize(),
                    $this->config->getReservationTimeout(),
                    $maintain ? 1 : 0,
                ], 7);

                if (is_array($claimed) && isset($claimed[0], $claimed[1])) {
                    $receipt = (string) $claimed[0];
                    $job = $this->decodeJob((string) $claimed[1]);
                    // 普通任务没有处理中记录；可信任务遇到非法数据时需要清理领取记录。
                    if ($job === null) {
                        if ($receipt !== '0') {
                            $this->acknowledge($redis, $receipt);
                        }
                        return false;
                    }

                    // 普通任务使用快速路径，一次 Lua 调用即可完成出队。
                    if ($receipt === '0') {
                        $job->setRunTimes($job->getRunTimes() + 1);
                        return $job;
                    }

                    // 已经达到最大执行次数的任务不再投递。
                    if ($job->getRetryTimes() !== 0 && $job->getRunTimes() >= $job->getRetryTimes()) {
                        $this->acknowledge($redis, $receipt);
                        return false;
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
                    $updated = $this->evalScript($redis, self::UPDATE_RESERVATION_SCRIPT, [
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

                return null;
            }, $timeout);

            if ($result instanceof Job) {
                return $result;
            }

            if ($waitTime <= 0 || microtime(true) >= $stopAt) {
                return null;
            }

            // false 表示刚刚清理了一条非法或超过重试次数的任务，可立即尝试下一条。
            if ($result !== false) {
                $this->pause(min($this->config->getPopPollInterval(), $stopAt - microtime(true)));
            }
        } while (true);
    }

    public function info(): ?array
    {
        return $this->invoke(function (Client|ClusterClient $redis): array {
            $info = $this->evalScript($redis, self::INFO_SCRIPT, [
                $this->normalQueueKey(),
                $this->reliableQueueKey(),
                $this->normalDelayQueueKey(),
                $this->reliableDelayQueueKey(),
                $this->processingQueueKey(),
            ], 5);

            return [
                'runningQueue' => (int) ($info[0] ?? 0),
                'delayQueue' => (int) ($info[1] ?? 0),
            ];
        });
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
            return $this->evalScript($redis, self::ACK_SCRIPT, [
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

    /** 优先使用脚本 SHA，Redis 尚未缓存脚本时自动回退到 EVAL。 */
    private function evalScript(
        Client|ClusterClient $redis,
        string $script,
        array $arguments,
        int $keyCount
    ): mixed {
        try {
            $result = $redis->evalSha(sha1($script), $arguments, $keyCount);
            if ($result !== false) {
                return $result;
            }

            $lastError = $redis->getLastError();
            if (!is_string($lastError) || stripos($lastError, 'NOSCRIPT') === false) {
                return false;
            }
            $redis->clearLastError();
        } catch (\Throwable $throwable) {
            if (stripos($throwable->getMessage(), 'NOSCRIPT') === false) {
                throw $throwable;
            }
        }

        return $redis->eval($script, $arguments, $keyCount);
    }

    private function acknowledge(Client|ClusterClient $redis, string $receipt): void
    {
        $this->evalScript($redis, self::ACK_SCRIPT, [
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
        Coroutine::sleep($seconds);
    }

    private function keyPrefix(): string
    {
        // Cluster 的 Lua 脚本涉及多个 key，使用相同 hash tag 确保它们落在同一个 slot。
        if ($this->config->getMode() === Config::MODE_CLUSTER) {
            return '{' . $this->topicName . '}';
        }
        return $this->topicName;
    }

    /** 普通任务待执行 List。 */
    private function normalQueueKey(): string { return $this->keyPrefix(); }

    /** 可信任务待执行 List。 */
    private function reliableQueueKey(): string { return $this->keyPrefix() . '_r'; }

    /** 普通延迟任务 Sorted Set。 */
    private function normalDelayQueueKey(): string { return $this->keyPrefix() . '_d'; }

    /** 可信延迟任务 Sorted Set。 */
    private function reliableDelayQueueKey(): string { return $this->keyPrefix() . '_rd'; }

    /** 处理中任务 Hash，field 为消费回执，value 为序列化 Job。 */
    private function processingQueueKey(): string { return $this->keyPrefix() . '_p'; }

    /** 处理中任务确认期限 Sorted Set，member 为消费回执。 */
    private function processingDeadlineKey(): string { return $this->keyPrefix() . '_pt'; }

    /** 生成当前主题内唯一消费回执的 Redis 自增键。 */
    private function receiptSequenceKey(): string { return $this->keyPrefix() . '_ps'; }

    private function allQueueKeys(): array
    {
        return [
            $this->normalQueueKey(),
            $this->reliableQueueKey(),
            $this->normalDelayQueueKey(),
            $this->reliableDelayQueueKey(),
            $this->processingQueueKey(),
            $this->processingDeadlineKey(),
            $this->receiptSequenceKey(),
        ];
    }
}
