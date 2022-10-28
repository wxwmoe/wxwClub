<?php require('function.php');

function worker() {
    global $db, $cycle; $idle = 0;
    $pdo = $db->prepare('update `queues` set `id` = last_insert_id(id), `inuse` = 1 where `inuse` = 0 and `timestamp` <= ? order by `retry`, `timestamp` asc limit 1');
    $pdo->execute([time()]);
    $pdo = $db->query('select q.id, c.name as club, t.tid, t.type, t.jsonld, q.target, q.retry from `queues` as `q` left join `tasks` as `t` on q.tid = t.tid left join `clubs` as `c` on t.cid = c.cid where `id` = last_insert_id() and row_count() <> 0');
    if ($task = $pdo->fetch(PDO::FETCH_ASSOC)) {
        switch ($task['type']) {
            case 'push':
                $pdo = $db->prepare('select count(*) from `blacklist` where `target` = :target');
                $pdo->execute([':target' => $task['target']]);
                if ($pdo->fetch(PDO::FETCH_COLUMN, 0)) {
                    $pdo = $db->prepare('delete from `queues` where `id` = :id');
                    $pdo->execute([':id' => $task['id']]);
                    $pdo = $db->prepare('update `tasks` set `queues` = `queues` - 1 where `tid` = :tid');
                    $pdo->execute([':tid' => $task['tid']]);
                } else {
                    if (ActivityPub_POST($task['target'], $task['club'], $task['jsonld'])) {
                        $pdo = $db->prepare('delete from `queues` where `id` = :id');
                        $pdo->execute([':id' => $task['id']]);
                        $pdo = $db->prepare('update `tasks` set `queues` = `queues` - 1 where `tid` = :tid');
                        $pdo->execute([':tid' => $task['tid']]);
                    } else {
                        $retry = $task['retry'] + 1;
                        if ($retry <= 3) $timestamp = time() + 60;
                        elseif ($retry <= 5) $timestamp = time() + 300;
                        elseif ($retry <= 10) $timestamp = time() + 600;
                        elseif ($retry <= 100) $timestamp = time() + 3600;
                        else $timestamp = time() + 86400;
                        if ($retry == 127) {
                            $pdo = $db->prepare('insert ignore into `blacklist`(`target`,`timestamp`) values (:target,:timestamp);');
                            $pdo->execute([':target' => $task['target'], ':timestamp' => time()]);
                            $pdo = $db->prepare('delete from `queues` where `id` = :id');
                            $pdo->execute([':id' => $task['id']]);
                            $pdo = $db->prepare('update `tasks` set `queues` = `queues` - 1 where `tid` = :tid');
                            $pdo->execute([':tid' => $task['tid']]);
                        } else {
                            $pdo = $db->prepare('update `queues` set `inuse` = 0, `retry` = :retry, `timestamp` = :timestamp where `id` = :id');
                            $pdo->execute([':id' => $task['id'], ':retry' => $retry, ':timestamp' => $timestamp]);
                        }
                    }
                } break;
            default: break;
        } $cycle++;
    } else $idle = 1;
    if ($idle || $cycle > 9) {
        $pdo = $db->query('select `tid` from `tasks` where `queues` < 1 limit 1');
        if ($task = $pdo->fetch(PDO::FETCH_COLUMN, 0)) {
            $pdo = $db->prepare('delete from `tasks` where `tid` = :tid');
            $pdo->execute([':tid' => $task]);
        } else {
            $pdo = $db->prepare('select `id` from `queues` where `inuse` = 1 and `timestamp` <= :timestamp order by `timestamp` asc limit 1');
            $pdo->execute([':timestamp' => time() - 600]);
            if ($id = $pdo->fetch(PDO::FETCH_COLUMN, 0)) {
                $pdo = $db->prepare('update `queues` set `inuse` = 0 where `id` = :id');
                $pdo->execute([':id' => $id]);
            } elseif ($idle) sleep(1);
        } $cycle = 0;
    }
    if (memory_get_usage(1) > 10 * 1024 * 1024) {
        global $stop; $stop = true;
        echo date('[Y-m-d H:i:s]').' Memory limit exceeded, stopping ...',"\n";
    }
}
