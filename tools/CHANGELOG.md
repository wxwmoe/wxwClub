#### 2026-08-08

```sql
ALTER TABLE `hosts` ADD `noticed` int NOT NULL DEFAULT '0' AFTER `since`;
```

#### 2026-08-07

```sql
CREATE TABLE `hosts` (
  `host` varchar(255) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `ips` varchar(1024) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL DEFAULT '',
  `resolved` int NOT NULL DEFAULT '0',
  `probe` int NOT NULL DEFAULT '0',
  `fails` smallint NOT NULL DEFAULT '0',
  `since` int NOT NULL DEFAULT '0',
  `until` int NOT NULL DEFAULT '0',
  `timestamp` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`host`),
  KEY `until` (`until`),
  KEY `timestamp` (`timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `queues` ADD `host` varchar(255) CHARACTER SET ascii COLLATE ascii_general_ci GENERATED ALWAYS AS (
  if(substring_index(substring_index(`target`, '/', 3), '/', -1) like '[%',
     substring_index(substring_index(substring_index(substring_index(`target`, '/', 3), '/', -1), ']', 1), '[', -1),
     substring_index(substring_index(substring_index(`target`, '/', 3), '/', -1), ':', 1))
) VIRTUAL;
ALTER TABLE `queues` ADD KEY `host` (`host`);
ALTER TABLE `queues` DROP KEY `pending`, ADD KEY `pending` (`inuse`,`timestamp`);
UPDATE `queues` SET `retry` = 0;
```

#### 2026-08-06

```sql
ALTER TABLE `activities` ADD `updated` int NOT NULL DEFAULT '0' AFTER `object`;
ALTER TABLE `tasks` MODIFY `jsonld` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL;
ALTER TABLE `announces` MODIFY `summary` mediumtext COLLATE utf8mb4_general_ci;
ALTER TABLE `announces` MODIFY `content` mediumtext COLLATE utf8mb4_general_ci NOT NULL;
```

#### 2026-08-05

```sql
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

ALTER TABLE `users` ADD `refresh` int NOT NULL DEFAULT '0';
ALTER TABLE `users` DROP KEY `name`, ADD KEY `name` (`name`);
ALTER TABLE `queues` ADD KEY `pending` (`inuse`,`retry`,`timestamp`);
ALTER TABLE `queues` DROP KEY `inuse`, DROP KEY `retry`, DROP KEY `timestamp`;
ALTER TABLE `announces` ADD KEY `cid_timestamp` (`cid`,`timestamp`);
ALTER TABLE `announces` ADD KEY `uid_timestamp` (`uid`,`timestamp`);
ALTER TABLE `announces` DROP KEY `uid`;
ALTER TABLE `announces` ADD KEY `timestamp_cid` (`timestamp`,`cid`);
ALTER TABLE `announces` DROP KEY `timestamp`;
ALTER TABLE `clubs` ADD KEY `timestamp` (`timestamp`);
ALTER TABLE `tasks` ADD KEY `queues_timestamp` (`queues`,`timestamp`);
ALTER TABLE `tasks` DROP KEY `queues`, DROP KEY `type`;
ALTER TABLE `blacklist` ADD KEY `pending` (`inuse`,`timestamp`);
ALTER TABLE `blacklist` DROP KEY `inuse`, DROP KEY `timestamp`;
ALTER TABLE `followers` DROP KEY `cid`;
```
