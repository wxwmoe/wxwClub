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
  `inbox` varchar(255) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `public_key` text CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `shared_inbox` varchar(255) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `timestamp` int NOT NULL,
  `refresh` int NOT NULL DEFAULT '0',
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
  `type` varchar(10) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `jsonld` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `queues` int NOT NULL DEFAULT '0',
  `timestamp` int NOT NULL,
  PRIMARY KEY (`tid`),
  KEY `cid` (`cid`),
  KEY `queues_timestamp` (`queues`,`timestamp`),
  CONSTRAINT `tasks_ibfk_2` FOREIGN KEY (`cid`) REFERENCES `clubs` (`cid`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `queues` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tid` int NOT NULL,
  `target` varchar(255) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `timestamp` int NOT NULL,
  `inuse` tinyint NOT NULL DEFAULT '0',
  `retry` tinyint NOT NULL DEFAULT '0',
  `host` varchar(255) CHARACTER SET ascii COLLATE ascii_general_ci GENERATED ALWAYS AS (
    if(substring_index(substring_index(`target`, '/', 3), '/', -1) like '[%',
       substring_index(substring_index(substring_index(substring_index(`target`, '/', 3), '/', -1), ']', 1), '[', -1),
       substring_index(substring_index(substring_index(`target`, '/', 3), '/', -1), ':', 1))
  ) VIRTUAL,
  PRIMARY KEY (`id`),
  KEY `tid` (`tid`),
  KEY `pending` (`inuse`,`timestamp`),
  KEY `host` (`host`),
  CONSTRAINT `queues_ibfk_2` FOREIGN KEY (`tid`) REFERENCES `tasks` (`tid`) ON DELETE CASCADE ON UPDATE CASCADE
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
  `id` int NOT NULL AUTO_INCREMENT,
  `target` varchar(255) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `create` int DEFAULT NULL,
  `timestamp` int NOT NULL DEFAULT '0',
  `inuse` tinyint NOT NULL DEFAULT '0',
  `retry` smallint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `target` (`target`),
  KEY `pending` (`inuse`,`timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `hosts` (
  `host` varchar(255) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `ips` varchar(1024) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL DEFAULT '',
  `resolved` int NOT NULL DEFAULT '0',
  `probe` int NOT NULL DEFAULT '0',
  `fails` smallint NOT NULL DEFAULT '0',
  `since` int NOT NULL DEFAULT '0',
  `noticed` int NOT NULL DEFAULT '0',
  `until` int NOT NULL DEFAULT '0',
  `timestamp` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`host`),
  KEY `until` (`until`),
  KEY `timestamp` (`timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;