-- 010_create_grade_change_requests.sql
CREATE TABLE IF NOT EXISTS `grade_change_requests` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `teacher_id` INT UNSIGNED NOT NULL,
  `section_id` INT UNSIGNED NOT NULL,
  `subject_id` INT UNSIGNED NOT NULL,
  `school_year` VARCHAR(20) NOT NULL,
  `semester` VARCHAR(20) NOT NULL,
  `grading_period` VARCHAR(20) NOT NULL,
  `reason` TEXT NOT NULL,
  `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `admin_id` INT UNSIGNED NULL,
  `admin_response` TEXT NULL,
  `expires_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL,

  PRIMARY KEY (`id`),
  INDEX (`teacher_id`),
  INDEX (`section_id`),
  INDEX (`subject_id`),
  INDEX (`school_year`, `semester`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

