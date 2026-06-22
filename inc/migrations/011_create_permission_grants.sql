  -- 011_create_permission_grants.sql
CREATE TABLE IF NOT EXISTS `permission_grants` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `teacher_id` INT UNSIGNED NOT NULL,
  `school_year` VARCHAR(20) NOT NULL,
  `semester` VARCHAR(20) NOT NULL,
  `grading_period` VARCHAR(20) NOT NULL,
  `expires_at` DATETIME NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `granted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `revoked_at` DATETIME NULL,

  PRIMARY KEY (`id`),
  INDEX (`teacher_id`),
  INDEX (`school_year`, `semester`, `grading_period`),

  CONSTRAINT `fk_permission_grants_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


