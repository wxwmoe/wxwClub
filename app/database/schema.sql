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
  UNIQUE KEY `name` (`name`),
  KEY `timestamp` (`timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `users` (
  `uid` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `actor` varchar(255) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `inbox` varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `public_key` text CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `shared_inbox` varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `timestamp` int NOT NULL,
  `refresh` int NOT NULL DEFAULT '0',
  `webfinger` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`uid`),
  UNIQUE KEY `actor` (`actor`),
  KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `activities` (
  `id` int NOT NULL AUTO_INCREMENT,
  `uid` int NOT NULL,
  `type` varchar(10) COLLATE utf8mb4_general_ci NOT NULL,
  `clubs` varchar(255) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `object` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `updated` int NOT NULL DEFAULT '0',
  `polled` int NOT NULL DEFAULT '0',
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
  KEY `uid` (`uid`),
  CONSTRAINT `followers_ibfk_4` FOREIGN KEY (`cid`) REFERENCES `clubs` (`cid`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `followers_ibfk_5` FOREIGN KEY (`uid`) REFERENCES `users` (`uid`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `announces` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cid` int NOT NULL,
  `uid` int NOT NULL,
  `activity` int NOT NULL,
  `summary` mediumtext COLLATE utf8mb4_general_ci,
  `content` mediumtext COLLATE utf8mb4_general_ci NOT NULL,
  `timestamp` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cid_activity` (`cid`,`activity`),
  KEY `activity` (`activity`),
  KEY `cid_timestamp` (`cid`,`timestamp`),
  KEY `uid_timestamp` (`uid`,`timestamp`),
  KEY `timestamp_cid` (`timestamp`,`cid`),
  CONSTRAINT `announces_ibfk_3` FOREIGN KEY (`cid`) REFERENCES `clubs` (`cid`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `announces_ibfk_5` FOREIGN KEY (`uid`) REFERENCES `users` (`uid`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `announces_ibfk_7` FOREIGN KEY (`activity`) REFERENCES `activities` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `tasks` (
  `tid` int NOT NULL AUTO_INCREMENT,
  `cid` int NOT NULL,
  `type` varchar(8) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `jsonld` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `timestamp` int NOT NULL,
  PRIMARY KEY (`tid`),
  KEY `cid` (`cid`),
  KEY `timestamp` (`timestamp`),
  CONSTRAINT `tasks_ibfk_2` FOREIGN KEY (`cid`) REFERENCES `clubs` (`cid`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `queues` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tid` int NOT NULL,
  `type` varchar(8) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL DEFAULT 'relay',
  `target` varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `due_at` int unsigned NOT NULL,
  `retries` tinyint unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `tid_target` (`tid`,`target`),
  KEY `target_type_due` (`target`,`type`,`due_at`,`id`),
  CONSTRAINT `queues_ibfk_2` FOREIGN KEY (`tid`) REFERENCES `tasks` (`tid`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `endpoints` (
  `url` varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `fails` smallint unsigned NOT NULL DEFAULT '0',
  `fail_since` int unsigned NOT NULL DEFAULT '0',
  `retry_at` int unsigned NOT NULL DEFAULT '0',
  `next_at` int unsigned DEFAULT NULL,
  `follow_at` int unsigned DEFAULT NULL,
  `notice_at` int unsigned DEFAULT NULL,
  `announce_at` int unsigned DEFAULT NULL,
  `relay_at` int unsigned DEFAULT NULL,
  `idle_since` int unsigned NOT NULL DEFAULT '0',
  `lease_until` int unsigned NOT NULL DEFAULT '0',
  `lease_token` binary(16) DEFAULT NULL,
  PRIMARY KEY (`url`),
  KEY `schedule` (`next_at`,`lease_until`),
  KEY `follow_schedule` (`follow_at`,`lease_until`),
  KEY `notice_schedule` (`notice_at`,`lease_until`),
  KEY `announce_schedule` (`announce_at`,`lease_until`),
  KEY `relay_schedule` (`relay_at`,`lease_until`),
  UNIQUE KEY `lease_token` (`lease_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `dns` (
  `host` varchar(255) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `ips` varchar(1024) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL DEFAULT '',
  `checked_at` int unsigned NOT NULL DEFAULT '0',
  `lock_until` int unsigned NOT NULL DEFAULT '0',
  `lock_token` binary(16) DEFAULT NULL,
  PRIMARY KEY (`host`),
  KEY `checked_at` (`checked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `notices` (
  `id` int NOT NULL AUTO_INCREMENT,
  `uid` int NOT NULL,
  `type` varchar(20) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `note` varchar(255) CHARACTER SET ascii COLLATE ascii_general_ci DEFAULT NULL,
  `object` varchar(255) CHARACTER SET ascii COLLATE ascii_general_ci DEFAULT NULL,
  `timestamp` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `uid_type_timestamp` (`uid`,`type`,`timestamp`),
  KEY `object` (`object`),
  KEY `timestamp` (`timestamp`),
  CONSTRAINT `notices_ibfk_1` FOREIGN KEY (`uid`) REFERENCES `users` (`uid`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `blacklist` (
  `target` varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at` int unsigned NOT NULL,
  `check_at` int unsigned NOT NULL,
  `checks` smallint unsigned NOT NULL DEFAULT '0',
  `restore_pending_at` int unsigned DEFAULT NULL,
  `lease_until` int unsigned NOT NULL DEFAULT '0',
  `lease_token` binary(16) DEFAULT NULL,
  PRIMARY KEY (`target`),
  UNIQUE KEY `lease_token` (`lease_token`),
  KEY `schedule` (`restore_pending_at`,`check_at`,`lease_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `bans` (
  `target` varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `type` varchar(8) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `clubs` varchar(1024) CHARACTER SET ascii COLLATE ascii_general_ci DEFAULT NULL,
  `reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `timestamp` int NOT NULL,
  PRIMARY KEY (`target`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `meta` (
  `name` varchar(30) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `value` varchar(255) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 结构版本。与代码里的 DB_VERSION 不相等时 web 全挡；只允许 worker 向前合并
INSERT INTO `meta` (`name`, `value`) VALUES ('schema', '7');
