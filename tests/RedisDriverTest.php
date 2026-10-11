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
            self::assertTrue($driver->confirm($second));
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
