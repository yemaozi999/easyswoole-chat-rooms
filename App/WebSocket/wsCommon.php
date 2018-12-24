<?php
/**
 * Created by PhpStorm.
 * User: yemaozi999
 * Date: 2018/12/14
 * Time: 11:27
 */

namespace App\WebSocket;


use EasySwoole\EasySwoole\Swoole\Memory\TableManager;
use PhpParser\Node\Scalar\String_;

class wsCommon
{

    const OP_TYPE_FRUSHROOM = 1;    //刷新房间
    const OP_TYPE_ENTERROOM = 2;    //进入房间
    const OP_TYPE_OUTROOM = 3;      //退出房间
    const OP_TYPE_SENDROOMMSG = 4;  //房间发言
    const OP_TYPE_MY_ROOMS = 5;     //获取我的房间
    const OP_TYPE_MY_INFO = 7;      //获取我的信息

    const OP_TYPE_ROOM_USERS = 8;  //获取房间用户

    const OP_TYPE_SYSTEM = 6;        //系统消息

    const OP_TYPE_ALL_ROOMS = 9;    //所有房间

    public static function returnArray($op_type,$param):String{


        $result = array(
            "op_type"=>$op_type,
            "data"=>array(
                "msg"=>$param["msg"],
                "room"=>$param['room'],
                "fd"=>$param['fd'],
                "fd_name"=>"",
                "to_fd"=>$param['to_fd'],
                "to_fd_name"=>"",
                "res"=>$param['res'],
                "time"=>date("Y-m-d H:i:s")
            )
        );

        $userTable = TableManager::getInstance()->get("talk_users");

        if($param["fd"]){
            $result["data"]["fd_name"] = $userTable->get($param["fd"])["talk_user_fd_name"];
        }
        if($param["to_fd"]){
            $result["data"]["to_fd_name"] = $userTable->get($param["to_fd"])["talk_user_fd_name"];
        }

        return json_encode($result);

    }


}