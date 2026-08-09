# wxwClub

A simple social groups compatible with ActivityPub.

> 项目仍在开发阶段，不建议用在生产环境 ...

## 特性

### 已实现

- 兼容 WebFinger 查找
- 兼容 Mastodon 安全模式
- 简单兼容 ActivityPub 协议
  - 响应 关注 / 取消关注 请求
  - 转发收到的 公开 / 不公开 消息
  - 收到旧消息 Tombstone 时撤销转发
  - 收到跨站用户 Delete 时清理关注关系
- 单个群组 Actor 支持自定义修改
  - 个人资料页　头像、横幅、昵称
  - 中文简介、英文简介、简介模板
- 投稿限流，支持 用户 / 群组 / 实例 / 重复内容 四类规则
- 触发限流时私信提醒投稿者，原帖删除后自动撤回提醒
- 提醒文案多语言，按对端 contentMap 自动选择
- 站点首页、群组主页、Outbox，均为游标翻页
- Push 任务队列，自动重试，多次失败进黑名单
- Shared Inbox、Outbox 实现
- 跨站消息 HTTP Signature 校验
  - 校验 Date / Digest，签名主体与 actor 绑定
  - 出站请求只允许公网地址，跳转逐跳重签
- 兼容 Mastodon、Misskey、Pleroma

### 待实现
- 私信修改 Actor 信息
- RsaSignature2017 生成

## 使用

### 环境要求
- MySQL 数据库
- PHP 版本 >= 7.3
- 依赖 PHP 扩展：curl, json, pcntl, posix, openssl, pdo_mysql

### 安装步骤
1. 编辑 `config.php` 参数
2. 导入 `tools/wxwclub.sql` 数据表
3. 重写请求至 `index.php`，例如 Nginx：
```
    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }
```
4. 运行 `wxwClub worker`，推荐 Docker：
```
    1. cd wxwClub/
    2. docker build -t 'wxwclub:worker' .
    3. docker run -d --restart always --stop-timeout 30 -v $(pwd):/wxwClub \
    --name wxwclub_worker wxwclub:worker php /wxwClub/cli.php worker
```
5. （可选）在 `config.php` 的 `worker` 里调整各类队列的进程数。每个部署只运行一个
   worker master，扩容请调大 `worker.delivery` / `worker.probe`，不要再起一个容器：
   维护队列是每个 master 固定一个，多开会重复扫描并让监控指标翻倍
6. 升级前先做可恢复快照、停止 worker，并在拉取代码期间挡住 web 写入口。数据库结构与
   代码版本不相等时前端返回 503；库落后时 worker 自动合并并直接退出，容器按
   `--restart always` 重启后才起队列进程。直接运行 CLI 则要在迁移成功后再启动一次 worker。
   库比代码新时旧代码会拒绝启动，不会尝试降级。也可以用 `php cli.php migrate` 手动合并

## 版权声明

> (> ʌ <) 都看到这了，点个 Star 吧 ~

参考项目  
[wordpress-activitypub / MIT][1]  
[php-curl-class / Unlicense License][2]  
  
MIT © FGHRSH

  [1]: https://github.com/pfefferle/wordpress-activitypub "ActivityPub for WordPress"
  [2]: https://github.com/php-curl-class/php-curl-class "php-curl-class"
