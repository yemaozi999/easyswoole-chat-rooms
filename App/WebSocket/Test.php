<?php
/**
 * Created by PhpStorm.
 * User: Apple
 * Date: 2018/11/1 0001
 * Time: 14:42
 */
namespace App\WebSocket;

use App\HttpController\BaseWithRedis;
use App\HttpController\Config;
use App\HttpController\Pool\Redis;
use EasySwoole\Component\Pool\PoolManager;
use EasySwoole\EasySwoole\ServerManager;
use EasySwoole\EasySwoole\Swoole\Memory\TableManager;
use EasySwoole\EasySwoole\Swoole\Task\TaskManager;
use EasySwoole\Mysqli\Mysqli;
use EasySwoole\Socket\AbstractInterface\Controller;

class Test extends Controller
{
    function hello()
    {
        $param = array(
            "msg"=>'call hello with arg:'. json_encode($this->caller()->getArgs()),
            "room"=>"",
            "fd"=>"",
            "to_fd"=>"",
            "res"=>""
        );
        $return  = wsCommon::returnArray(wsCommon::OP_TYPE_SYSTEM, $param);
        $this->response()->setMessage($return);
    }
    public function who(){
        $myData = TableManager::getInstance()->get("talk_users")->get($this->caller()->getClient()->getFd());
        $param = array(
            "msg"=>"获取成功",
            "room"=>"",
            "fd"=>"",
            "to_fd"=>"",
            "res"=>$myData
        );
        $return  = wsCommon::returnArray(wsCommon::OP_TYPE_MY_INFO, $param);
        $this->response()->setMessage($return);
    }

    function delay()
    {
        $param = array(
            "msg"=>'this is delay action',
            "room"=>"",
            "fd"=>"",
            "to_fd"=>"",
            "res"=>""
        );

        $return  = wsCommon::returnArray(wsCommon::OP_TYPE_SYSTEM, $param);
        $this->response()->setMessage($return);
        $client = $this->caller()->getClient();

        // 异步推送, 这里直接 use fd也是可以的
        // TaskManager::async 回调参数中的代码是在 task 进程中执行的 默认不含连接池 需要注意可能出现 getPool null的情况
        TaskManager::async(function () use ($client){
            $server = ServerManager::getInstance()->getSwooleServer();
            $i = 0;
            while ($i < 5) {
                sleep(1);

                $param2 = array(
                    "msg"=>'push in http at '.time(),
                    "room"=>"",
                    "fd"=>"",
                    "to_fd"=>"",
                    "res"=>""
                );
                $return  = wsCommon::returnArray(wsCommon::OP_TYPE_SYSTEM, $param2);

                $server->push($client->getFd(),$return);
                $i++;
            }
        });
    }


    function sendAll(){

        //发送到全部人
        $table = TableManager::getInstance()->get("talk_users");

        $server = ServerManager::getInstance()->getSwooleServer();

        $param = array(
            "msg"=>"群发",
            "room"=>"",
            "fd"=>"",
            "to_fd"=>"",
            "res"=>""
        );

        $return  = wsCommon::returnArray(wsCommon::OP_TYPE_SYSTEM,$param);

        foreach($table as $key=>$val){
            $server->push($val["talk_users_fd_id"],$return);
        }
    }

    function sendAllIn(){

        $fd = $this->caller()->getClient()->getFd();

        //发送到全部人
        $table = TableManager::getInstance()->get("talk_users");

        $server = ServerManager::getInstance()->getSwooleServer();

        $param = array(
            "msg"=>'用户进入:fd-'.$fd,
            "room"=>"",
            "fd"=>"",
            "to_fd"=>"",
            "res"=>""
        );

        $return  = wsCommon::returnArray(wsCommon::OP_TYPE_SYSTEM,$param);

        foreach($table as $key=>$val){
            $server->push($val["talk_users_fd_id"],$return);
        }

    }

    function serviceclose(){
        //服务器主动关闭
        $client = $this->caller()->getClient();
        $client ->close();

    }

    function serviceclosefd(){
        //指定fd 关闭
        $fd = $this->caller()->getClient()->getFd();
        $server = $server = ServerManager::getInstance()->getSwooleServer();
        $server ->close($fd);
    }


