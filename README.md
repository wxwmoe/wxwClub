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
- Push 任务队列，自动重试
- Shared Inbox、Outbox 实现
- 跨站消息 HTTP Signature 校验
- 兼容 Mastodon、Misskey、Pleroma

### 待实现
- 私信修改 Actor 信息
- RsaSignature2017 生成

## 使用

### 环境要求
- MySQL 数据库
- PHP 版本 >= 7.0
- 依赖 PHP 扩展：curl, json, pcntl, pdo_mysql

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
    3. docker run -d --restart always -v $(pwd):/wxwClub \
    --name wxwclub_worker wxwclub:worker php /wxwClub/cli.php worker
```

## 版权声明

> (> ʌ <) 都看到这了，点个 Star 吧 ~

参考项目  
[wordpress-activitypub / MIT][1]  
[php-curl-class / Unlicense License][2]  
  
MIT © FGHRSH

  [1]: https://github.com/pfefferle/wordpress-activitypub "ActivityPub for WordPress"
  [2]: https://github.com/php-curl-class/php-curl-class "php-curl-class"
