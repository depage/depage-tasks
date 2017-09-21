/*
    Tasks Table
    -----------------------------------

    @tablename _tasks
    @version 1.5.0-beta.1
*/
CREATE TABLE `_tasks` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL,
  `projectname` varchar(35) DEFAULT NULL,
  `status` varchar(255) DEFAULT NULL,
  `time_added` datetime NOT NULL,
  `time_started` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

/*
    @version 1.6.0
*/
ALTER TABLE `_tasks`
    CHANGE COLUMN `time_added` `timeAdded` datetime NOT NULL,
    CHANGE COLUMN `time_started` `timeStarted` datetime DEFAULT NULL,
    CHANGE COLUMN `projectname` `projectName` varchar(35) DEFAULT NULL,
    ADD COLUMN `timeScheduled` datetime DEFAULT NULL;
