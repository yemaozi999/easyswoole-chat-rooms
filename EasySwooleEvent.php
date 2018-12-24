<?php
/**
 * Created by PhpStorm.
 * User: yf
 * Date: 2018/5/28
 * Time: 下午6:33
 */

namespace EasySwoole\EasySwoole;


use App\Crontab\TaskOne;
use App\Crontab\TaskTwo;
use App\HttpController\Pool\Redis;
use App\Log\LogHandler;
use App\Process\HotReload;
use App\Process\ProcessTaskTest;
use App\Process\ProcessTest;
use App\Rpc\RpcServer;
use App\Rpc\ServiceOne;
use App\Utility\ConsoleCommand\Test;
use App\Utility\ConsoleCommand\TrackerLogCategory;
use App\Utility\ConsoleCommand\TrackerPushLog;
use App\Utility\Pool\MysqlPool;
use App\Utility\Pool\RedisPool;
use App\Utility\TrackerManager;
use App\WebSocket\WebSocketEvent;
use App\WebSocket\WebSocketParser;
use App\WebSocket\wsBase;
use App\WebSocket\wsCommon;
use EasySwoole\Component\Di;
use EasySwoole\Component\Openssl;
use EasySwoole\Component\Pool\PoolManager;
use EasySwoole\EasySwoole\AbstractInterface\Event;
use EasySwoole\EasySwoole\Console\CommandContainer;
use EasySwoole\EasySwoole\Console\TcpService;
use EasySwoole\EasySwoole\Crontab\Crontab;
use EasySwoole\EasySwoole\Swoole\EventRegister;
use EasySwoole\EasySwoole\Swoole\Memory\TableManager;
use EasySwoole\EasySwoole\Swoole\Process\Helper;
use EasySwoole\EasySwoole\Swoole\Task\TaskManager;
use EasySwoole\EasySwoole\Swoole\Time\Timer;
use EasySwoole\Http\Request;
use EasySwoole\Http\Response;
use EasySwoole\Rpc\Pack;
use EasySwoole\Rpc\RequestPackage;
use EasySwoole\Socket\Client\Tcp;
use EasySwoole\Socket\Dispatcher;
use EasySwoole\Trace\Bean\Tracker;
use EasySwoole\Utility\File;
use Swoole\Process;
use Swoole\Server;
use Swoole\Table;

class EasySwooleEvent implements Event
{
    /**
     * 框架初始化事件
     * 在Swoole没有启动之前 会先执行这里的代码
     */
    public static function initialize()
    {
        // TODO: Implement initialize() method.
        date_default_timezone_set('Asia/Shanghai');//设置时区
        $tempDir = EASYSWOOLE_ROOT . '/Temp2';
        Config::getInstance()->setConf('TEMP_DIR', $tempDir);//重新设置temp文件夹
        Di::getInstance()->set(SysConst::SHUTDOWN_FUNCTION, function () {//注册自定义代码终止回调
            $error = error_get_last();
            if (!empty($error)) {
                var_dump($error);
            }
        });

        //调用链追踪器设置Token获取值为协程id
        TrackerManager::getInstance()->setTokenGenerator(function () {
            return \Swoole\Coroutine::getuid();
        });
        //每个链结束的时候，都会执行的回调
        TrackerManager::getInstance()->setEndTrackerHook(function ($token, Tracker $tracker) {
//            Logger::getInstance()->console((string)$tracker);
            //这里请读取动态配置 TrackerPushLog 来判断是否推送，读取TrackerLogCategory 判断推送分类
            $trackerPushLogStatus = Config::getInstance()->getDynamicConf('CONSOLE.TRACKER_PUSH_LOG');
            if ($trackerPushLogStatus) {
                $trackerLogCategory = Config::getInstance()->getDynamicConf('CONSOLE.TRACKER_LOG_CATEGORY');
                if ($trackerLogCategory) {
                    if (in_array('all', $trackerLogCategory)) {
                        TcpService::push((string)$tracker);
                    } else {
                        TcpService::push($tracker->toString($trackerLogCategory));
                    }
                }
            }
        });

        // 设置Tracker的推送配置和命令，以下配置请写入动态配置项
        CommandContainer::getInstance()->set('trackerPushLog', new TrackerPushLog());
        CommandContainer::getInstance()->set('trackerLogCategory', new TrackerLogCategory());
        \EasySwoole\EasySwoole\Console\CommandContainer::getInstance()->set('Test', new Test());
        //默认开启，推送全部日志
        Config::getInstance()->setDynamicConf('CONSOLE.TRACKER_LOG_CATEGORY', ['all']);
        Config::getInstance()->setDynamicConf('CONSOLE.TRACKER_PUSH_LOG', true);


        //引用自定义文件配置
        self::loadConf();
        Config::getInstance()->setDynamicConf('test_config_value', 0);//配置一个动态配置项
        Config::getInstance()->setConf('test_config_value', 0);//配置一个普通配置项

        // 注册mysql数据库连接池
        PoolManager::getInstance()->register(MysqlPool::class, Config::getInstance()->getConf('MYSQL.POOL_MAX_NUM'));

        // 注册redis连接池
        PoolManager::getInstance()->register(RedisPool::class, Config::getInstance()->getConf('REDIS.POOL_MAX_NUM'));

        // 注入日志处理类
        Logger::getInstance()->setLoggerWriter(new LogHandler());
    }

