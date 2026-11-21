<?php

declare(strict_types=1);

namespace EasySwoole\Queue\Tests;

use EasySwoole\Queue\Driver\Redis\Config;
use EasySwoole\Queue\Driver\Redis\Pool;
use EasySwoole\Queue\Driver\Redis\Redis as RedisDriver;
use EasySwoole\Queue\Job;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

final class RedisDriverTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        Coroutine::set(['hook_flags' => SWOOLE_HOOK_TCP]);
    }

    public function testNormalJobCanBePushedAndPopped(): void
    {
        $this->withDriver(function (RedisDriver $driver): void {
            $job = $this->newJob('normal-payload');
            self::assertTrue($driver->push($job));

            $popped = $driver->pop(1.0);
            self::assertInstanceOf(Job::class, $popped);
            self::assertSame('normal-payload', $popped->getJobData());
            self::assertSame(1, $popped->getRunTimes());
            self::assertNull($popped->getQueueReceiptId());
            self::assertFalse($driver->confirm($popped));
            self::assertNull($driver->pop(0.0));
        });
    }

    public function testReliableJobCanBeConfirmed(): void
    {
        $this->withDriver(function (RedisDriver $driver): void {
            $job = $this->newJob('reliable-payload');
            $job->setRetryTimes(3);
            $job->setWaitConfirmTime(2);

            self::assertTrue($driver->push($job));

            $popped = $driver->pop(1.0);
            self::assertInstanceOf(Job::class, $popped);
            self::assertNotNull($popped->getQueueReceiptId());
            self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{22}\z/', $popped->getQueueReceiptId());
            self::assertSame(1, $popped->getRunTimes());
            self::assertTrue($driver->confirm($popped));
            self::assertNull($driver->pop(0.0));
        });
    }

    public function testUnconfirmedJobIsHiddenBeforeTimeoutAndDeliveredAgainAfterTimeout(): void
    {
        $this->withDriver(function (RedisDriver $driver): void {
            $job = $this->newJob('retry-payload');
            $job->setRetryTimes(3);
            $job->setWaitConfirmTime(2);

            self::assertTrue($driver->push($job));

            $first = $driver->pop(1.0);
            self::assertInstanceOf(Job::class, $first);
            self::assertSame(1, $first->getRunTimes());
            self::assertNotNull($first->getQueueReceiptId());

            // 确认期限内任务仍在处理中集合，不能被其他消费者重复领取。
            Coroutine::sleep(0.5);
            self::assertNull($driver->pop(0.0));

            // 超过确认期限后，维护逻辑会将未确认任务重新放回待消费队列。
            Coroutine::sleep(1.6);

            $second = $driver->pop(1.0);
            self::assertInstanceOf(Job::class, $second);
            self::assertSame('retry-payload', $second->getJobData());
            self::assertSame(2, $second->getRunTimes());
            self::assertNotSame($first->getQueueReceiptId(), $second->getQueueReceiptId());

            $driver->confirm($first);
            self::assertTrue($driver->push($this->jobWithId($job->getJobId(), 'duplicate')));
            self::assertNull($driver->pop(0));
            self::assertTrue($driver->confirm($second));
            self::assertTrue($driver->push($job));
            self::assertSame(1, $driver->pop(0)?->getRunTimes());
        });
    }

    public function testDelayedJobIsNotVisibleBeforeDelayExpires(): void
    {
        $this->withDriver(function (RedisDriver $driver): void {
            $job = $this->newJob('delayed-payload');
            $job->setDelayTime(1);

            self::assertTrue($driver->push($job));
            self::assertNull($driver->pop(0.0));

            Coroutine::sleep(1.1);

            $popped = $driver->pop(1.0);
            self::assertInstanceOf(Job::class, $popped);
            self::assertSame('delayed-payload', $popped->getJobData());
        });
    }

    public function testConcurrentPushesKeepOneReadyJobPerId(): void
    {
        $this->withDriver(function (RedisDriver $driver): void {
            $original = $this->newJob('original');
            $duplicate = clone $original;
            $duplicate->setJobData('replacement');
            $duplicate->setRetryTimes(3);
            $results = new Coroutine\Channel(8);
            for ($i = 0; $i < 8; $i++) {
                Coroutine::create(function () use ($driver, $original, $results): void {
                    try {
                        $results->push($driver->push($original));
                    } catch (\Throwable $error) {
                        $results->push($error);
                    }
                });
            }
            for ($i = 0; $i < 8; $i++) {
                self::assertTrue($results->pop(3));
            }

            self::assertTrue($driver->push($duplicate));
            self::assertSame(1, $driver->info()['runningQueue']);
            self::assertSame('original', $driver->pop(0)?->getJobData());
            self::assertNull($driver->pop(0));
            self::assertTrue($driver->push($duplicate));
            self::assertSame('replacement', $driver->pop(0)?->getJobData());
        });
    }

    public function testDuplicateDelayedPushPreservesPayloadAndScore(): void
    {
        $this->withDriver(function (RedisDriver $driver): void {
            $job = $this->newJob('original');
            $job->setDelayTime(60);
            $job->setRetryTimes(3);
            self::assertTrue($driver->push($job));
            $scheduled = $this->evalOnQueue($driver, "return redis.call('ZRANGE', KEYS[4], 0, -1, 'WITHSCORES')");
            self::assertCount(2, $scheduled);

            $replacement = clone $job;
            $replacement->setDelayTime(120);
            $replacement->setJobData('replacement');
            self::assertTrue($driver->push($replacement));
            self::assertTrue($driver->push($this->jobWithId($job->getJobId(), 'immediate')));
            self::assertSame($scheduled, $this->evalOnQueue($driver, "return redis.call('ZRANGE', KEYS[4], 0, -1, 'WITHSCORES')"));

            $this->evalOnQueue($driver, "return redis.call('ZADD', KEYS[4], ARGV[1], ARGV[2])", [time() - 1, $scheduled[0]]);
            $popped = $driver->pop(0);
            self::assertSame('original', $popped?->getJobData());
            self::assertTrue($driver->push($replacement));
            self::assertSame(0, $driver->info()['delayQueue']);
            self::assertTrue($driver->confirm($popped));
            self::assertTrue($driver->push($replacement));
            self::assertSame(1, $driver->info()['delayQueue']);
        });
    }

    public function testExhaustionAndFlushReleaseJobId(): void
    {
        $this->withDriver(function (RedisDriver $driver): void {
            $job = $this->newJob('original');
            $job->setRetryTimes(1);
            $job->setRunTimes(1);
            self::assertTrue($driver->push($job));
            self::assertNull($driver->pop(0));

            $job->setRunTimes(0);
            self::assertTrue($driver->push($job));
            $old = $driver->pop(0);
            self::assertInstanceOf(Job::class, $old);
            self::assertTrue($driver->flush());
            self::assertTrue($driver->push($job));
            $current = $driver->pop(0);
            self::assertInstanceOf(Job::class, $current);
            self::assertNotSame($old->getQueueReceiptId(), $current->getQueueReceiptId());

            $driver->confirm($old);
            self::assertTrue($driver->push($job));
            self::assertNull($driver->pop(0));
            self::assertTrue($driver->confirm($current));
            self::assertTrue($driver->push($job));
            self::assertInstanceOf(Job::class, $driver->pop(0));
        });
    }

    public function testLegacyIndexMigrationPreservesInFlightJobAndOtherTopicIsIndependent(): void
    {
        $this->withDriver(function (RedisDriver $driver): void {
            $original = $this->newJob('in flight');
            $original->setRetryTimes(3);
            $duplicate = $this->jobWithId($original->getJobId(), 'duplicate');
            $delay = $this->newJob('legacy delay');
            $deadline = time() + 120;
            $this->evalOnQueue($driver, <<<'LUA'
redis.call('DEL', KEYS[8])
redis.call('HSET', KEYS[5], 'old-receipt', ARGV[1])
redis.call('ZADD', KEYS[6], ARGV[4], 'old-receipt')
redis.call('RPUSH', KEYS[1], ARGV[2])
redis.call('ZADD', KEYS[3], ARGV[4], ARGV[3])
return 1
LUA, [serialize($original), serialize($duplicate), serialize($delay), $deadline]);

            self::assertTrue($driver->push($duplicate));
            self::assertNull($driver->pop(0));
            self::assertSame((string) $deadline, $this->evalOnQueue(
                $driver,
                "return redis.call('ZSCORE', KEYS[6], 'old-receipt')"
            ));
            self::assertSame((string) $deadline, $this->evalOnQueue(
                $driver,
                "return redis.call('ZRANGE', KEYS[3], 0, -1, 'WITHSCORES')[2]"
            ));
            self::assertTrue($driver->push($this->jobWithId($delay->getJobId(), 'duplicate delay')));
            self::assertSame(1, $driver->info()['delayQueue']);
            self::assertSame(0, $driver->info()['runningQueue']);

            $other = $this->newDriver();
            try {
                self::assertTrue($other->push($duplicate));
                self::assertSame('duplicate', $other->pop(0)?->getJobData());
            } finally {
                try {
                    $other->flush();
                } finally {
                    $this->destroyPool($other);
                }
            }

            $original->setQueueReceiptId('old-receipt');
            self::assertTrue($driver->confirm($original));
            self::assertTrue($driver->push($duplicate));
            self::assertSame('duplicate', $driver->pop(0)?->getJobData());
        });
    }

    private function jobWithId(string $id, string $data): Job
    {
        $job = $this->newJob($data);
        $job->setJobId($id);
        return $job;
    }

    private function evalOnQueue(RedisDriver $driver, string $script, array $arguments = []): mixed
    {
        $keyMethod = new \ReflectionMethod($driver, 'allQueueKeys');
        $keyMethod->setAccessible(true);
        $keys = $keyMethod->invoke($driver);
        $invoke = new \ReflectionMethod($driver, 'invoke');
        $invoke->setAccessible(true);
        return $invoke->invoke(
            $driver,
            static fn ($redis) => $redis->eval($script, array_merge($keys, $arguments), count($keys))
        );
    }

    private function withDriver(callable $test): void
    {
        $error = null;

        Coroutine\run(function () use ($test, &$error): void {
            $driver = null;
            try {
                $driver = $this->newDriver();
                $test($driver);
            } catch (\Throwable $throwable) {
                $error = $throwable;
            } finally {
                if ($driver instanceof RedisDriver) {
                    try {
                        $driver->flush();
                    } finally {
                        $this->destroyPool($driver);
                    }
                }
            }
        });

        if ($error !== null) {
            throw $error;
        }
    }

    private function newDriver(): RedisDriver
    {
        $config = new Config();
        $mode = $this->env('REDIS_MODE', Config::MODE_STANDALONE);
        $config->setMode($mode);
        $config->setUsername($this->nullableEnv('REDIS_USERNAME'));
        $config->setPassword($this->nullableEnv('REDIS_PASSWORD'));
        $config->setConnectTimeout((float) $this->env('REDIS_CONNECT_TIMEOUT', '3'));
        $config->setReadTimeout((float) $this->env('REDIS_READ_TIMEOUT', '3'));
        $config->setMinObjectNum((int) $this->env('REDIS_POOL_MIN', '1'));
        $config->setMaxObjectNum((int) $this->env('REDIS_POOL_MAX', '5'));
        $config->setPopPollInterval((float) $this->env('REDIS_POP_POLL_INTERVAL', '0.01'));
        $config->setReservationTimeout((int) $this->env('REDIS_RESERVATION_TIMEOUT', '3'));

        if ($mode === Config::MODE_CLUSTER) {
            $seeds = array_values(array_filter(array_map(
                'trim',
                explode(',', $this->env('REDIS_CLUSTER_SEEDS', ''))
            )));
            $config->setSeeds($seeds);
        } else {
            $config->setHost($this->env('REDIS_HOST', '127.0.0.1'));
            $config->setPort((int) $this->env('REDIS_PORT', '6379'));
            $config->setDatabase((int) $this->env('REDIS_DATABASE', '0'));
        }

        $driver = new RedisDriver($config);
        $driver->init('phpunit_' . bin2hex(random_bytes(8)), 'phpunit');
        self::assertTrue($driver->flush());
        return $driver;
    }

    private function newJob(string $data): Job
    {
        $job = new Job();
        $job->setJobId(bin2hex(random_bytes(8)));
        $job->setNodeId('phpunit');
        $job->setJobData($data);
        return $job;
    }

    private function destroyPool(RedisDriver $driver): void
    {
        $property = new \ReflectionProperty(RedisDriver::class, 'pool');
        /** @var Pool $pool */
        $pool = $property->getValue($driver);
        $pool->destroy();
    }

    private function env(string $name, string $default): string
    {
        $value = getenv($name);
        return is_string($value) && $value !== '' ? $value : $default;
    }

    private function nullableEnv(string $name): ?string
    {
        $value = getenv($name);
        return is_string($value) && $value !== '' ? $value : null;
    }
}
