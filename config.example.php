<?php $config = [
    'base' => 'example.com',
    'mysql' => [
        'host' => 'mysql',
        'database' => 'localhost',
        'username' => 'root',
        'password' => ''
    ],
    'default' => [
        'avatar' => 'https://fp1.fghrsh.net/2021/10/25/d4be74ddb653ac6cc0ade052c3541e05.png',
        'banner' => 'https://fp1.fghrsh.net/2021/10/25/86dbef8672928e061a5ce1e5722e8056.png',
        'summary' => '<p>这是一个关于 :infoname_cn: 的群组，关注以获取群组推送，引用可以分享到群组。</p><p>I\'m a group about :infoname_en:. Follow me to get all the group posts. Tag me to share with the group.</p><p>创建新群组可以 搜索 或 引用 @新群组名@:local_domain:。</p><p>Create other groups by searching for or tagging @yourGroupName@:local_domain:</p>'
    ],
    'nodeName' => 'example.com',
    'maintainer' => ['name' => '@admin', 'email' => 'support@example.com'],
    'nodeDescription' => 'A simple groups instance compatible with ActivityPub.',
    'nodeInboxLogs' => false,
    'nodeOutboxLogs' => false,
    'openRegistrations' => true,
    'nodeSuspendedName' => ['yourgroupname']
];