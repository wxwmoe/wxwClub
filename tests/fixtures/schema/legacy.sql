-- 合并链的起点：meta 表出现之前的那套结构，Club_DB_Version() 把它读成版本 0。
-- 这不是「空库」—— 全新安装走的是 tools/wxwclub.sql，从不合并。src/migrate/1.php 只新建 announces/tasks/queues/blacklist/notices，
-- 下面这四张核心表是它假定已经在的，所以想验合并链就得有这么一份旧库。
--
-- 每一处「旧写法」都对应 1.php 里一段真的会跑起来的改写：datetime 的 create_time、拆成两列的 infoname、activities 的 cid 和 activity_id、
-- 放不下 URL 的 varchar(100)、还没挪到 actor 上的唯一键。带几行数据是为了让那几段回填真的搬一次东西，空表跑过去等于没验。

CREATE TABLE `clubs` (
  `cid` int NOT NULL AUTO_INCREMENT,
  `name` varchar(30) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `nickname` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `infoname_cn` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `infoname_en` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `summary` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `avatar` varchar(100) CHARACTER SET ascii COLLATE ascii_general_ci DEFAULT NULL,
  `banner` varchar(100) CHARACTER SET ascii COLLATE ascii_general_ci DEFAULT NULL,
  `public_key` text CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `private_key` text CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `create_time` datetime NOT NULL,
  PRIMARY KEY (`cid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `users` (
  `uid` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `actor` varchar(100) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `inbox` varchar(100) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `public_key` text CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `shared_inbox` varchar(100) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `create_time` datetime NOT NULL,
  PRIMARY KEY (`uid`),
  KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `activities` (
  `id` int NOT NULL AUTO_INCREMENT,
  `uid` int NOT NULL,
  `cid` int NOT NULL,
  `type` varchar(10) COLLATE utf8mb4_general_ci NOT NULL,
  `activity_id` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `object` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `create_time` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `uid` (`uid`),
  KEY `cid` (`cid`),
  CONSTRAINT `activities_ibfk_1` FOREIGN KEY (`uid`) REFERENCES `users` (`uid`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `activities_ibfk_2` FOREIGN KEY (`cid`) REFERENCES `clubs` (`cid`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `followers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cid` int NOT NULL,
  `uid` int NOT NULL,
  `create_time` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `cid` (`cid`),
  KEY `uid` (`uid`),
  CONSTRAINT `followers_ibfk_1` FOREIGN KEY (`cid`) REFERENCES `clubs` (`cid`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `followers_ibfk_2` FOREIGN KEY (`uid`) REFERENCES `users` (`uid`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `clubs` (`cid`, `name`, `nickname`, `infoname_cn`, `infoname_en`, `summary`, `avatar`, `banner`, `public_key`, `private_key`, `create_time`) VALUES
  (1, 'test', 'test group', '测试', 'test', '<p>legacy</p>', 'https://local.example/a.png', 'https://local.example/b.png', 'legacy-public', 'legacy-private', '2023-01-02 03:04:05');

INSERT INTO `users` (`uid`, `name`, `actor`, `inbox`, `public_key`, `shared_inbox`, `create_time`) VALUES
  (1, 'alice@remote.example', 'https://remote.example/users/alice', 'https://remote.example/users/alice/inbox', 'legacy-public', 'https://remote.example/inbox', '2023-01-02 03:04:05');

INSERT INTO `activities` (`id`, `uid`, `cid`, `type`, `activity_id`, `object`, `create_time`) VALUES
  (1, 1, 1, 'Create', '1', 'https://remote.example/users/alice/statuses/1', '2023-01-02 03:04:05');

INSERT INTO `followers` (`id`, `cid`, `uid`, `create_time`) VALUES
  (1, 1, 1, '2023-01-02 03:04:05');
