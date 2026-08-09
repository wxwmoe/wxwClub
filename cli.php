#!/usr/bin/env php
<?php if (PHP_SAPI != 'cli') exit("The program runs only in CLI mode!\n");

require(__DIR__.'/src/bootstrap.php');
require_once(APP_ROOT.'/src/worker.php');

$stop = false;
declare(ticks = 1);

function shutdown() {
    global $stop; $stop = true;
    // master 和几个子进程都会各记一行，看的就是谁还没收到
    Club_Log_Console('info', 'shutdown requested, finishing current task', ['pid' => getmypid()]);
};

// 子进程的去向：正常退出记 code，被信号带走记 signal，9 多半是宽限期不够被强杀的。补进程和关停收尾两处都要记，抄两遍迟早漏一处
function worker_reaped($slot, $pid, $status) {
    global $slots;
    if (pcntl_wifsignaled($status)) {
        $level = 'warning'; $result = 'signal '.pcntl_wtermsig($status);
    } elseif (pcntl_wifexited($status)) {
        $code = pcntl_wexitstatus($status);
        $level = $code ? 'error' : 'info'; $result = 'code '.$code;
    } else {
        $level = 'warning'; $result = 'unknown';
    }
    Club_Log_Console($level, 'worker exited', ['slot' => $slot, 'type' => $slots[$slot] ?? '?', 'pid' => $pid, 'status' => $result]);
}

// 一个 worker 的主循环。多进程模式下这就是子进程的全部工作，declare(ticks) 是文件作用域的，循环留在这个文件里信号才收得到
function worker_loop($type) {
    global $stop, $db;
    // 在第一条任务开始前打开统计窗口；否则首条慢请求刚结束就停机时，force flush 只能看到一秒窗口，busy_ratio 和吞吐率都会被夸大。
    Club_Stat_Flush();
    while (!$stop) {
        try { worker($type); }
        // 只 echo 到 stdout 的话，进程被托管起来跑时这些报错等于没有
        catch (PDOException $e) {
            // 多进程抢同一张队列表，偶尔会撞出死锁，重来一次就好；连成片才是真出事了
            Club_Log_Console('error', 'database error', ['error' => $e->getMessage(), 'pid' => getmypid()]);
            Club_Stat('db_retries');
            // 连接断了就重连。长期进程握着一个失效的 PDO 只会每秒重复报同一行错，而队列那边看起来只是没人在投递
            try { if ($db) $db->query('select 1'); else Club_DB_Connect(); }
            catch (PDOException $lost) {
                try {
                    Club_DB_Connect();
                    Club_Log_Console('info', 'database reconnected', ['pid' => getmypid()]);
                } catch (PDOException $down) {
                    Club_Log_Console('error', 'database reconnect failed', ['error' => $down->getMessage(), 'pid' => getmypid()]);
                }
            }
            sleep(1);
        }
    }
    // 退出前把这一窗口攒下的计数写出去，不然最后一段时间等于没有记录
    Club_Stat_Flush(true);
}

if (!isset($argv[1])) {
    fwrite(STDERR, "Usage: php cli.php worker|migrate\n");
    exit(1);
}

