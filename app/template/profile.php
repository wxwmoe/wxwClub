<?php
global $db, $base, $config;

$club     = Club_Template_Escape($vars['club']);
$nickname = Club_Template_Escape($vars['nickname']);
$row      = $vars['row'];
// Profile HTML and banner CSS come from administrator settings.
$summary  = $vars['summary'];

$handle = '@'.$club.'@'.Club_Template_Escape($config['base']);
$avatar = $row['avatar'] ?: $config['default']['avatar'];
$banner = $row['banner'] ?: $config['default']['banner'];

$max = Club_HTTP_Cursor($_GET['max'] ?? '');
$min = Club_HTTP_Cursor($_GET['min'] ?? '');
$asc = (bool)$min;
$where = ''; $params = [':cid' => $row['cid']];
if ($max) {
    $where = ' and a.timestamp <= :ts and (a.timestamp < :ts or a.id < :id)';
    $params[':ts'] = $max[0]; $params[':id'] = $max[1];
} elseif ($min) {
    $where = ' and a.timestamp >= :ts and (a.timestamp > :ts or a.id > :id)';
    $params[':ts'] = $min[0]; $params[':id'] = $min[1];
}
$pdo = $db->prepare('select a.id, a.timestamp, u.name, act.object, a.summary, a.content from `announces` as `a` left join `users` as `u` on a.uid = u.uid'.
    ' left join `activities` as `act` on a.activity = act.id where a.cid = :cid'.$where.' order by a.timestamp '.($asc ? 'asc' : 'desc').', a.id '.($asc ? 'asc' : 'desc').' limit 21');
$pdo->execute($params);
$activities = $pdo->fetchAll(PDO::FETCH_ASSOC);
$more = count($activities) > 20;
$activities = array_slice($activities, 0, 20);
if ($asc) $activities = array_reverse($activities);

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
