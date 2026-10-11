<?php

namespace EasySwoole\Queue\Driver\RabbitMQ;

use EasySwoole\Queue\AbstractInterface\QueueDriverInterface;
use EasySwoole\Queue\Job;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

/**
 * RabbitMQ 队列驱动。
 *
 * 发布和消费分别使用连接池；消费池固定维护一个长连接，由一个后台协程独占 RabbitMQ Channel，并通过
 * Swoole Channel 向业务协程分发任务、接收确认指令。可信任务占用 prefetch
 * 配额而不是连接池连接，因此一个消费连接即可承载多条并发处理中的任务。
 */
class RabbitMQ implements QueueDriverInterface
{
    protected Config $config;
    protected bool $hasInit = false;
    protected string $topicName;
    protected ?string $nodeId = null;
    protected Pool $pool;
    protected Pool $consumerPool;

    private ?Channel $readyJobs = null;
    private ?Channel $commands = null;
    private ?Channel $dispatcherStopped = null;
    private bool $dispatcherRunning = false;
    private ?\Throwable $dispatcherError = null;

    /** @var array<string, array{message: AMQPMessage, payload: string, deadline: float}> */
    private array $pending = [];
    private int $topologyGeneration = 0;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function init(string $topicName, ?string $nodeId): bool
    {
        if ($this->hasInit) {
            return true;
        }
        if ($topicName === '') {
            throw new \InvalidArgumentException('rabbitmq topic name cannot be empty');
        }

        $this->topicName = $topicName;
        $this->nodeId = $nodeId;
        $this->pool = new Pool($this->config);
        $consumerConfig = clone $this->config;
        $consumerConfig->setMinObjectNum(1);
        $consumerConfig->setMaxObjectNum(1);
        $this->consumerPool = new Pool($consumerConfig);
        $this->hasInit = true;
        return true;
    }

    public function push(Job $job, float $timeout = 3.0): bool
    {
        $payload = $this->encodeJob($job);
        $reliable = $job->getRetryTimes() !== 0;
        $queue = $job->getDelayTime() > 0
            ? ($reliable ? $this->reliableDelayQueue() : $this->normalDelayQueue())
            : ($reliable ? $this->reliableQueue() : $this->normalQueue());

        return $this->invoke(function (Client $client) use ($payload, $queue, $job, $timeout): bool {
            $this->declareTopology($client);
            $properties = $this->messageProperties();
            if ($job->getDelayTime() > 0) {
                $properties['expiration'] = (string) ($job->getDelayTime() * 1000);
            }

            $this->publish($client, $queue, $payload, $properties, $timeout);
            return true;
        }, $timeout);
    }

    public function pop(float $timeout = 3.0, ?array $params = null): ?Job
    {
        $this->assertInitialized();
        $this->startDispatcher();

        if ($this->dispatcherError !== null) {
            throw $this->dispatcherError;
        }

        $waitTime = max(0.0, $timeout);
        $result = $this->readyJobs?->pop($waitTime > 0 ? $waitTime : 0.001);
        if ($result instanceof \Throwable) {
            throw $result;
        }
        if ($result instanceof Job) {
            return $result;
        }
        if ($this->dispatcherError !== null) {
            throw $this->dispatcherError;
        }
        return null;
    }

    public function info(): ?array
    {
        return $this->invoke(function (Client $client): array {
            $this->declareTopology($client);
            return [
                'runningQueue' => $this->messageCount($client, $this->normalQueue())
                    + $this->messageCount($client, $this->reliableQueue()),
                'delayQueue' => $this->messageCount($client, $this->normalDelayQueue())
                    + $this->messageCount($client, $this->reliableDelayQueue()),
            ];
        });
    }

