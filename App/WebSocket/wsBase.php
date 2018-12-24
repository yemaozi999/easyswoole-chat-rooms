<?php
/**
 * Created by PhpStorm.
 * User: yemaozi999
 * Date: 2018/12/12
 * Time: 15:20
 */

namespace App\WebSocket;

use App\Utility\Pool\MysqlPool;
use App\Utility\Pool\RedisPool;
use EasySwoole\Component\Pool\PoolManager;
use EasySwoole\EasySwoole\Config;


class wsBase
{
    public static function gcRedis($redis){
        PoolManager::getInstance()->getPool(RedisPool::class)->recycleObj($redis);
    }

    public static function getRedisPool(){
        return PoolManager::getInstance()->getPool(RedisPool::class)->getObj(Config::getInstance()->getConf('REDIS.POOL_TIME_OUT'));
    }

    public static function getMysql($db){
        PoolManager::getInstance()->getPool(MysqlPool::class)->recycleObj($db);
    }

    public static function getMysqlPool(){
      return PoolManager::getInstance()->register(MysqlPool::class, Config::getInstance()->getConf('MYSQL.POOL_MAX_NUM'));
    }

}