switch ($argv[1]) {
    case 'worker':
        // 库里的结构与这份代码不一致时，先进入结构合并闸门。合并期间 web 那边整个入口是 503，这里也不能起队列进程 —— 半新半旧的结构下投递会写出对不上的行。
        // 合并完直接退出，让容器按 restart 策略把正常的队列进程带起来
        try { $version = Club_DB_Version(); }
        catch (Throwable $e) {
            Club_Log_Console('error', 'database schema check failed', ['error' => $e->getMessage(), 'pid' => getmypid()]);
            exit(1);
        }
        if ($version !== DB_VERSION) {
            require_once(APP_ROOT.'/src/migrate.php');
            // 合并中途崩了没有事务能回滚，但每一步都会先问一次 information_schema，重启后接着跑不会把已经合并好的部分改坏。这里只负责把原因记下来
            try { $version = Club_Migrate_Run(); }
            catch (Throwable $e) {
                Club_Log_Console('error', 'database merge failed', ['error' => $e->getMessage(), 'pid' => getmypid()]);
                exit(1);
            }
            if ($version !== DB_VERSION) {
                Club_Log_Console('error', 'database schema mismatch, not starting workers', ['schema' => $version, 'expected' => DB_VERSION]);
                exit(1);
            }
            Club_Log_Console('info', 'database merged, exiting for restart', ['schema' => $version]);
            break;
        }
        // 维护是全站一份的活，多开只是把同样的事做 N 遍，所以固定一个、不作配置。投递和探活都靠 token 租约互斥，加进程就是加并发，队列逻辑一行都不用改
        $slots = ['maintain.0' => 'maintain'];
        foreach (['delivery' => 8, 'probe' => 1] as $type => $fallback) for ($i = 0, $n = (int)($config['worker'][$type] ?? $fallback); $i < $n; $i++) $slots[$type.'.'.$i] = $type;
        if (!in_array('delivery', $slots, true)) {
            Club_Log_Console('error', 'worker needs at least one delivery process, nothing would ever be sent', ['worker' => $config['worker'] ?? []]);
            exit(1);
        }
        // 进了黑名单的对端只有探活能把它捞回来，一个都不开就是永久拉黑
        if (!in_array('probe', $slots, true))
            Club_Log_Console('warning', 'no probe process configured,'.
                ' blacklisted endpoints will never be restored');
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_fork')
            || !function_exists('pcntl_wait')) {
            Club_Log_Console('error', 'worker unavailable, ext-pcntl missing');
            exit(1);
        }
        // 停止时要靠它把 SIGTERM 转发给子进程（docker stop 只发给 master）。缺了的话直到关停那一刻才发作，子进程会变成谁也收不到通知的孤儿，先拦下来
        if (!function_exists('posix_kill')) {
            Club_Log_Console('error', 'worker unavailable, ext-posix missing');
            exit(1);
        }
        pcntl_signal(SIGINT, 'shutdown');
        pcntl_signal(SIGTERM, 'shutdown');
        // master 只管 fork 和收尸，先把 bootstrap 建的连接关掉：带着连接 fork 的话父子共享同一个 socket，谁先断开都会把别人的一起带走
        $db = null;
        Club_Log_Console('info', 'master started', ['slots' => array_count_values($slots), 'pid' => getmypid()]);
        $children = [];     // pid => 槽位，槽位空出来就补一个回去
        while (!$stop) {
            $taken = array_flip($children);
            foreach ($slots as $slot => $type) {
                if ($stop) break;
                if (isset($taken[$slot])) continue;
                if (($pid = pcntl_fork()) < 0) {
                    Club_Log_Console('error', 'fork failed, will retry', ['slot' => $slot]); sleep(1); break;
                }
                if ($pid === 0) {
                    // 子进程：继承来的名单是父进程的，留着会拿它去 kill 别人的兄弟
                    $children = []; Club_DB_Connect();
                    // 类型加序号：pid 每次重启都变，按这个名字才对得上是哪个位置
                    Club_Worker_Slot($slot);
                    worker_loop($type); exit(0);
                }
                $children[$pid] = $slot; $taken[$slot] = $pid;
                Club_Log_Console('info', 'worker started', ['slot' => $slot, 'type' => $type, 'pid' => $pid]);
            }
            // 不用阻塞版的 wait：SIGTERM 在它进去之后才到的话，得等到有子进程退出才醒得过来，而 docker stop 只给 10 秒。sleep 会被信号打断
            if (($pid = pcntl_wait($status, WNOHANG)) > 0) {
                // 内存超限自杀、或者崩溃，都在这里补回去，不用整个容器重启
                worker_reaped($children[$pid] ?? '?', $pid, $status);
                unset($children[$pid]);
            } elseif (!$stop) sleep(1);
        }
        if ($children) {
            Club_Log_Console('info', 'stopping workers', ['jobs' => count($children)]);
            foreach (array_keys($children) as $pid) posix_kill($pid, SIGTERM);
            // 子进程要跑完手上那条投递才退。宽限期不够被 SIGKILL 也不丢任务，它握着的租约 120 秒后自然过期，别人重新领走就是；那之后旧进程就算复活也拿不到出网权，token 已经不是它的了
            while ($children && ($pid = pcntl_wait($status)) > 0) {
                worker_reaped($children[$pid] ?? '?', $pid, $status);
                unset($children[$pid]);
            }
        }
        Club_Log_Console('info', 'master stopped', ['pid' => getmypid()]); break;
    // 手动合并。worker 启动时会自己判断并合并，这条只是给不方便重启容器的场合留的口子
    case 'migrate':
        require_once(APP_ROOT.'/src/migrate.php');
        try { $version = Club_Migrate_Run(); }
        catch (Throwable $e) {
            Club_Log_Console('error', 'database merge failed', ['error' => $e->getMessage(), 'pid' => getmypid()]);
            exit(1);
        }
        if ($version !== DB_VERSION) {
            Club_Log_Console('error', 'database schema mismatch after merge', ['schema' => $version, 'expected' => DB_VERSION]);
            exit(1);
        }
        exit(0);
    default:
        fwrite(STDERR, 'Unknown parameter: '.$argv[1]."\n");
        exit(1);
}