    function createRoom(){

        $server = ServerManager::getInstance()->getSwooleServer();

        $table = TableManager::getInstance()->get("room");

        $tableCount = TableManager::getInstance()->get("room_count");
        $tableCount->incr(0,"count",1);
        $count = $tableCount->get(0,"count");

        $table->set($count+1,["id"=>$count+1,"name"=>"房间".($count+1)]);

        //向所有用户发送房间 列表

        $users = TableManager::getInstance()->get("talk_users");

        $rooms = array();
        foreach($table as $key=>$val){

            $rooms[$key] = array("name"=>$val["name"],"id"=>$key);
        }

        $param = array(
            "msg"=>"获取成功",
            "room"=>"",
            "fd"=>"",
            "to_fd"=>"",
            "res"=>$rooms
        );

        $result = wsCommon::returnArray(wsCommon::OP_TYPE_FRUSHROOM,$param);

        foreach($users as $key=>$val){
            $server->push($key,$result);
        }

    }

    function delRoom(){

        $data = $this->caller()->getArgs();
        $roomKey = $data["value"];

        $server = ServerManager::getInstance()->getSwooleServer();
        $table = TableManager::getInstance()->get("room");

        if($table->exist($roomKey)){
            //删除房间
            $table->del($roomKey);
            //关房要同时删除 房间里面的用户
            $room_fds = TableManager::getInstance()->get("room_fd");
            foreach($room_fds as $rf_key=>$rf_val){

                $params = explode("_",$rf_key);
                if($params[0]==$roomKey){
                    $room_fds->del($rf_key);
                }
            }
        }

        $rooms = array();
        foreach($table as $key=>$val){
            $rooms[$key] = $val["name"];
        }

        //向所有用户发送新房间 列表

        $users = TableManager::getInstance()->get("talk_users");

        $param = array(
            "msg"=>"获取成功",
            "room"=>"",
            "fd"=>"",
            "to_fd"=>"",
            "res"=>$rooms
        );

        $result = wsCommon::returnArray(wsCommon::OP_TYPE_FRUSHROOM,$param);

        foreach($users as $key=>$val){
            $server->push($key,$result);
        }




    }

    /**
     * 用户进房
     */
    function goInRoom(){

        $data = $this->caller()->getArgs();
        $roomKey = $data["value"];

        $fd = $this->caller()->getClient()->getFd();

        $server = ServerManager::getInstance()->getSwooleServer();
        $table = TableManager::getInstance()->get("room");
        if($table->exist($roomKey)){


            $room_fd = TableManager::getInstance()->get("room_fd");

            if(!$room_fd->exist($roomKey."_".$fd)) {

                //通知房间里面的人

                $user_table = TableManager::getInstance()->get("talk_users")->get($fd);

                $param = array(
                    "msg"=>'用户:' . $user_table['talk_user_fd_name'] . ":" . "进入房间" . $roomKey,
                    "room"=>$roomKey,
                    "fd"=>$fd,
                    "to_fd"=>"",
                    "res"=>["name"=>$user_table["talk_user_fd_name"],"id"=>$user_table["talk_users_fd_id"]]
                );

                $result = wsCommon::returnArray(wsCommon::OP_TYPE_ENTERROOM,$param);

                foreach ($room_fd as $rf_key => $rf_val) {

                    $params = explode("_", $rf_key);
                    if ($params[0] == $roomKey) {
                        $server->push($rf_val["fd"], $result);
                    }
                }

                $room_fd->set($roomKey . "_" . $fd, ["fd" => $fd, "room_id" => $roomKey]);
            }
        }
    }