    public function confirm(Job $job, float $timeout = 3.0): bool
    {
        if ($job->getRetryTimes() === 0) {
            return false;
        }
        $receipt = $job->getQueueReceiptId();
        if ($receipt === null || !$this->dispatcherRunning || !$this->commands instanceof Channel) {
            return false;
        }

        $reply = new Channel(1);
        if (!$this->commands->push(['type' => 'confirm', 'receipt' => $receipt, 'reply' => $reply], $timeout)) {
            return false;
        }
        $confirmed = $reply->pop($timeout);
        $reply->close();
        if ($confirmed === true) {
            $job->setQueueReceiptId(null);
            return true;
        }
        if ($confirmed instanceof \Throwable) {
            throw $confirmed;
        }
        return false;
    }

    public function flush(): bool
    {
        $this->assertInitialized();
        $this->stopDispatcher();

        return $this->invoke(function (Client $client): bool {
            $this->declareTopology($client);
            foreach ($this->allQueues() as $queue) {
                $client->publishChannel()->queue_delete($queue);
            }
            $this->topologyGeneration++;
            return true;
        });
    }

    public function __clone()
    {
        if ($this->hasInit) {
            throw new \RuntimeException('RabbitMQ queue driver init clone not supported');
        }
    }

    /** 启动唯一的消费协程，RabbitMQ 消费 Channel 只允许该协程读写。 */
    private function startDispatcher(): void
    {
        if ($this->dispatcherRunning) {
            return;
        }

        $this->dispatcherError = null;
        $this->readyJobs = new Channel($this->config->getConsumerBufferSize());
        $this->commands = new Channel(max(16, $this->config->getPrefetchCount() * 2));
        $this->dispatcherStopped = new Channel(1);
        $started = new Channel(1);
        $this->dispatcherRunning = true;

        Coroutine::create(function () use ($started): void {
            $client = null;
            $startupCompleted = false;
            try {
                // 消费池固定为 1/1，不占用发布池配额，同时统一连接生命周期管理。
                /** @var Client $client */
                $client = $this->consumerPool->getObj($this->config->getGetObjectTimeout());
                $this->declareTopology($client);
                $channel = $client->consumeChannel();
                $channel->basic_qos(null, $this->config->getPrefetchCount(), false);

                $normalTag = $channel->basic_consume(
                    $this->normalQueue(),
                    '',
                    false,
                    false,
                    false,
                    false,
                    function (AMQPMessage $message): void {
                        $this->receiveNormal($message);
                    }
                );
                $reliableTag = $channel->basic_consume(
                    $this->reliableQueue(),
                    '',
                    false,
                    false,
                    false,
                    false,
                    function (AMQPMessage $message): void {
                        $this->receiveReliable($message);
                    }
                );
                $started->push(true);
                $startupCompleted = true;

                while ($this->dispatcherRunning) {
                    $this->handleCommands();
                    $this->expirePending($client);
                    if (!$this->dispatcherRunning) {
                        break;
                    }
                    try {
                        // stream_select 已通过 SWOOLE_HOOK_STREAM_FUNCTION 协程化。
                        // ACK 指令也由本协程执行，单次等待最多 1ms，避免 confirm() 长时间排队。
                        $channel->wait(
                            null,
                            false,
                            min(0.001, $this->config->getPopPollInterval())
                        );
                    } catch (AMQPTimeoutException) {
                    }
                }

                if ($normalTag !== null && $channel->is_open()) {
                    $channel->basic_cancel($normalTag);
                }
                if ($reliableTag !== null && $channel->is_open()) {
                    $channel->basic_cancel($reliableTag);
                }
                $this->ackAndClearPending();
                $this->consumerPool->recycleObj($client);
                $client = null;
                $this->dispatcherRunning = false;
                $this->dispatcherStopped?->push(true);
            } catch (\Throwable $throwable) {
                $this->dispatcherError = $throwable;
                $this->dispatcherRunning = false;
                $this->pending = [];
                if (!$startupCompleted) {
                    $started->push($throwable);
                } elseif ($this->readyJobs instanceof Channel) {
                    $this->readyJobs->push($throwable, 0.001);
                }
                if ($client instanceof Client) {
                    // 关闭异常连接后，Broker 会重新投递该连接上尚未确认的消息。
                    $this->consumerPool->unsetObj($client);
                }
                $this->dispatcherStopped?->push($throwable);
            }
        });

        $result = $started->pop($this->config->getConnectTimeout() + 1.0);
        $started->close();
        if ($result instanceof \Throwable) {
            throw $result;
        }
        if ($result !== true) {
            $this->dispatcherRunning = false;
            throw new \RuntimeException('rabbitmq consumer dispatcher start timeout');
        }
    }

