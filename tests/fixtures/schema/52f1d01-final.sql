-- 合并链的另一个起点：52f1d01（2022-10-29）的 tools/wxwclub.sql，逐字节照抄。这是自动合并出现之前最后一版完整旧库，
-- 线上真正从这里升上来的可能性最大 —— 13a8931 那份验的是 1.php 的回填，这一份验的是 2.php 的调度重构。
--
-- 跟终态的差别集中在调度那几张表：queues 还是 inuse/retry 那套、blacklist 带自增 id、tasks 挂着已经没人读的 queues 计数、
-- 四个 URL 列还在 ascii_general_ci 上、announces 是 text 而不是 mediumtext，endpoints 和 dns 根本还不存在。
-- 数据只覆盖结构改写，URL 规范化的边界（大小写、默认端口、重复目标）还没进这份夹具。

CREATE TABLE `clubs` (
  `cid` int NOT NULL AUTO_INCREMENT,
  `name` varchar(30) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `nickname` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `infoname` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `summary` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `avatar` varchar(255) CHARACTER SET ascii COLLATE ascii_general_ci DEFAULT NULL,
  `banner` varchar(255) CHARACTER SET ascii COLLATE ascii_general_ci DEFAULT NULL,
  `public_key` text CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `private_key` text CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `timestamp` int NOT NULL,
  PRIMARY KEY (`cid`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `users` (
  `uid` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `actor` varchar(255) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `inbox` varchar(255) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `public_key` text CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `shared_inbox` varchar(255) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `timestamp` int NOT NULL,
  PRIMARY KEY (`uid`),
  UNIQUE KEY `name` (`name`),
  UNIQUE KEY `actor` (`actor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `activities` (
  `id` int NOT NULL AUTO_INCREMENT,
  `uid` int NOT NULL,
  `type` varchar(10) COLLATE utf8mb4_general_ci NOT NULL,
  `clubs` varchar(255) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `object` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `timestamp` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `object` (`object`),
  KEY `uid` (`uid`),
  CONSTRAINT `activities_ibfk_5` FOREIGN KEY (`uid`) REFERENCES `users` (`uid`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `followers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cid` int NOT NULL,
  `uid` int NOT NULL,
  `timestamp` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cid_uid` (`cid`,`uid`),
  KEY `cid` (`cid`),
  KEY `uid` (`uid`),
  CONSTRAINT `followers_ibfk_4` FOREIGN KEY (`cid`) REFERENCES `clubs` (`cid`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `followers_ibfk_5` FOREIGN KEY (`uid`) REFERENCES `users` (`uid`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `announces` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cid` int NOT NULL,
  `uid` int NOT NULL,
  `activity` int NOT NULL,
  `summary` text COLLATE utf8mb4_general_ci,
  `content` text COLLATE utf8mb4_general_ci NOT NULL,
  `timestamp` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cid_activity` (`cid`,`activity`),
  KEY `uid` (`uid`),
  KEY `activity` (`activity`),
  KEY `timestamp` (`timestamp`),
  CONSTRAINT `announces_ibfk_3` FOREIGN KEY (`cid`) REFERENCES `clubs` (`cid`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `announces_ibfk_5` FOREIGN KEY (`uid`) REFERENCES `users` (`uid`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `announces_ibfk_7` FOREIGN KEY (`activity`) REFERENCES `activities` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `tasks` (
  `tid` int NOT NULL AUTO_INCREMENT,
  `cid` int NOT NULL,
  `type` varchar(10) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `jsonld` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `queues` int NOT NULL DEFAULT '0',
  `timestamp` int NOT NULL,
  PRIMARY KEY (`tid`),
  KEY `cid` (`cid`),
  KEY `type` (`type`),
  KEY `queues` (`queues`),
  CONSTRAINT `tasks_ibfk_2` FOREIGN KEY (`cid`) REFERENCES `clubs` (`cid`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `queues` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tid` int NOT NULL,
  `target` varchar(255) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `timestamp` int NOT NULL,
  `inuse` tinyint NOT NULL DEFAULT '0',
  `retry` tinyint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `tid` (`tid`),
  KEY `timestamp` (`timestamp`),
  KEY `inuse` (`inuse`),
  KEY `retry` (`retry`),
  CONSTRAINT `queues_ibfk_2` FOREIGN KEY (`tid`) REFERENCES `tasks` (`tid`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `blacklist` (
  `id` int NOT NULL AUTO_INCREMENT,
  `target` varchar(255) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `create` int DEFAULT NULL,
  `timestamp` int NOT NULL DEFAULT '0',
  `inuse` tinyint NOT NULL DEFAULT '0',
  `retry` smallint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `target` (`target`),
  KEY `timestamp` (`timestamp`),
  KEY `inuse` (`inuse`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
INSERT INTO `clubs` (`cid`, `name`, `nickname`, `infoname`, `summary`, `avatar`, `banner`, `public_key`, `private_key`, `timestamp`) VALUES
  (1, 'test', 'test group', '{":infoname_cn:":"测试",":infoname_en:":"test"}', '<p>legacy</p>', 'https://local.example/a.png', 'https://local.example/b.png', 'legacy-public', 'legacy-private', 1666972800);

INSERT INTO `users` (`uid`, `name`, `actor`, `inbox`, `public_key`, `shared_inbox`, `timestamp`) VALUES
  (1, 'alice@remote.example', 'https://remote.example/users/alice', 'https://remote.example/users/alice/inbox', 'legacy-public', 'https://remote.example/inbox', 1666972800);

INSERT INTO `activities` (`id`, `uid`, `type`, `clubs`, `object`, `timestamp`) VALUES
  (1, 1, 'Create', '["test"]', 'https://remote.example/users/alice/statuses/1', 1666972800);

INSERT INTO `followers` (`id`, `cid`, `uid`, `timestamp`) VALUES
  (1, 1, 1, 1666972800);

INSERT INTO `announces` (`id`, `cid`, `uid`, `activity`, `summary`, `content`, `timestamp`) VALUES
  (1, 1, 1, 1, NULL, 'legacy announce', 1666972800);

INSERT INTO `tasks` (`tid`, `cid`, `type`, `jsonld`, `queues`, `timestamp`) VALUES
  (1, 1, 'push', '{"type":"Announce"}', 1, 1666972800);

INSERT INTO `queues` (`id`, `tid`, `target`, `timestamp`, `inuse`, `retry`) VALUES
  (1, 1, 'https://remote.example/inbox', 1666972800, 0, 0);

INSERT INTO `blacklist` (`id`, `target`, `create`, `timestamp`, `inuse`, `retry`) VALUES
  (1, 'https://dead.example/inbox', 1666972800, 0, 0, 0);