    public static function mainServerCreate(EventRegister $register)
    {
        // TODO: Implement mainServerCreate() method.

        //注册onWorkerStart回调事件
        $register->add($register::onWorkerStart, function (\swoole_server $server, int $workerId) {
            // var_dump('worker:' . $workerId . 'start');
        });

        // 自定义进程注册例子
        $swooleServer = ServerManager::getInstance()->getSwooleServer();
        $swooleServer->addProcess((new ProcessTest('test_process'))->getProcess());
        //注册异步任务自定义进程
        $swooleServer->addProcess((new ProcessTaskTest('ProcessTaskTest'))->getProcess());
        //自适应热重启 虚拟机下可以传入 disableInotify => true 强制使用扫描式热重启 规避虚拟机无法监听事件刷新
        $swooleServer->addProcess((new HotReload('HotReload', ['disableInotify' => false]))->getProcess());

        //添加子服务监听
        $subPort = ServerManager::getInstance()->getSwooleServer()->addListener('0.0.0.0', 9502, SWOOLE_TCP);
        $subPort->on('receive', function (\swoole_server $server, int $fd, int $reactor_id, string $data) {
            echo "subport on receive \n";
        });
        $subPort->on('connect', function (\swoole_server $server, int $fd, int $reactor_id) {
            echo "subport on connect \n";
        });

        //主swoole服务修改配置
        ServerManager::getInstance()->getSwooleServer()->set(
            [
                'task_async' => true,
                'log_level' => 5
            ]);


        /**
         * **************** tcp控制器 **********************
         */
        $server = ServerManager::getInstance()->getSwooleServer();
        $subPort = $server->addListener('0.0.0.0', 9503, SWOOLE_TCP);
        $subPort->set(
            ['open_length_check' => false]//不验证数据包
        );
        $socketConfig = new \EasySwoole\Socket\Config();
        $socketConfig->setType($socketConfig::TCP);
        $socketConfig->setParser(new \App\TcpController\Parser());
        //设置解析异常时的回调,默认将抛出异常到服务器
        $socketConfig->setOnExceptionHandler(function (Server $server, $throwable, $raw, Tcp $client, $response) {
            $server->send($client->getFd(), 'bye');
            $server->close($client->getFd());
        });
        $dispatch = new \EasySwoole\Socket\Dispatcher($socketConfig);
        $subPort->on('receive', function (\swoole_server $server, int $fd, int $reactor_id, string $data) use ($dispatch) {
            $dispatch->dispatch($server, $data, $fd, $reactor_id);
        });

        /**
         * **************** websocket控制器 **********************
         */

        //新增一个swoole_table 记录每个进入的fd_id
        $table = TableManager::getInstance();
        $table->add("talk_users",
            [
                "talk_users_fd_id"=>['type'=>Table::TYPE_INT,'size'=>11],
                "talk_user_fd_name"=>['type'=>Table::TYPE_STRING,'size'=>50]
            ],
            128
        );


        //*******************************动态开房表****************************************
        //房间数量表, 用作自增  key 0
        $table->add("room_count",
            ["count"=>["type"=>Table::TYPE_INT,'size'=>6]],
            12
        );
        //初始化
        $tableCount = TableManager::getInstance()->get("room_count");
        $tableCount->set(0,["count"=>0]);

        //房间表,记录所有房间   key格式  roomid
        $table->add("room",
          [
              "id"=>["type"=>Table::TYPE_INT,'size'=>6],
              "name"=>["type"=>Table::TYPE_STRING,'size'=>20],
          ],
          16
        );
        //房间用户表 记录房间有什么用户 key格式  roomid_fdid
        $table->add("room_fd",
            [
                "fd"=>["type"=>Table::TYPE_INT,'size'=>6],
                "room_id"=>["type"=>Table::TYPE_INT,'size'=>6],
            ],
            16
        );
        //*******************************动态开房表end****************************************


        // 创建一个 Dispatcher 配置
        $conf = new \EasySwoole\Socket\Config();
        // 设置 Dispatcher 为 WebSocket 模式
        $conf->setType($conf::WEB_SOCKET);
        // 设置解析器对象
        $conf->setParser(new WebSocketParser());
        // 创建 Dispatcher 对象 并注入 config 对象
        $dispatch = new Dispatcher($conf);
        // 给server 注册相关事件 在 WebSocket 模式下  message 事件必须注册 并且交给 Dispatcher 对象处理
        $register->set(EventRegister::onMessage, function (\swoole_websocket_server $server, \swoole_websocket_frame $frame) use ($dispatch) {
            $dispatch->dispatch($server, $frame->data, $frame);
        });
        //自定义握手
        $websocketEvent = new WebSocketEvent();
        $register->set(EventRegister::onHandShake, function (\swoole_http_request $request, \swoole_http_response $response) use ($websocketEvent,$table) {

            $fdTable = $table->get("talk_users");
            $fdTable->set($request->fd,['talk_users_fd_id'=>$request->fd,'talk_user_fd_name'=>'游客'.$request->fd]);


            $websocketEvent->onHandShake($request, $response);
        });



        $register->set(EventRegister::onClose, function (\swoole_server $server, int $fd, int $reactorId) use ($websocketEvent,$table) {

            //清除退出的fd
            $fdTable = $table->get("talk_users");

            $fd_name = $fdTable->get($fd)["talk_user_fd_name"];

            $fdTable->del($fd);



            //退场群发
            foreach($fdTable as $key=>$val){

                foreach($fdTable as $key=>$val){

                    $param = array(
                        "msg"=>$fd_name."退出了",
                        "room"=>"",
                        "fd"=>"",
                        "to_fd"=>"",
                        "res"=>""
                    );

                    $return  = wsCommon::returnArray(wsCommon::OP_TYPE_SYSTEM,$param);

                    $server->push($val["talk_users_fd_id"],$return);
                }
            }

            $room_fd = $table->get("room_fd");

            //需要通知那些房间
            $send_rooms = array();
            foreach($room_fd as $key=>$val){
                $params = explode("_",$key);
                if($params[1]==$fd){
                    $room_fd->del($key);
                    $send_rooms[] = $params[0];
                }
            }

            foreach($room_fd as $key=>$val){
                $par = explode("_",$key);

                $param2 = array(
                    "msg"=>'用户:'.$fd_name."退出房间",
                    "room"=>$par[0],
                    "fd"=>$fd,
                    "to_fd"=>"",
                    "res"=>""
                );

                $return  = wsCommon::returnArray(wsCommon::OP_TYPE_OUTROOM,$param2);
                if(in_array($par[0],$send_rooms)){
                    $server->push($key,$return);
                }

            }

            $websocketEvent->onClose($server, $fd, $reactorId);
        });

        /**
         * **************** udp服务 **********************
         */

        //新增一个udp服务
        $server = ServerManager::getInstance()->getSwooleServer();
        $subPort = $server->addListener('0.0.0.0', '9605', SWOOLE_UDP);
        $subPort->on('packet', function (\swoole_server $server, string $data, array $client_info) {
//            echo "udp packet:{$data}";
        });
        //udp客户端
        //添加自定义进程做定时udp发送
        $server->addProcess(new \swoole_process(function (\swoole_process $process) {
            //服务正常关闭
            $process::signal(SIGTERM, function () use ($process) {
                $process->exit(0);
            });
            //每隔5秒发送一次数据
            \Swoole\Timer::tick(5000, function () {
                if ($sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP)) {
                    socket_set_option($sock, SOL_SOCKET, SO_BROADCAST, true);
                    $msg = '123456';
                    socket_sendto($sock, $msg, strlen($msg), 0, '255.255.255.255', 9605);//广播地址
                    socket_close($sock);
                }
            });
        }));


