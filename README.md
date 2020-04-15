# Queue
```php
<?php
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
            $job->setJobData(time());
            $id = $queue->producer()->push($job);
            var_dump('job create for Id :'.$id);
            \co::sleep(3);
        }
    });

    go(function ()use($queue){
        $queue->consumer()->listen(function (\EasySwoole\Queue\Job $job){
            var_dump($job);
        });
    });
});
```
