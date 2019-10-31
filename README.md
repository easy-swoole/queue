# Queue
```php
use EasySwoole\Redis\Config\RedisConfig;
use EasySwoole\RedisPool\RedisPool;
use EasySwoole\Queue\Driver\Redis;
use EasySwoole\Queue\Queue;
use EasySwoole\Queue\Job;

$config = new RedisConfig([
    'host'=>'127.0.0.1'
]);
$redis = new RedisPool($config);

$driver = new Redis($redis);
$queue = new Queue($driver);

go(function ()use($queue){
    while (1){
        $job = new Job();
        $job->setJobData(time());
        $id = $queue->producer()->push($job);
        var_dump('job create for Id :'.$id);
        \co::sleep(3);
    }
});

go(function ()use($queue){
    $queue->consumer()->listen(function (Job $job){
        var_dump($job->toArray());
    });
});
```