        /**
         * **************** 异步客户端 **********************
         */
        //纯原生异步
        ServerManager::getInstance()->getSwooleServer()->addProcess(new Process(function ($worker) {
            $client = new \swoole_client(SWOOLE_SOCK_TCP, SWOOLE_SOCK_ASYNC);
            $client->on("connect", function (\swoole_client $cli) {
                $cli->send("test:delay");
            });
            $client->on("receive", function (\swoole_client $cli, $data) {
                echo "Receive: $data";
                $cli->send("test:delay");
                sleep(1);
            });
            $client->on("error", function (\swoole_client $cli) {
                echo "error\n";
            });
            $client->on("close", function (\swoole_client $cli) {
                echo "Connection close\n";
            });
            $client->connect('192.168.159.1', 9502);


            //本demo自定义进程采用的是原生写法,如果需要使用,请使用自定义进程类模板开发
            if (extension_loaded('pcntl')) {//异步信号,使用自定义进程类模板不需要该代码
                pcntl_async_signals(true);
            }
            Process::signal(SIGTERM, function () use ($worker) {//信号回调,使用自定义进程类模板不需要该代码
                $worker->exit(0);
            });
        }));

        /**
         * **************** Crontab任务计划 **********************
         */
        // 开始一个定时任务计划
        Crontab::getInstance()->addTask(TaskOne::class);
        // 开始一个定时任务计划
        Crontab::getInstance()->addTask(TaskTwo::class);

