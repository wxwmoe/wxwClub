<?php require('function.php');

function worker() {
    global $db;
    $pdo = $db->prepare('update `queues` set `id` = last_insert_id(id), `inuse` = 1 where `inuse` = 0 and `timestamp` <= ? order by `timestamp` asc limit 1;');
    $pdo->execute([time()]);
    $pdo = $db->query('select q.id, c.name as club, t.type, t.jsonld, q.target, q.retry from `queues` as `q` left join `tasks` as `t` on q.tid = t.tid left join `clubs` as `c` on t.cid = c.cid where `id` = last_insert_id() and row_count() <> 0');
    if ($task = $pdo->fetch(PDO::FETCH_ASSOC)) {
        switch ($task['type']) {
            case 'push':
                if (ActivityPub_POST($task['target'], $task['club'], $task['jsonld'])) {
                    $pdo = $db->prepare('delete from `queues` where `id` = :id');
                    $pdo->execute([':id' => $task['id']]);
                } else {
                    $retry = $task['retry'] + 1;
                    if ($retry <= 3) $timestamp = time() + 60;
                    elseif ($retry <= 5) $timestamp = time() + 300;
                    elseif ($retry <= 10) $timestamp = time() + 600;
                    else $timestamp = time() + 3600;
                    if ($retry = 128) {
                        $pdo = $db->prepare('delete from `queues` where `id` = :id');
                        $pdo->execute([':id' => $task['id']]);
                    } else {
                        $pdo = $db->prepare('update `queues` set `inuse` = 0, `retry` = :retry, `timestamp` = :timestamp where `id` = :id');
                        $pdo->execute([':id' => $task['id'], ':retry' => $retry, ':timestamp' => $timestamp]);
                    }
                } break;
            default: break;
        }
    } else {
        $pdo = $db->query('select t.tid from `tasks` as `t` where (select count(q.id) from `queues` as `q` where t.tid = q.tid) = 0 limit 1');
        if ($task = $pdo->fetch(PDO::FETCH_COLUMN, 0)) {
            $pdo = $db->prepare('delete from `tasks` where `tid` = :tid');
            $pdo->execute([':tid' => $task]);
        } else sleep(1);
    }
}
