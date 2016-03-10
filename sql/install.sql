CREATE TABLE `civicrm_netbanx_receipt` (
  `trx_id` varchar(255) COLLATE utf8_unicode_ci NOT NULL COMMENT 'CiviCRM transaction ID',
  `timestamp` int(11) NOT NULL DEFAULT '0' COMMENT 'A Unix timestamp indicating when this receipt was created',
  `code` int(10) unsigned DEFAULT NULL COMMENT 'Transaction response code',
  `receipt` text CHARACTER SET utf8 COMMENT 'Full store receipt, including credit card transaction',
  `ip` varchar(255) CHARACTER SET utf8 NOT NULL DEFAULT '' COMMENT 'IP address of the donor',
  `first_name` varchar(64) CHARACTER SET utf8 DEFAULT NULL COMMENT 'Billing first name',
  `last_name` varchar(64) CHARACTER SET utf8 DEFAULT NULL COMMENT 'Billing last name',
  `card_type` varchar(32) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'Credit card type',
  `card_number` varchar(64) CHARACTER SET utf8 DEFAULT NULL COMMENT 'Partial credit card number',
  PRIMARY KEY (`trx_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci COMMENT='Logs all netbanx credit card receipts sent to users.';

CREATE TABLE `civicrm_netbanx_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Log ID',
  `trx_id` varchar(255) NOT NULL COMMENT 'CiviCRM transaction ID',
  `timestamp` int(11) NOT NULL DEFAULT '0' COMMENT 'A Unix timestamp indicating when this message was sent or received.',
  `type` varchar(32) DEFAULT NULL COMMENT 'Type of communication',
  `message` text COMMENT 'XML message sent or received',
  `ip` varchar(255) NOT NULL COMMENT 'IP of the visitor',
  `fail` tinyint(4) DEFAULT '0' COMMENT 'Set to 1 if the message was an error.',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=96918 DEFAULT CHARSET=utf8 COMMENT='Logs all communications with the payment gateway.';
