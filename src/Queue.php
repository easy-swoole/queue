<?php

namespace easySwoole\Queue;

use easySwoole\Queue\Connector\Connector;

/**
 * easySwoole Queue
 * Class Queue
 * @author : evalor <master@evalor.cn>
 * @package easySwoole\Queue
 * @method static size($queueName = null);
 * @method static push($job, $data = '', $queueName = null);
 * @method static pushOn($queueName, $job, $data = '');
 * @method static pushRaw($payload, $queueName = null, array $options = []);
 * @method static later($delay, $job, $data = '', $queueName = null);
 * @method static laterOn($queueName, $delay, $job, $data = '');
 * @method static bulk($jobs, $data = '', $queueName = null);
 * @method static pop($queueName = null);
 */
class Queue
{
    protected static $connector;

    /**
     * init options
     * @param Connector $Connector
     * @author : evalor <master@evalor.cn>
     */
    static function init($Connector)
    {
        self::$connector = $Connector;
    }

    /**
     * Call Static func
     * @param $name
     * @param $arguments
     * @author : evalor <master@evalor.cn>
     * @return mixed
     */
    public static function __callStatic($name, $arguments)
    {
        return call_user_func_array([self::$connector, $name], $arguments);
    }
}