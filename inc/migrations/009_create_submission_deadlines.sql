-- 009_create_submission_deadlines.sql
CREATE TABLE IF NOT EXISTS `submission_deadlines` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `school_year` VARCHAR(20) NOT NULL,
  `semester` VARCHAR(20) NOT NULL,
  `grading_period` VARCHAR(20) NOT NULL,
  `deadline` DATETIME NOT NULL,
  `status` ENUM('open','closed','extended') NOT NULL DEFAULT 'open',
  `extended_until` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_deadline` (`school_year`,`semester`,`grading_period`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

