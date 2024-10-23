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

-- Migration: 20241024

CREATE TABLE `users_blocks` (
  `id` int NOT NULL AUTO_INCREMENT,
  `club_id` int DEFAULT NULL,
  `target` varchar(255) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `created_at` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `club_target` (`club_id`, `target`),
  KEY `club_id` (`club_id`),
  CONSTRAINT `users_blocks_ibfk_1` FOREIGN KEY (`club_id`) REFERENCES `clubs` (`cid`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `instances_blocks` (
  `id` int NOT NULL AUTO_INCREMENT,
  `club_id` int DEFAULT NULL,
  `target` varchar(255) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `created_at` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `club_target` (`club_id`, `target`),
  KEY `club_id` (`club_id`),
  CONSTRAINT `instances_blocks_ibfk_1` FOREIGN KEY (`club_id`) REFERENCES `clubs` (`cid`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