    /**
     * 退房
     */
    function outRoom(){

        $data = $this->caller()->getArgs();
        $roomKey = $data["value"];

        $fd = $this->caller()->getClient()->getFd();

        $server = ServerManager::getInstance()->getSwooleServer();
        $table = TableManager::getInstance()->get("room");
        if($table->exist($roomKey)){

            $room_fd = TableManager::getInstance()->get("room_fd");
            if($room_fd->exist($roomKey."_".$fd)) {
                $room_fd->del($roomKey . "_" . $fd);
                //通知房间里面的人

                $user_table = TableManager::getInstance()->get("talk_users")->get($fd);

                $param = array(
                    "msg"=>'用户:' . $user_table['talk_user_fd_name'] . ":" . "退出房间" . $roomKey,
                    "room"=>$roomKey,
                    "fd"=>$fd,
                    "to_fd"=>"",
                    "res"=>""
                );

                $result = wsCommon::returnArray(wsCommon::OP_TYPE_OUTROOM, $param);

                foreach ($room_fd as $rf_key => $rf_val) {

                    $params = explode("_", $rf_key);
                    if ($params[0] == $roomKey) {
                        $server->push($rf_val["fd"], $result);
                    }
                }
            }
        }
    }

    /**
     * 发送房间消息
     */
    function sendRoom(){

        $data = $this->caller()->getArgs();
        $roomKey = $data["room"];
        $msg = $data["msg"];
        $fd = $this->caller()->getClient()->getFd();

        $param = array(
            "msg"=>$msg,
            "room"=>$roomKey,
            "fd"=>$fd,
            "to_fd"=>"",
            "res"=>""
        );

        $result = wsCommon::returnArray(wsCommon::OP_TYPE_SENDROOMMSG,$param);
        $server = ServerManager::getInstance()->getSwooleServer();
        $table = TableManager::getInstance()->get("room");
        if($table->exist($roomKey)){

            $room_fd = TableManager::getInstance()->get("room_fd");
            if($room_fd->exist($roomKey."_".$fd)) {
                //通知房间里面的人
                foreach ($room_fd as $rf_key => $rf_val) {
                    $params = explode("_", $rf_key);
                    if ($params[0] == $roomKey) {
                        $server->push($rf_val["fd"], $result);
                    }
                }
            }
        }
    }


    function getMyRooms(){

        $server = ServerManager::getInstance()->getSwooleServer();

        $fd = $this->caller()->getClient()->getFd();

        $room_fd = TableManager::getInstance()->get("room_fd");

        $myRooms = array();

        foreach($room_fd as $rf_key=>$rf_val){
            $params = explode("_",$rf_key);
            if($params[1]==$fd){
                $myRooms[] = $params[0];
            }
        }

        $param = array(
            "msg"=>"获取成功",
            "room"=>"",
            "fd"=>$fd,
            "to_fd"=>"",
            "res"=>$myRooms
        );

        $result = wsCommon::returnArray(wsCommon::OP_TYPE_MY_ROOMS,$param);

        $server->push($fd , $result);

    }


    function getRoomUsers(){

        $fd = $this->caller()->getClient()->getFd();

        $server = ServerManager::getInstance()->getSwooleServer();

        $data = $this->caller()->getArgs();
        $room = $data["value"];

        $room_fd = TableManager::getInstance()->get("room_fd");

        $room_users = array();

        foreach($room_fd as $rf_key=>$rf_val){
            $param = explode("_",$rf_key);
            if($param[0]==$room){

                $user_table = TableManager::getInstance()->get("talk_users")->get($param[1]);

                $room_users[] = $user_table;
            }
        }
        $param = array(
            "msg"=>"获取成功",
            "room"=>$room,
            "fd"=>$fd,
            "to_fd"=>"",
            "res"=>$room_users
        );
        $result = wsCommon::returnArray(wsCommon::OP_TYPE_ROOM_USERS,$param);

        $server->push($fd , $result);

    }


    function getAllRooms(){

        $server = ServerManager::getInstance()->getSwooleServer();

        $fd = $this->caller()->getClient()->getFd();

        $roomsData = TableManager::getInstance()->get("room");

        $rooms = array();

        foreach($roomsData as $rf_key=>$rf_val){
            $rooms[] = ["id"=>$rf_val["id"],"name"=>$rf_val["name"]];
        }

        $param = array(
            "msg"=>"获取成功",
            "room"=>"",
            "fd"=>$fd,
            "to_fd"=>"",
            "res"=>$rooms
        );

        $result = wsCommon::returnArray(wsCommon::OP_TYPE_ALL_ROOMS,$param);

        $server->push($fd , $result);

    }


}