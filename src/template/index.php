<?php
// 站点首页
global $db, $ver, $base, $config;

// 限定最近 30 天，否则每次都要全表扫 announces 再整体排序；
// group by 取 clubs 主键，name / nickname 才满足 ONLY_FULL_GROUP_BY
$pdo = $db->prepare('select c.name, c.nickname, max(a.timestamp) as `active` from `announces` as `a`'.
    ' join `clubs` as `c` on a.cid = c.cid where a.timestamp >= :since'.
    ' group by c.cid order by `active` desc limit 20');
$pdo->execute([':since' => time() - 86400 * 30]);
$clubs = $pdo->fetchAll(PDO::FETCH_ASSOC);

// 形如 @用户@实例 才拼得出主页地址，写成别的格式就只显示文本
$maintainer = explode('@', (string)$config['node']['maintainer']['name']);
$maintainer_url = count($maintainer) == 3 ? 'https://'.$maintainer[2].'/@'.$maintainer[1] : '';
// node 下的配置只有管理员能写，故意不转义，留给站点自己塞样式之类的改动
?>
<title><?= $config['node']['name'] ?></title>
<style>a{color:#000;text-decoration:none}</style>
<h3><?= $config['node']['name'] ?> (<a href="https://github.com/wxwmoe/wxwClub" target="_blank">wxwClub/<?= Club_Html($ver) ?></a>)</h3>
<p><?= $config['node']['description'] ?></p>
<p><b><br>热门群组</b></p>
<?php if (!$clubs): ?>
<p>最近还没有群组冒泡，快去 @ 一个把它叫醒吧 ~</p>
<?php else: foreach ($clubs as $club): $name = Club_Html($club['name']); ?>
<p><a href="<?= $base ?>/club/<?= $name ?>" target="_blank"><?= Club_Html($club['nickname'] ?: $club['name']) ?> (@<?= $name ?>@<?= Club_Html($config['base']) ?>)</a></p>
<?php endforeach; endif; ?>
<br>
<p style="font-size:14px">Maintainer:
    <?php if ($maintainer_url): ?><a rel="me" href="<?= Club_Html($maintainer_url) ?>" target="_blank"><?= $config['node']['maintainer']['name'] ?></a>
    <?php else: ?><?= $config['node']['maintainer']['name'] ?><?php endif; ?>
    (mail: <?= $config['node']['maintainer']['email'] ?>)
</p>