    /** 普通任务进入本地缓冲区后立即 ACK；缓冲区已满则退回 Broker。 */
    private function receiveNormal(AMQPMessage $message): void
    {
        $job = $this->decodeJob($message->getBody());
        if (!$job instanceof Job) {
            $message->ack();
            return;
        }
        $job->setRunTimes($job->getRunTimes() + 1);
        if ($this->readyJobs?->push($job, 0.1)) {
            $message->ack();
            return;
        }
        $message->nack(false, true);
    }

    /** 可信任务保留 AMQPMessage，由后台协程统一执行 ACK 或超时重投。 */
    private function receiveReliable(AMQPMessage $message): void
    {
        $job = $this->decodeJob($message->getBody());
        if (!$job instanceof Job) {
            $message->ack();
            return;
        }
        if ($job->getRetryTimes() !== 0 && $job->getRunTimes() >= $job->getRetryTimes()) {
            $message->ack();
            return;
        }

        $job->setRunTimes($job->getRunTimes() + 1);
        $receipt = bin2hex(random_bytes(16));
        $reservedJob = clone $job;
        $reservedJob->setQueueReceiptId(null);
        $job->setQueueReceiptId($receipt);

        $this->pending[$receipt] = [
            'message' => $message,
            'payload' => $this->encodeJob($reservedJob),
            'deadline' => microtime(true) + max(1, $job->getWaitConfirmTime()),
        ];

        // 本地缓冲区满时保留 pending，任务会在确认期限后自动重投，不会丢失。
        $this->readyJobs?->push($job, 0.1);
    }

    private function handleCommands(): void
    {
        if (!$this->commands instanceof Channel) {
            return;
        }
        while (!$this->commands->isEmpty()) {
            $command = $this->commands->pop(0.001);
            if (is_array($command)) {
                $this->handleCommand($command);
            }
        }
    }

    private function handleCommand(array $command): void
    {
        $reply = $command['reply'] ?? null;
        try {
            if (($command['type'] ?? null) === 'confirm') {
                $receipt = (string) ($command['receipt'] ?? '');
                $confirmed = $this->acknowledge($receipt);
                if ($reply instanceof Channel) {
                    $reply->push($confirmed);
                }
            } elseif (($command['type'] ?? null) === 'stop') {
                $this->dispatcherRunning = false;
            }
        } catch (\Throwable $throwable) {
            if ($reply instanceof Channel) {
                $reply->push($throwable);
            }
            throw $throwable;
        }
    }

    private function acknowledge(string $receipt): bool
    {
        if (!isset($this->pending[$receipt])) {
            return false;
        }
        $this->pending[$receipt]['message']->ack();
        unset($this->pending[$receipt]);
        return true;
    }

    /**
     * 先通过 Publisher Confirm 确认更新后的任务已到达 Broker，再 ACK 原消息。
     * 若 ACK 失败，原消息仍会重投，最坏产生重复但不会因顺序问题丢失任务。
     */
    private function expirePending(Client $client): void
    {
        $now = microtime(true);
        foreach ($this->pending as $receipt => $pending) {
            if ($pending['deadline'] > $now) {
                continue;
            }
            $this->publish(
                $client,
                $this->reliableQueue(),
                $pending['payload'],
                $this->messageProperties(),
                $this->config->getReadWriteTimeout()
            );
            $pending['message']->ack();
            unset($this->pending[$receipt]);
        }
    }

