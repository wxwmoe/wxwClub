<?php $config = [
    // 实例主域名
    'base' => 'example.com',
    // 数据库信息
    'mysql' => [
        // 数据库地址
        'host' => 'mysql',
        // 数据库名称
        'database' => 'localhost',
        // 数据库用户
        'username' => 'root',
        // 数据库密码
        'password' => ''
    ],
    // 默认模板
    'default' => [
        // 头像外链
        'avatar' => 'https://fp1.fghrsh.net/2021/11/03/1568571d1ed0bfaef26acdf6d5664826.png',
        // 横幅外链
        'banner' => 'https://fp1.fghrsh.net/2021/10/25/86dbef8672928e061a5ce1e5722e8056.png',
        
        /****************************
         *      预  设  标  签      *
         * ------------------------ *
         * :club_name: => 群组名    *
         * :local_domain: => 主域名 *
         ****************************/
         
        // 简介模板
        'summary' => '<p>这是一个关于 :infoname_cn: 的群组，关注以获取群组推送，引用可以分享到群组。</p><p>I\'m a group about :infoname_en:. Follow me to get all the group posts. Tag me to share with the group.</p><p>创建新群组可以 搜索 或 引用 @新群组名@:local_domain:。</p><p>Create other groups by searching for or tagging @yourGroupName@:local_domain:</p>',
        // 默认昵称
        'nickname' => ':club_name: 组',
        // 自定标签
        'infoname' => [':infoname_cn:' => ':club_name:', ':infoname_en:' => ':club_name:']
    ],
    // 实例名称
    'nodeName' => 'example.com',
    // 实例时区
    'nodeTimezone' => 'Asia/Shanghai',
    // 调试模式
    'nodeDebugging' => false,
    // 安全模式
    'nodeInboxVerify' => false,
    // 管理信息
    'nodeMaintainer' => ['name' => '@admin', 'email' => 'support@example.com'],
    // 实例描述
    'nodeDescription' => 'A simple social groups compatible with ActivityPub.',
    // 禁用的群组名称
    'nodeSuspendedName' => ['yourgroupname'],
    // 开放新群组注册
    'openRegistrations' => true
];