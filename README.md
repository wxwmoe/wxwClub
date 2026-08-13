# wxwClub

A simple social groups compatible with ActivityPub.

> 项目仍在开发阶段，不建议用在生产环境 ...

## 特性

- 兼容 WebFinger 查找
- 兼容 Mastodon 安全模式
- 兼容 ActivityPub 群组互操作
  - 响应关注、取消关注请求
  - 转发提及群组的公开、不公开消息
  - 转发带签名的编辑、投票更新和删除活动
  - 用户销户后清理关注关系和历史消息
  - 原消息删除后撤回转发
- 支持个性化的群组简介信息
  - 头像、横幅、昵称均可修改
  - 独立的多语言简介和简介模板
- 投稿限流，支持按用户、群组、实例、重复内容设置规则
- 触发限流时私信提醒投稿者，原消息删除后自动撤回提醒
- 提醒文案支持多语言，按消息 `contentMap` 自动选择语言
- 站点首页、群组页、Outbox 使用游标翻页，避免深分页扫描
- 投递队列自动重试，持续失败的实例进入黑名单并定期探测恢复
- 校验跨站消息的 HTTP Signature
  - 校验 Date / Digest，签名主体与 Actor 绑定
  - 出站请求只允许访问公网地址，跳转逐跳校验并重新签名
- 兼容 Mastodon、Misskey、Pleroma、GoToSocial 互操作

## 使用

### 环境要求
- MySQL 数据库
- PHP 版本 >= 7.3
- 依赖 PHP 扩展：curl, json, pcntl, posix, openssl, pdo_mysql

### 安装步骤
1. 编辑 `config.php` 参数
2. 导入 `app/database/schema.sql` 数据表
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
5. （可选）调整 `config.php` 里 `worker` 的各类队列数；worker 可以多开，队列翻倍。

### 升级步骤
1. `git pull` 拉取新代码
2. `docker restart wxwclub_worker`

数据库结构由 worker 自动合并，期间前端返回 HTTP 503，完成后恢复。

## 版权声明

> (> ʌ <) 都看到这了，点个 Star 吧 ~

ActivityPub 兼容实现参考  
[wordpress-activitypub / MIT][1]  
[mastodon / AGPL-3.0][2]  
[misskey / AGPL-3.0][3]  
  
MIT © FGHRSH

  [1]: https://github.com/Automattic/wordpress-activitypub "ActivityPub for WordPress"
  [2]: https://github.com/mastodon/mastodon "Your self-hosted, globally interconnected microblogging community"
  [3]: https://github.com/misskey-dev/misskey "A completely free and open interplanetary-microblogging platform"
