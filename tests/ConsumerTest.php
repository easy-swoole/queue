<?php

declare(strict_types=1);

namespace EasySwoole\Queue\Tests;

use EasySwoole\Queue\AbstractInterface\QueueDriverInterface;
use EasySwoole\Queue\Consumer;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Swoole\Coroutine;

final class ConsumerTest extends TestCase
{
    public function testOnBreakTakesControlWithoutImplicitSleep(): void
    {
        $driver = $this->createMock(QueueDriverInterface::class);
        $driver->expects(self::once())
            ->method('pop')
            ->with(0.1, null)
            ->willReturn(null);

        $consumer = new Consumer($driver);
        $consumer->setBreakTime(1.0);

        $callbackCalled = false;
        $consumer->setOnBreak(function (Consumer $current) use (&$callbackCalled, $consumer): void {
            $callbackCalled = true;
            self::assertSame($consumer, $current);
            $current->stopListen();
        });

        $startedAt = microtime(true);
        $this->runInCoroutine(static function () use ($consumer): void {
            $consumer->listen(static function (): void {
                self::fail('empty queue must not invoke the job callback');
            });
        });

        self::assertTrue($callbackCalled);
        self::assertLessThan(0.5, microtime(true) - $startedAt);
    }

    public function testBreakTimeIsUsedWhenOnBreakIsNotConfigured(): void
    {
        $driver = $this->createMock(QueueDriverInterface::class);
        $popCount = 0;
        $driver->method('pop')->willReturnCallback(
            static function () use (&$popCount): ?\EasySwoole\Queue\Job {
                $popCount++;
                if ($popCount === 1) {
                    return null;
                }
                throw new RuntimeException('stop test listener');
            }
        );

        $consumer = new Consumer($driver);
        $consumer->setBreakTime(0.03);

        $caught = null;
        $startedAt = microtime(true);
        $this->runInCoroutine(static function () use ($consumer, &$caught): void {
            $consumer->listen(
                static function (): void {
                    self::fail('empty queue must not invoke the job callback');
                },
                null,
                static function (\Throwable $throwable) use ($consumer, &$caught): void {
                    $caught = $throwable;
                    $consumer->stopListen();
                }
            );
        });

        self::assertInstanceOf(RuntimeException::class, $caught);
        self::assertGreaterThanOrEqual(0.02, microtime(true) - $startedAt);
    }

    private function runInCoroutine(callable $callback): void
    {
        $error = null;
        Coroutine\run(static function () use ($callback, &$error): void {
            try {
                $callback();
            } catch (\Throwable $throwable) {
                $error = $throwable;
            }
        });

        if ($error !== null) {
            throw $error;
        }
    }
}
