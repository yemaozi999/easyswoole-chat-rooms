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
cp sample.env dev.env
```

## 配置

修改 `dev.env` 内的配置项

```ini
REDIS.HOST = 127.0.0.1  # redis服务器地址
REDIS.PORT = 6379       # redis服务器端口
REDIS.AUTH =            # redis服务器密码 (如果没有密码请注释本行)
```

## 启动

```bash
php easyswoole start
```
