# Queue
```php
<?php
/**
 * Created by PhpStorm.
 * User: Tioncico
 * Date: 2019/10/18 0018
 * Time: 9:54
 */

include "./vendor/autoload.php";

go(function (){
    //queue组件会自动强制进行序列化
    \EasySwoole\RedisPool\Redis::getInstance()->register('queue',new \EasySwoole\Redis\Config\RedisConfig(
        [
            'host'      => '127.0.0.1',
            'port'      => '6379',
            'auth'      => 'easyswoole',
        ]
    ));
    $redisPool = \EasySwoole\RedisPool\Redis::getInstance()->get('queue');
    $driver = new \EasySwoole\Queue\Driver\Redis($redisPool,'queue');
    $queue = new EasySwoole\Queue\Queue($driver);
    go(function ()use($queue){
        while (1){
            $job = new \EasySwoole\Queue\Job();
            $data = "1:".rand(1,99);
            $job->setJobData($data);
            $id = $queue->producer()->push($job);
            echo ('create1 data :'.$data.PHP_EOL);
            \co::sleep(3);
        }
    });
    go(function ()use($queue){
        $queue->consumer()->listen(function (\EasySwoole\Queue\Job $job){
            echo "job1 data:".$job->getJobData().PHP_EOL;
        });
    });



    $driver = new \EasySwoole\Queue\Driver\Redis($redisPool,'queue2');
    $queue2 = new EasySwoole\Queue\Queue($driver);
    go(function ()use($queue2){
        while (1){
            $job = new \EasySwoole\Queue\Job();
            $data = "2:".rand(1,99);
            $job->setJobData($data);
            $id = $queue2->producer()->push($job);
            echo ('create2 data :'.$data.PHP_EOL);
            \co::sleep(3);
        }
    });
    go(function ()use($queue2){
        $queue2->consumer()->listen(function (\EasySwoole\Queue\Job $job){
            echo "job2 data:".$job->getJobData().PHP_EOL;
        });
    });
});

```
