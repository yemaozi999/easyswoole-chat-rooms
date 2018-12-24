# easyswoole-chat-rooms
一款依靠高性能开源框架easyswoole开发的 多房间聊天室

## 在线体验

[在线DEMO演示站](http://yemaozi.substr.cn/)
[文档地址](https://www.kancloud.cn/yemaozi999/easyswoole-chatrooms)
## 安装

安装时遇到提示是否覆盖 `EasySwooleEvent.php` 请选择否 (输入 n 回车)

```bash
git clone https://github.com/yemaozi999/easyswoole-chat-rooms.git
composer install
easyswoole install
php vendor/easyswoole/easyswoole/bin/easyswoole.php install
cp sample.env dev.env
```

## 配置

修改 `dev.env` 内的配置项

```ini
################ defalut config ##################
SERVER_NAME = EasySwoole

MAIN_SERVER.LISTEN_ADDRESS = 0.0.0.0
MAIN_SERVER.PORT = 9501
MAIN_SERVER.SERVER_TYPE = WEB_SOCKET_SERVER ## 可选为 SERVER  WEB_SERVER WEB_SOCKET_SERVER
MAIN_SERVER.SOCK_TYPE = SWOOLE_TCP  ## 该配置项当为SERVER_TYPE值为SERVER时有效
MAIN_SERVER.RUN_MODEL = SWOOLE_PROCESS

MAIN_SERVER.SETTING.worker_num = 8
MAIN_SERVER.SETTING.max_request = 5000
MAIN_SERVER.SETTING.task_worker_num = 8
MAIN_SERVER.SETTING.task_max_request = 500
TEMP_DIR = null
LOG_DIR = null

CONSOLE.ENABLE = true
CONSOLE.LISTEN_ADDRESS = 127.0.0.1
CONSOLE.HOST = 127.0.0.1
CONSOLE.PORT = 9000
CONSOLE.EXPIRE = 120
CONSOLE.AUTH = null
CONSOLE.PUSH_LOG = true

################ DATABASE CONFIG ##################

MYSQL.host = localhost
MYSQL.port = 3306
MYSQL.user = root
MYSQL.timeout = 5
MYSQL.charset = utf8mb4
MYSQL.password = root
MYSQL.database = easyswoole
MYSQL.POOL_MAX_NUM = 4
MYSQL.POOL_TIME_OUT = 0.1

################ REDIS CONFIG ##################

REDIS.host = 127.0.0.1
REDIS.port = 6379
REDIS.auth =
REDIS.POOL_MAX_NUM = 4
REDIS.POOL_TIME_OUT = 0.1

################ 自定义配置 ##################
DATABASE.ip=127.0.0.1
DATABASE.port=3306
DATABASE.user=root
DATABASE.password=root
```

## 启动

```bash
php easyswoole start
```
