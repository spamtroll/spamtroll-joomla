CREATE TABLE IF NOT EXISTS `#__spamtroll_log` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `created` DATETIME NOT NULL,
    `source` VARCHAR(32) NOT NULL,
    `status` VARCHAR(16) NOT NULL,
    `score` FLOAT NOT NULL DEFAULT 0,
    `content_hash` CHAR(64) NOT NULL DEFAULT '',
    `ip` VARCHAR(45) NOT NULL DEFAULT '',
    `email` VARCHAR(255) NOT NULL DEFAULT '',
    `username` VARCHAR(255) NOT NULL DEFAULT '',
    `symbols` TEXT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_spamtroll_log_created` (`created`),
    KEY `idx_spamtroll_log_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
