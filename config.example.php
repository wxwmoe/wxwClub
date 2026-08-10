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
    // 实例设置
    'node' => [
        // 实例名称
        'name' => 'example.com',
        // 实例描述
        'description' => 'A simple social groups compatible with ActivityPub.',
        // 实例时区
        'timezone' => 'Asia/Shanghai',
        // 管理信息，name 写成 @用户@实例 首页才出得了链接
        'maintainer' => ['name' => '@admin@example.com', 'email' => 'support@example.com'],
        // 预设语言，识别不出对方语言时使用（对应 src/i18n/ 下的文件名）
        'language' => 'en',
        // 安全模式，关掉等于谁都能往 inbox 里塞消息，不建议
        'inbox-verify' => true,
        // 日志级别，由少到多（ silent / error / warning / info / debug ）
        // logs/event/ 是事件流，logs/stat/ 是各进程的定期汇总和心跳，都按天一个文件；其余目录按请求或事件切成单独文件
        // 这两个流在 worker 每次启动时还会再切一刀：当天已有的那份挪成 .log.001，再启动一次挪成 .log.002，序号越大越晚，补零对齐所以按文件名排序就是时间顺序
        // 采集器按 *.log 收的话会漏掉重启前那几段，要连 *.log.* 一起收
        // 注意 silent 只关掉 logs/ 下的写入，PHP 自身的报错仍受 php.ini 的 log_errors 控制
        'log-level' => 'info',
        // 日志保留天数，0 不清理
        'log-retention' => 30
    ],
    // DNS 解析，只支持 DoH
    'dns' => [
        // 按顺序尝试。配两家以上时，只有各家一致答 SERVFAIL 才把解析失败算到对端头上；只配一家就没有复核，那一家的 SERVFAIL 直接算数
        'resolver' => [
            // ip 是域名的固定地址，填了就直接钉给 curl；留空则由 curl 去解析这个域名
            ['url' => 'https://one.one.one.one/dns-query', 'ip' => ['1.1.1.1', '1.0.0.1', '2606:4700:4700::1111', '2606:4700:4700::1001']],
            ['url' => 'https://dns.google/resolve', 'ip' => ['8.8.8.8', '8.8.4.4', '2001:4860:4860::8888', '2001:4860:4860::8844']]
        ],
        // 单次查询超时，秒。A 和 AAAA 各查一次，最坏耗时是它乘二再乘 resolver 条数
        'timeout' => 5,
        // 连接超时，秒
        'connect-timeout' => 3
    ],
    // 队列进程数，按类型分别配置。每个进程占一条 mysql 连接
    'worker' => [
        // 投递队列：瓶颈是等对端的那次 curl，慢实例能占住一个进程十几秒。同一条 endpoint 只放一次投递在途，进程数超过可领的 endpoint 数就是纯空转
        'delivery' => 8,
        // 探活队列：只跑黑名单恢复探测，频率很低，0 表示不再探活、进了黑名单就不出来
        'probe' => 1
        // 另有一个维护队列，负责日志轮换、过期清理、对账和黑名单清理。它是全站一份的活，多开只是把同样的事做 N 遍，所以固定一个、不作配置
    ],
    // 群组设置
    'club' => [
        // 开放新群组注册
        'open-registrations' => true,
        // 每小时最多新建的群组数，0 不限制
        'create-limit' => 10,
        // 禁用的群组名称
        'suspended-names' => ['yourgroupname'],
        // 转发原始报文（编辑、删除）的体积上限，KB
        // 参考：2 万字的中文投稿约 118 KB
        'relay-limit' => 512,
        
        /********************************
         *        限  流  规  则        *
         * ---------------------------- *
         * type   => 按谁计数           *
         *   user => 单个用户           *
         *   club => 整个群组           *
         *   site => 投稿者的实例       *
         *   dupl => 单个用户的重复内容 *
         * hours  => 时间窗口，小时     *
         * limit  => 窗口内的条数       *
         ********************************/

        // 同一群组可叠加多条规则，按顺序判断，触发哪条回哪条
        // 四种都只统计本群组，同一内容投给不同群组互不影响
        'limits' => [
            'yourtestgroup' => ['type' => 'user', 'hours' => 24, 'limit' => 10],
            'yourbusygroup' => [
                ['type' => 'dupl', 'hours' => 24, 'limit' => 1],
                ['type' => 'user', 'hours' => 24, 'limit' => 5],
                ['type' => 'site', 'hours' => 1, 'limit' => 20],
                ['type' => 'club', 'hours' => 1, 'limit' => 60]
            ]
        ],
        // 用于私信提醒用户的系统群组，不开放注册、不进目录、不接受关注
        'system-name' => 'system'
    ],
    // 触发限制时的私信提醒
    'notice' => [
        // 是否发送提醒
        'enabled' => true,
        // 每个用户每天最多收到的条数
        'limit' => 20,
        // 提醒保留天数，超过后撤回并清理，0 不清理
        'retention' => 30
    ]
];