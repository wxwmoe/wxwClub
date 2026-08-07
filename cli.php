#!/usr/bin/env php
<?php if (PHP_SAPI != 'cli') exit("The program runs only in CLI mode!\n");

require(__DIR__.'/src/bootstrap.php');
require_once(APP_ROOT.'/src/worker.php');

$stop = false;
declare(ticks = 1);
pcntl_signal(SIGINT, 'shutdown');
pcntl_signal(SIGTERM, 'shutdown');

function shutdown() {
    global $stop; $stop = true;
    // master 和几个子进程都会各记一行，看的就是谁还没收到
    Club_Log_Console('info', 'shutdown requested, finishing current task', ['pid' => getmypid()]);
};

// 子进程的去向：正常退出记 code，被信号带走记 signal，9 多半是宽限期不够被强杀的。
// 补进程和关停收尾两处都要记，抄两遍迟早漏一处
function worker_reaped($slot, $pid, $status) {
    $signal = pcntl_wifsignaled($status);
    Club_Log_Console($signal ? 'warning' : 'info', 'worker exited', ['slot' => $slot, 'pid' => $pid,
        'status' => $signal ? 'signal '.pcntl_wtermsig($status) : 'code '.pcntl_wexitstatus($status)]);
}

// 一个 worker 的主循环。多进程模式下这就是子进程的全部工作，
// declare(ticks) 是文件作用域的，循环留在这个文件里信号才收得到
function worker_loop($maintain = true) {
    global $stop;
    while (!$stop) {
        try { worker($maintain); }
        // 只 echo 到 stdout 的话，进程被托管起来跑时这些报错等于没有
        catch (PDOException $e) {
            // 多进程抢同一张队列表，偶尔会撞出死锁，重来一次就好；连成片才是真出事了
            Club_Log_Console('error', 'database error', ['error' => $e->getMessage(), 'pid' => getmypid()]);
            sleep(1);
        }
    }
}

if (isset($argv[1])) switch ($argv[1]) {
    case 'worker':
        // 瓶颈是投递时等对端的那次 curl，慢实例能占住整个进程十秒。
        // 领队列是一条原子语句，多开几个进程就是几倍并发，队列逻辑一行都不用改。
        // 上限防手滑：每个进程占一条 mysql 连接，别把 max_connections 撑爆
        $jobs = max(1, min(32, (int)($argv[2] ?? 1)));
        // 停止时要靠它把 SIGTERM 转发给子进程（docker stop 只发给 master）。
        // 缺了的话直到关停那一刻才发作，子进程会变成谁也收不到通知的孤儿，先拦下来
        if ($jobs > 1 && !function_exists('posix_kill')) {
            Club_Log_Console('error', 'multi-process mode unavailable, ext-posix missing', ['jobs' => $jobs]);
            $jobs = 1;
        }
        if ($jobs == 1) {
            Club_Log_Console('info', 'worker started', ['pid' => getmypid()]);
            worker_loop();
            Club_Log_Console('info', 'worker stopped', ['pid' => getmypid()]); break;
        }
        // master 只管 fork 和收尸，先把 bootstrap 建的连接关掉：
        // 带着连接 fork 的话父子共享同一个 socket，谁先断开都会把别人的一起带走
        $db = null;
        Club_Log_Console('info', 'master started', ['jobs' => $jobs, 'pid' => getmypid()]);
        $children = [];     // pid => 槽位，槽位空出来就补一个回去
        while (!$stop) {
            $taken = array_flip($children);
            for ($slot = 0; $slot < $jobs && !$stop; $slot++) {
                if (isset($taken[$slot])) continue;
                if (($pid = pcntl_fork()) < 0) {
                    Club_Log_Console('error', 'fork failed, will retry', ['slot' => $slot]); sleep(1); break;
                }
                if ($pid === 0) {
                    // 子进程：继承来的名单是父进程的，留着会拿它去 kill 别人的兄弟
                    $children = []; Club_DB_Connect();
                    worker_loop($slot === 0); exit(0);
                }
                $children[$pid] = $slot; $taken[$slot] = $pid;
                // 0 号还兼管 rotate、过期清理，记下槽位才知道刚重启的是不是它
                Club_Log_Console('info', 'worker started', ['slot' => $slot, 'pid' => $pid]);
            }
            // 不用阻塞版的 wait：SIGTERM 在它进去之后才到的话，得等到有子进程
            // 退出才醒得过来，而 docker stop 只给 10 秒。sleep 会被信号打断
            if (($pid = pcntl_wait($status, WNOHANG)) > 0) {
                // 内存超限自杀、或者崩溃，都在这里补回去，不用整个容器重启
                worker_reaped($children[$pid] ?? '?', $pid, $status);
                unset($children[$pid]);
            } elseif (!$stop) sleep(1);
        }
        if ($children) {
            Club_Log_Console('info', 'stopping workers', ['jobs' => count($children)]);
            foreach (array_keys($children) as $pid) posix_kill($pid, SIGTERM);
            // 子进程要跑完手上那条投递才退。宽限期不够被 SIGKILL 也不丢任务，
            // 那些行留在 inuse = 1 上，30 秒后由 0 号进程复位
            while ($children && ($pid = pcntl_wait($status)) > 0) {
                worker_reaped($children[$pid] ?? '?', $pid, $status);
                unset($children[$pid]);
            }
        }
        Club_Log_Console('info', 'master stopped', ['pid' => getmypid()]); break;
    default: echo 'Unknown parameters',"\n"; break;
}
