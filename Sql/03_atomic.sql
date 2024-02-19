/*
    subtaskAtomic Table
    -----------------------------------

    @tablename _subtaskatomic
    @connection _subtasks
    @version 2.5.0
*/
CREATE TABLE `_subtaskatomic` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `subtaskId` int(11) unsigned NOT NULL,
  `params` longblob NOT NULL,
  `status` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `subtaskId` (`subtaskId`),
  CONSTRAINT `_subtaskatomic_ibfk_1` FOREIGN KEY (`subtaskId`) REFERENCES `_subtasks` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