        /**
         * **************** Rpc2.0 默认demo **********************
         */
        $rpcConfig = new \EasySwoole\Rpc\Config();
        $rpcConfig->setServiceName('ServiceOne');
        $rpcConfig->setBroadcastTTL(4);//广播时间间隔
        //$rpcConfig->setAuthKey('123456');//开启通讯密钥

        $rpc = RpcServer::getInstance($rpcConfig);
        ##########自定义控制器写法 开始####################
        $rpcConfig->setOnRequest(function (RequestPackage $package, \EasySwoole\Rpc\Response $response,\EasySwoole\Rpc\Config $config, \swoole_server $server, int $fd){
            try{
                $class ='App\\Rpc\\'. $config->getServiceName();
                var_dump($class);
                new $class($package,$response,$config,$server,$fd);
            }catch (\Throwable $throwable){
                $response->setStatus($response::STATUS_SERVER_ERROR);
                $response->setMessage("{$throwable->getMessage()} at file {$throwable->getFile()} line {$throwable->getLine()}");
            }
            if(is_callable($config->getAfterRequest())){
                call_user_func($config->getAfterRequest(),$package,$response,$config,$server,$fd);
            }
            if($server->exist($fd)){
                $msg = $response->__toString();
                if($config->isEnableOpenssl()){
                    $openssl = new Openssl($config->getAuthKey());
                    $msg = $openssl->encrypt($msg);
                }
                $server->send($fd,Pack::pack($msg));
            }

            return false;
        });
        ##########自定义控制器写法结束####################


        //注册action
        $rpc->getActionList()->register('a1', function (RequestPackage $package, \EasySwoole\Rpc\Response $response, \swoole_server $server, int $fd) {
            var_dump($package->getArg());
            return 'AAA';
        });

        $rpc->getActionList()->register('a2', function (RequestPackage $package, \EasySwoole\Rpc\Response $response, \swoole_server $server, int $fd) {
            \co::sleep(0.1);
            return 'a2';
        });

        $server = ServerManager::getInstance()->getSwooleServer();
        //注册广播进程，主动对外udp广播服务节点信息
        $server->addProcess($rpc->getRpcBroadcastProcess());
        //创建一个udp子服务，用来接收udp广播
        $udp = $server->addListener($rpcConfig->getBroadcastListenAddress(),$rpcConfig->getBroadcastListenPort(),SWOOLE_UDP);
        $udp->on('packet',function (\swoole_server $server, string $data, array $client_info)use($rpc){
            $rpc->onRpcBroadcast($server,$data,$client_info);
        });

        //创建一个tcp子服务，用来接收rpc的tcp请求。
        $sub = $server->addListener($rpcConfig->getListenAddress(),$rpcConfig->getListenPort(),SWOOLE_TCP);
        $sub->set($rpcConfig->getProtocolSetting());
        $sub->on('receive',function (\swoole_server $server, int $fd, int $reactor_id, string $data)use($rpc){
            $rpc->onRpcRequest( $server,  $fd,  $reactor_id,  $data);
        });

    }


    /**
     * 引用自定义配置文件
     * @throws \Exception
     */
    public static function loadConf()
    {
        $files = File::scanDirectory(EASYSWOOLE_ROOT . '/App/Config');
        if (is_array($files)) {
            foreach ($files['files'] as $file) {
                $fileNameArr = explode('.', $file);
                $fileSuffix = end($fileNameArr);
                if ($fileSuffix == 'php') {
                    Config::getInstance()->loadFile($file);
                } elseif ($fileSuffix == 'env') {
                    Config::getInstance()->loadEnv($file);
                }
            }
        }
    }

    public static function onRequest(Request $request, Response $response): bool
    {
        //为每个请求做标记
        TrackerManager::getInstance()->getTracker()->addAttribute('workerId', ServerManager::getInstance()->getSwooleServer()->worker_id);
        if ((0/*auth fail伪代码,拦截该请求,判断是否有效*/)) {
            $response->end(true);
            return false;
        }
        // TODO: Implement onRequest() method.
        return true;
    }

    public static function afterRequest(Request $request, Response $response): void
    {
        // TODO: Implement afterAction() method.
        //tracker结束
        TrackerManager::getInstance()->closeTracker();
    }

    public static function onReceive(\swoole_server $server, int $fd, int $reactor_id, string $data): void
    {
        echo "TCP onReceive.\n";

    }

}
