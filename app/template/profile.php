<?php
global $db, $base, $config;

$club     = Club_Template_Escape($vars['club']);
$nickname = Club_Template_Escape($vars['nickname']);
$row      = $vars['row'];
// summary 允许带 HTML（预设值里就有 <p>），且只有管理员能写，故不转义
$summary  = $vars['summary'];

$handle = '@'.$club.'@'.Club_Template_Escape($config['base']);
$avatar = $row['avatar'] ?: $config['default']['avatar'];
$banner = $row['banner'] ?: $config['default']['banner'];

// 游标翻页，只给上一页 / 下一页，不支持跳页，也就没有 offset 越翻越慢的问题
$max = Club_HTTP_Cursor($_GET['max'] ?? '');   // 往旧翻
$min = Club_HTTP_Cursor($_GET['min'] ?? '');   // 往新翻
$asc = (bool)$min;
$where = ''; $params = [':cid' => $row['cid']];
if ($max) {
    $where = ' and a.timestamp <= :ts and (a.timestamp < :ts or a.id < :id)';
    $params[':ts'] = $max[0]; $params[':id'] = $max[1];
} elseif ($min) {
    $where = ' and a.timestamp >= :ts and (a.timestamp > :ts or a.id > :id)';
    $params[':ts'] = $min[0]; $params[':id'] = $min[1];
}
// 多取一条，用来判断这个方向还有没有内容
$pdo = $db->prepare('select a.id, a.timestamp, u.name, act.object, a.summary, a.content from `announces` as `a` left join `users` as `u` on a.uid = u.uid'.
    ' left join `activities` as `act` on a.activity = act.id where a.cid = :cid'.$where.' order by a.timestamp '.($asc ? 'asc' : 'desc').', a.id '.($asc ? 'asc' : 'desc').' limit 21');
$pdo->execute($params);
$activities = $pdo->fetchAll(PDO::FETCH_ASSOC);
$more = count($activities) > 20;
$activities = array_slice($activities, 0, 20);
if ($asc) $activities = array_reverse($activities);

// 往新翻时 $more 说明还有更新的；往旧翻时带着 max 就说明来路上有更新的
$newer = $asc ? $more : (bool)$max;
$older = $asc ? true : $more;
$link = $base.'/club/'.$club;
if ($activities) {
    $head = $activities[0]; $foot = $activities[count($activities) - 1];
    $prev = $link.'?min='.$head['timestamp'].'.'.$head['id'];
    $next = $link.'?max='.$foot['timestamp'].'.'.$foot['id'];
}
?>
<title><?= $nickname ?> (<?= $handle ?>)</title>
<link href="<?= $link ?>" rel="alternate" type="application/activity+json">
<meta content="profile" property="og:type" />
<meta content="<?= Club_Template_Escape($summary) ?>" name="description">
<meta content="<?= $link ?>" property="og:url" />
<meta content="<?= Club_Template_Escape($config['node']['name']) ?>" property="og:site_name" />
<meta content="<?= $nickname ?> (<?= $handle ?>)" property="og:title" />
<meta content="<?= Club_Template_Escape($summary) ?>" property="og:description" />
<meta content="<?= Club_Template_Escape($avatar) ?>" property="og:image" />
<meta content="400" property="og:image:width" />
<meta content="400" property="og:image:height" />
<meta content="summary" property="twitter:card" />
<meta content="<?= $club ?>@<?= Club_Template_Escape($config['base']) ?>" property="profile:username" />
<style>
a{color:#000;text-decoration:none}
details>summary{cursor:pointer;list-style:none}
/* CSS 里实体转义无效，横幅只有管理员能设，保持原样输出 */
.info::before{content:"";background:url(<?= $banner ?>) no-repeat center;background-size:cover;
    opacity:0.35;z-index:-1;position:absolute;width:720px;height:220px;top:0px;left:0px;border-radius:8px;}
</style>
<div class="info">
    <img src="<?= Club_Template_Escape($avatar) ?>" width="50" /><p style="line-height:1px"><br></p>
    <h3 style="position:absolute;top:10px;left:68px"><?= $nickname ?> (<?= $handle ?>)</h3>
    <div style="font-size:14px"><?= $summary ?></div><p style="line-height:1px"><br></p>
</div>
<div style="font-size:14px">
    <p>近期活动：</p>
<?php if (!$activities): ?>
    <p>群组还没有活动，快来发送一条吧 ~</p>
<?php else: foreach ($activities as $activity):
    // 以下几项都是跨站用户可控的内容，一律转义
    $time = date('Y-m-d H:i:s', $activity['timestamp']);
    $who  = Club_Template_Escape($activity['name']);
    $text = Club_Template_Escape($activity['content']);
    $url  = Club_Template_Link($activity['object']); ?>
    <?php if ($activity['summary']): ?>
    <details><summary>[<?= $time ?>] <?= $who ?>: [CW] <?= Club_Template_Escape($activity['summary']) ?></summary>
        <p><?= $url ? '<a href="'.$url.'" target="_blank">'.$text.'</a>' : $text ?></p></details>
    <?php else: ?>
    <p>[<?= $time ?>] <?= $url ? '<a href="'.$url.'" target="_blank">'.$who.': '.$text.'</a>' : $who.': '.$text ?></p>
    <?php endif; ?>
<?php endforeach; endif; ?>
    <p>
    <?php if ($newer && $activities): ?><a href="<?= $prev ?>">上一页</a>
    <?php else: ?><span style="color:#aaa">上一页</span><?php endif; ?>
    |
    <?php if ($older && $activities): ?><a href="<?= $next ?>">下一页</a>
    <?php else: ?><span style="color:#aaa">下一页</span><?php endif; ?>
    </p>
</div>
