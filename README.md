# Queue

## 安装

```bash
composer require easyswoole/queue 
```

### 队列驱动

任何队列驱动都必须实现```EasySwoole\Queue\QueueDriverInterface```这个接口定义。且实现的对象一定是必须可被克隆(可以看Queue中的Producer方法即知道为何)。
队列在被加载到对应topic的Producer和Consumer时，都会被分别执行一次init()方法。

### 创建队列
```php

use EasySwoole\Queue\Driver\RedisQueue;
use EasySwoole\Queue\Queue;
use EasySwoole\Redis\Config;

$config = new Config([
    'host'=>"",
    'port'=>"",
    'auth'=>""
]);

$driver = new RedisQueue($config);
$queue = new Queue($driver);

```
### 普通生产
```
$job = new Job();
$job->setJobData("this is my job data time time ".date('Ymd h:i:s'));
$queue->producer("topic")->push($job);
```
### 普通消费
```php

$job = $queue->consumer("topic")->pop();
//或者是自定义进程中
$queue->consumer("topicName")->listen(function (Job $job){
    var_dump($job);
});

```

## CLI 单独使用
```php

use EasySwoole\Queue\Driver\RedisQueue;
use EasySwoole\Queue\Job;
use EasySwoole\Queue\Queue;
use EasySwoole\Redis\Config;
use Swoole\Coroutine;
use Swoole\Coroutine\Scheduler;

require "vendor/autoload.php";

$sc = new Scheduler();
$sc->add(function (){
    $config = new Config([
        'host'=>"",
        'port'=>"",
        'auth'=>""
    ]);

    $driver = new RedisQueue($config);
    $queue = new Queue($driver);

    Coroutine::create(function ()use($queue){
        while (1){
            Coroutine::sleep(3);
            $job = new Job();
            $job->setJobData("job test create at ".time());
            try {
                $queue->producer("test")->push($job);
            }catch (\Throwable $throwable){

            }
        }
    });

    Coroutine::create(function ()use($queue){
        while (1){
            Coroutine::sleep(5);
            $job = new Job();
            $job->setJobData("job another create at ".time());
            try {
                $queue->producer("another")->push($job);
            }catch (\Throwable $throwable){

            }
        }
    });

    Coroutine::create(function ()use($queue){
        $queue->consumer("test")->listen(function (Job $job){
            var_dump($job->getJobData() ." hande in test");
        },[],function (){

        });
    });

    Coroutine::create(function ()use($queue){
        $queue->consumer("another")->listen(function (Job $job){
            var_dump($job->getJobData() ." hande in another");
        },[],function (){

        });
    });

});

$sc->start();
```

## 延迟任务
```
$job = new Job();
$job->setJobData("this is my job data time time ".date('Ymd h:i:s'));
$job->setDelayTime(5);//设置延后时间
$queue->producer("topic")->push($job);
```
## 可信任务
```
$job = new Job();
$job->setJobData("this is my job data time time ".date('Ymd h:i:s'));
$job->setRetryTimes(3);//任务如果没有确认，则会执行三次
$job->setWaitConfirmTime(5);//如果5秒内没确认任务，会重新回到队列。默认为3秒
$queue->producer("topic")->push($job);//投递任务
//确认一个任务
$queue->consumer("topic")->confirm($job);
```

## 消费者控制

当队列未`pop`到`job`时：

```php
/** @var \EasySwoole\Queue\Queue $queue */
$queue->consumer("topic")->setOnBreak(function(\EasySwoole\Queue\Consumer $consumer) {
     // todo 
 })->listen(function (\EasySwoole\Queue\Job $job){ });
```

设置`breakTime`：

```php
/** @var \EasySwoole\Queue\Queue $queue */
$queue->consumer("topic")->setBreakTime(0.1);
```
