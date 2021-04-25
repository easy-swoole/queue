# Queue

## 安装

```bash
composer require easyswoole/queue 3.x
```

## 使用
默认自带的队列驱动为Redis队列。
### 创建队列
```
use EasySwoole\Queue\Driver\RedisQueue;
use EasySwoole\Queue\Job;
use EasySwoole\Queue\Queue;
use EasySwoole\Redis\Config\RedisConfig;

$config = new RedisConfig();
$queue = new Queue(new RedisQueue($config));
```
### 普通生产
```
$job = new Job();
$job->setJobData("this is my job data time time ".date('Ymd h:i:s'));
$queue->producer()->push($job);
```
### 普通消费
```
$job = $queue->consumer()->pop();
//或者是自定义进程中
$queue->consumer()->listen(function (Job $job){
    var_dump($job);
});
```
## 延迟任务
```
$job = new Job();
$job->setJobData("this is my job data time time ".date('Ymd h:i:s'));
$job->setDelayTime(5);//设置延后时间
$queue->producer()->push($job);
```
## 可信任务
```
$job = new Job();
$job->setJobData("this is my job data time time ".date('Ymd h:i:s'));
$job->setRetryTimes(3);//任务如果没有确认，则会执行三次
$job->setWaitConfirmTime(5);//如果5秒内没确认任务，会重新回到队列。默认为3秒
$queue->producer()->push($job);//投递任务
//确认一个任务
$queue->consumer()->confirm($job);
```

## 消费者控制

当队列未`pop`到`job`时：

```php
/** @var \EasySwoole\Queue\Queue $queue */
$queue->consumer()->setOnBreak(function(\EasySwoole\Queue\Consumer $consumer) {
     // todo 
 })->listen(function (\EasySwoole\Queue\Job $job){ });
```

设置`breakTime`：

```php
/** @var \EasySwoole\Queue\Queue $queue */
$queue->consumer()->setBreakTime(0.1);
```
