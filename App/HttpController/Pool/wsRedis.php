<?php
/**
 * Created by PhpStorm.
 * User: yemaozi999
 * Date: 2018/12/12
 * Time: 15:12
 */

namespace App\HttpController\Pool;


use App\HttpController\BaseWithRedis;

class wsRedis extends BaseWithRedis
{
    function getName() {
        $this->getRedis()->set('name', 'blank');
        $name = $this->getRedis()->get('name');
        $this->response()->write($name);
    }
}