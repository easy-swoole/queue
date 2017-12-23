队列管理类
------

轻量级的任务队列实现，支持`生产-消费`模型的普通队列和延时队列，支持`Redis`和`Beanstalkd`作为后端驱动


安装
------

```
composer require easyswoole/queue
```

初始化配置
------

在框架`frameInitialized`事件里进行初始化操作，具体的配置项可以参考`Connector`目录下对应的驱动类文件

#### 使用Redis驱动

```
use easySwoole\Queue\Connector\Redis;
use easySwoole\Queue\Queue;

function frameInitialized()
{
	$redisOptions = [
		'default'    => 'default',   // 默认队列名称
		'host'       => '127.0.0.1', // redis服务器
		'select'     => 0,           // redis库序号
		'password'   => '',          // redis密码
		'port'       => 6379,        // redis端口
		'ttr'        => 60,          // 任务的最大执行时间
		'timeout'    => 5,           // 连接redis的超时时间
		'persistent' => true         // 是否开启长连接
	];

	// 初始化队列
	$RedisConnector = new Redis($redisOptions);
	Queue::init($RedisConnector);
}
```

#### 使用Beanstalkd驱动

```
use easySwoole\Queue\Connector\Beanstalkd;
use easySwoole\Queue\Queue;

function frameInitialized()
{
	$beanstalkdOptions = [
		'default'    => 'default',   // 默认队列名称
		'host'       => '127.0.0.1', // beanstalkd服务器
		'port'       => 11300,       // beanstalkd端口
		'ttr'        => 60,          // 任务的最大执行时间
		'timeout'    => null,        // 连接beanstalkd的超时时间
		'persistent' => true         // 是否开启长连接
	];

	// 初始化队列
	$beanstalkdConnector = new Beanstalkd($beanstalkdOptions);
	Queue::init($beanstalkdConnector);
}
```

建立任务处理类
-----
任务处理类需要实现 `easySwoole\Queue\JobInterface` 接口里的所有方法


```
<?php

use easySwoole\Queue\Contracts\Job as JobContracts;
use easySwoole\Queue\JobInterface;

class someJobs implements JobInterface
{

    /**
     * 执行任务
     * @param JobContracts $Job
     * @param mixed $data 任务参数
     */
    public function fire(JobContracts $Job, $data);
    {
        // 执行一些任务处理逻辑

        $Job->delete();    // 任务完成后删除任务
        $Job->release();   // 本次处理失败 退回队列
        
        $e = new \Exception('任务失败异常原因');
        $Job->failed($e);    // 任务处理失败 执行失败逻辑

    }

    /**
     * 任务失败逻辑
     * @param mixed $data 任务参数
     * @param \Exception $e
     */
    public function failed($data, \Exception $e);
    {
        // 任务到达最大重试次数后执行本方法
        // 可用于发送通知或日志记录等收尾工作
    }
}
```

将任务投递到队列
------

在业务逻辑中像下面这样进行投递

```
function deliver()
{
    /**
     * 投递普通任务
     * @param string $job 任务处理类的完全名称(包含全命名空间)
     * @param mixed $data 任务的自定义数据
     * @param string $queue 任务队列的名称
     */
    Queue::push(someJobs::class, 'someTaskData', 'QueueName');

    /**
     * 投递延时任务
     * @param int $delay 任务延时秒数
     * @param string $job 任务处理类的完全名称(包含全命名空间)
     * @param mixed $data 任务的自定义数据
     * @param string $queue 任务队列的名称
     */
    Queue::later(30, someJobs::class, 'someTaskData', 'QueueName');
}
```

监听任务队列
------

在`Event`的`frameInitialize `和`onWorkerStart`事件中添加如下代码启动Worker进行队列监听

```
use easySwoole\Queue\Listener;
use Core\Component\ShareMemory;
use Core\Swoole\AsyncTaskManager;
use Core\Swoole\Timer;
```

```
function frameInitialize()
{
    ShareMemory::getInstance()->clear(); // 运行环境清理
}
```

其中`Listener`的`listen`方法可以接受三个参数，按顺序分别是

```
* @param int $delay 任务抛出异常且未被删除时 可以再次获取的延迟时间
* @param int $sleep 如果队列中没有任务 休息多少秒后继续查询
* @param int $tries 任务允许的失败次数上限 超过次数则执行失败逻辑
```

```
function onWorkerStart(\swoole_server $server, $workerId)
{
    // 获得最大TaskWorker数量
    $TaskWorkerNum = Config::getInstance()->getConf('SERVER.CONFIG.task_worker_num');
    if ($workerId == 0) {

        // 启动定时器每1秒投递一个Listener
        Timer::loop(1000, function () use ($TaskWorkerNum) {

            $share = ShareMemory::getInstance();

            // 请勿使得所有Worker都在繁忙状态 危险操作
            if ($share->get('TASK_RUNNING_NUM') < $TaskWorkerNum - 1) {

                AsyncTaskManager::getInstance()->add(
                    function () use ($share) {

                        // Worker计数器自增
                        $share->startTransaction();
                        $share->set('TASK_RUNNING_NUM', $share->get(WorkConsts::TASK_RUNNING_NUM) + 1);
                        $share->commit();

                        // 启动一个任务监听
                        $listener = new Listener(3, 5, 3);
                        $listener->listen('QueueName,OtherName', 3, 5);

                        while (1) {
                            try {
                                $data = $listener->listen('QueueName');
                                if (!$data->Job()) break;
                            } catch (\Exception $e) {
                                echo 'onWorkerStart Closure Exception: ' . $e->getMessage() . PHP_EOL;
                                break;
                            }
                        }

                        return true;  // 切记任务结束后一定要return

                    },
                    AsyncTaskManager::TASK_DISPATCHER_TYPE_RANDOM,
                    function () use ($share) {
                        // Worker计数器自减
                        $share->startTransaction();
                        $share->set('TASK_RUNNING_NUM', $share->get(WorkConsts::TASK_RUNNING_NUM) - 1);
                        $share->commit();
                    });
            }
        });
    }
}```