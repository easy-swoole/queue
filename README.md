# Queue

```php

go(function (){
   \EasySwoole\RedisPool\Redis::getInstance()->register('queue',new \EasySwoole\RedisPool\Config());
    $driver = new \EasySwoole\Queue\Driver\Redis('queue','queue');
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
            var_dump($job->toArray());
        });
    });

});

```