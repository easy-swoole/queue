<?php

declare(strict_types=1);

namespace EasySwoole\Queue\Tests;

use EasySwoole\Queue\Driver\RabbitMQ\Config;
use EasySwoole\Queue\Driver\RabbitMQ\Pool;
use EasySwoole\Queue\Driver\RabbitMQ\RabbitMQ;
use EasySwoole\Queue\Job;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

final class RabbitMQDriverTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        Coroutine::set([
            'hook_flags' => SWOOLE_HOOK_TCP | SWOOLE_HOOK_STREAM_FUNCTION,
        ]);
    }

    public function testNormalJobCanBePushedAndPopped(): void
    {
        $this->withDriver(function (RabbitMQ $driver): void {
            $job = $this->newJob('normal-payload');
            self::assertTrue($driver->push($job));
            self::assertSame(['runningQueue' => 1, 'delayQueue' => 0], $driver->info());

            $popped = $driver->pop(1.0);
            self::assertInstanceOf(Job::class, $popped);
            self::assertSame('normal-payload', $popped->getJobData());
            self::assertSame(1, $popped->getRunTimes());
            self::assertNull($popped->getQueueReceiptId());
            self::assertFalse($driver->confirm($popped));
            self::assertNull($driver->pop(0.0));
            self::assertSame(['runningQueue' => 0, 'delayQueue' => 0], $driver->info());
        });
    }

    public function testReliableJobCanBeConfirmed(): void
    {
        $this->withDriver(function (RabbitMQ $driver): void {
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

    public function testUnconfirmedJobIsHiddenAndDeliveredAgainAfterTimeout(): void
    {
        $this->withDriver(function (RabbitMQ $driver): void {
            $job = $this->newJob('retry-payload');
            $job->setRetryTimes(3);
            $job->setWaitConfirmTime(2);

            self::assertTrue($driver->push($job));
            $first = $driver->pop(1.0);

            self::assertInstanceOf(Job::class, $first);
            self::assertSame(1, $first->getRunTimes());
            self::assertNotNull($first->getQueueReceiptId());

            Coroutine::sleep(0.5);
            self::assertNull($driver->pop(0.0));

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
        $this->withDriver(function (RabbitMQ $driver): void {
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

    public function testReliableJobStopsAfterRetryLimit(): void
    {
        $this->withDriver(function (RabbitMQ $driver): void {
            $job = $this->newJob('limited-retry');
            $job->setRetryTimes(2);
            $job->setWaitConfirmTime(1);

            self::assertTrue($driver->push($job));

            $first = $driver->pop(1.0);
            self::assertInstanceOf(Job::class, $first);
            self::assertSame(1, $first->getRunTimes());

            Coroutine::sleep(1.1);
            $second = $driver->pop(1.0);
            self::assertInstanceOf(Job::class, $second);
            self::assertSame(2, $second->getRunTimes());

            Coroutine::sleep(1.1);
            self::assertNull($driver->pop(0.2));
            self::assertSame(['runningQueue' => 0, 'delayQueue' => 0], $driver->info());
        });
    }

    public function testReliableJobsDoNotOccupyPublisherPoolConnections(): void
    {
        $this->withDriver(function (RabbitMQ $driver): void {
            for ($index = 0; $index < 12; $index++) {
                $job = $this->newJob('concurrent-' . $index);
                $job->setRetryTimes(3);
                $job->setWaitConfirmTime(10);
                self::assertTrue($driver->push($job));
            }

            $jobs = [];
            for ($index = 0; $index < 12; $index++) {
                $job = $driver->pop(2.0);
                self::assertInstanceOf(Job::class, $job);
                self::assertNotNull($job->getQueueReceiptId());
                $jobs[] = $job;
            }

            // 12 个任务都尚未确认，发布池依然可以继续发布消息。
            self::assertTrue($driver->push($this->newJob('publish-while-pending')));
            foreach ($jobs as $job) {
                self::assertTrue($driver->confirm($job));
            }
            self::assertInstanceOf(Job::class, $driver->pop(1.0));
        }, 20, 32);
    }

    private function withDriver(callable $test, int $prefetchCount = 100, int $bufferSize = 1000): void
    {
        $error = null;
        Coroutine\run(function () use ($test, $prefetchCount, $bufferSize, &$error): void {
            $driver = null;
            try {
                $driver = $this->newDriver($prefetchCount, $bufferSize);
                $test($driver);
            } catch (\Throwable $throwable) {
                $error = $throwable;
            } finally {
                if ($driver instanceof RabbitMQ) {
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

    private function newDriver(int $prefetchCount = 100, int $bufferSize = 1000): RabbitMQ
    {
        $config = new Config();
        $config->setHost($this->env('RABBITMQ_HOST', '127.0.0.1'));
        $config->setPort((int) $this->env('RABBITMQ_PORT', '5672'));
        $config->setUsername($this->env('RABBITMQ_USERNAME', 'guest'));
        $config->setPassword($this->env('RABBITMQ_PASSWORD', 'guest'));
        $config->setVirtualHost($this->env('RABBITMQ_VHOST', '/'));
        $config->setConnectTimeout((float) $this->env('RABBITMQ_CONNECT_TIMEOUT', '3'));
        $config->setReadWriteTimeout((float) $this->env('RABBITMQ_READ_WRITE_TIMEOUT', '3'));
        $config->setHeartbeat((int) $this->env('RABBITMQ_HEARTBEAT', '0'));
        $config->setMinObjectNum((int) $this->env('RABBITMQ_POOL_MIN', '1'));
        $config->setMaxObjectNum((int) $this->env('RABBITMQ_POOL_MAX', '5'));
        $config->setPopPollInterval((float) $this->env('RABBITMQ_POP_POLL_INTERVAL', '0.01'));
        $config->setPrefetchCount((int) $this->env('RABBITMQ_PREFETCH_COUNT', (string) $prefetchCount));
        $config->setConsumerBufferSize((int) $this->env('RABBITMQ_CONSUMER_BUFFER_SIZE', (string) $bufferSize));

        $driver = new RabbitMQ($config);
        $driver->init('phpunit_' . bin2hex(random_bytes(8)), 'phpunit');
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

    private function destroyPool(RabbitMQ $driver): void
    {
        foreach (['pool', 'consumerPool'] as $propertyName) {
            $property = new \ReflectionProperty(RabbitMQ::class, $propertyName);
            /** @var Pool $pool */
            $pool = $property->getValue($driver);
            $pool->destroy();
        }
    }

    private function env(string $name, string $default): string
    {
        $value = getenv($name);
        return is_string($value) && $value !== '' ? $value : $default;
    }
}