    private function stopDispatcher(): void
    {
        if (!$this->commands instanceof Channel) {
            $this->closeLocalChannels();
            return;
        }

        $timeout = $this->config->getReadWriteTimeout() + 1.0;
        if ($this->dispatcherRunning
            && !$this->commands->push(['type' => 'stop'], $timeout)
        ) {
            throw new \RuntimeException('rabbitmq consumer dispatcher stop timeout');
        }
        $result = $this->dispatcherStopped?->pop($timeout);
        if ($result instanceof \Throwable) {
            throw $result;
        }
        if ($result !== true) {
            throw new \RuntimeException('rabbitmq consumer dispatcher stop timeout');
        }

        $this->closeLocalChannels();
    }

    private function ackAndClearPending(): void
    {
        foreach ($this->pending as $pending) {
            $pending['message']->ack();
        }
        $this->pending = [];
    }

    private function closeLocalChannels(): void
    {
        $this->dispatcherRunning = false;
        $this->pending = [];
        if ($this->readyJobs instanceof Channel) {
            $this->readyJobs->close();
        }
        if ($this->commands instanceof Channel) {
            $this->commands->close();
        }
        if ($this->dispatcherStopped instanceof Channel) {
            $this->dispatcherStopped->close();
        }
        $this->readyJobs = null;
        $this->commands = null;
        $this->dispatcherStopped = null;
    }

    private function publish(
        Client $client,
        string $queue,
        string $payload,
        array $properties,
        float $timeout
    ): void {
        $client->publishChannel()->basic_publish(new AMQPMessage($payload, $properties), '', $queue);
        $client->publishChannel()->wait_for_pending_acks_returns(max(0.001, $timeout));
    }

    private function declareTopology(Client $client): void
    {
        if ($client->hasDeclaredTopic($this->topicName, $this->topologyGeneration)) {
            return;
        }

        $publish = $client->publishChannel();
        $publish->queue_declare($this->normalQueue(), false, true, false, false);
        $publish->queue_declare($this->reliableQueue(), false, true, false, false);
        $publish->queue_declare(
            $this->normalDelayQueue(),
            false,
            true,
            false,
            false,
            false,
            new AMQPTable([
                'x-dead-letter-exchange' => '',
                'x-dead-letter-routing-key' => $this->normalQueue(),
            ])
        );
        $publish->queue_declare(
            $this->reliableDelayQueue(),
            false,
            true,
            false,
            false,
            false,
            new AMQPTable([
                'x-dead-letter-exchange' => '',
                'x-dead-letter-routing-key' => $this->reliableQueue(),
            ])
        );
        $client->markTopicDeclared($this->topicName, $this->topologyGeneration);
    }

    private function invoke(callable $callback, ?float $timeout = null): mixed
    {
        $this->assertInitialized();
        return $this->pool->invoke($callback, $timeout);
    }

    private function messageCount(Client $client, string $queue): int
    {
        $result = $client->publishChannel()->queue_declare($queue, true);
        return (int) ($result[1] ?? 0);
    }

    private function messageProperties(): array
    {
        return [
            'content_type' => 'application/vnd.php.serialized',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'timestamp' => time(),
        ];
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

    private function queueBase(): string
    {
        return $this->config->getQueuePrefix() . $this->topicName;
    }

    private function normalQueue(): string { return $this->queueBase() . '.normal'; }
    private function reliableQueue(): string { return $this->queueBase() . '.reliable'; }
    private function normalDelayQueue(): string { return $this->queueBase() . '.normal.delay'; }
    private function reliableDelayQueue(): string { return $this->queueBase() . '.reliable.delay'; }

    private function allQueues(): array
    {
        return [
            $this->normalQueue(),
            $this->reliableQueue(),
            $this->normalDelayQueue(),
            $this->reliableDelayQueue(),
        ];
    }

    private function assertInitialized(): void
    {
        if (!$this->hasInit) {
            throw new \RuntimeException('RabbitMQ queue driver has not been initialized');
        }
    }
}
