DROP TABLE IF EXISTS `exf_epson_print_pool`;
CREATE TABLE `exf_epson_print_pool` (
  `oid` binary(16) NOT NULL,
  `print_job_id` varchar(50) NULL,
  `device_id` varchar(20) NOT NULL,
  `content` text NOT NULL,
  `state_id` int NOT NULL,
  `created_on` datetime NOT NULL,
  `modified_on` datetime NOT NULL,
  `created_by_user_oid` binary(16) DEFAULT NULL,
  `modified_by_user_oid` binary(16) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
ALTER TABLE `exf_epson_print_pool` ADD PRIMARY KEY(`oid`);