-- 合并链的起点之一：初始提交 13a8931（2021-10-25）的 tools/wxwclub.sql，逐字节照抄。
-- 自动合并是后来才有的，那之前线上结构就以各次提交的这份快照为准，没有额外手工 ALTER 可推测，所以历史快照就是唯一的事实来源。
--
-- 这一版里 src/migrate/1.php 要改的东西最多：表名还叫 activitys、infoname 拆成两列、activities 挂着 cid 和 activity_id、
-- create_time 是 datetime、唯一键还在 users.name 上、object 只是普通索引。末尾几行数据是为了让那些回填真的搬一次东西。

CREATE TABLE `clubs` (
  `cid` int NOT NULL AUTO_INCREMENT,
  `name` varchar(30) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `nickname` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `infoname_cn` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `infoname_en` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `summary` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `avatar` varchar(100) CHARACTER SET ascii COLLATE ascii_general_ci DEFAULT NULL,
  `banner` varchar(100) CHARACTER SET ascii COLLATE ascii_general_ci DEFAULT NULL,
  `public_key` text CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `private_key` text CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`cid`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `users` (
  `uid` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `actor` varchar(100) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `inbox` varchar(100) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `public_key` text CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `shared_inbox` varchar(100) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`uid`),
  UNIQUE KEY `name` (`name`),
  KEY `actor` (`actor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `activitys` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cid` int NOT NULL,
  `uid` int NOT NULL,
  `type` varchar(10) COLLATE utf8mb4_general_ci NOT NULL,
  `activity_id` varchar(20) COLLATE utf8mb4_general_ci NOT NULL,
  `object` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `cid` (`cid`),
  KEY `uid` (`uid`),
  KEY `object` (`object`),
  CONSTRAINT `activitys_ibfk_4` FOREIGN KEY (`cid`) REFERENCES `clubs` (`cid`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `activitys_ibfk_5` FOREIGN KEY (`uid`) REFERENCES `users` (`uid`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `followers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cid` int NOT NULL,
  `uid` int NOT NULL,
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cid_uid` (`cid`,`uid`),
  KEY `cid` (`cid`),
  KEY `uid` (`uid`),
  CONSTRAINT `followers_ibfk_4` FOREIGN KEY (`cid`) REFERENCES `clubs` (`cid`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `followers_ibfk_5` FOREIGN KEY (`uid`) REFERENCES `users` (`uid`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `clubs` (`cid`, `name`, `nickname`, `infoname_cn`, `infoname_en`, `summary`, `avatar`, `banner`, `public_key`, `private_key`, `create_time`) VALUES
  (1, 'test', 'test group', '测试', 'test', '<p>legacy</p>', 'https://local.example/a.png', 'https://local.example/b.png', 'legacy-public', 'legacy-private', '2021-10-25 03:04:05');

INSERT INTO `users` (`uid`, `name`, `actor`, `inbox`, `public_key`, `shared_inbox`, `create_time`) VALUES
  (1, 'alice@remote.example', 'https://remote.example/users/alice', 'https://remote.example/users/alice/inbox', 'legacy-public', 'https://remote.example/inbox', '2021-10-25 03:04:05');

INSERT INTO `activitys` (`id`, `cid`, `uid`, `type`, `activity_id`, `object`, `create_time`) VALUES
  (1, 1, 1, 'Create', '1', 'https://remote.example/users/alice/statuses/1', '2021-10-25 03:04:05');

INSERT INTO `followers` (`id`, `cid`, `uid`, `create_time`) VALUES
  (1, 1, 1, '2021-10-25 03:04:05');
