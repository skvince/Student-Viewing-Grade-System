-- 005_create_assignments.sql
CREATE TABLE IF NOT EXISTS `assignments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `teacher_id` INT UNSIGNED NOT NULL,
  `section_id` INT UNSIGNED NOT NULL,
  `module` VARCHAR(255) NOT NULL,
  `school_year` VARCHAR(20) DEFAULT NULL,
  `semester` VARCHAR(20) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_assignments_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_assignments_section` FOREIGN KEY (`section_id`) REFERENCES `sections`(`id`) ON DELETE CASCADE,
  INDEX (`teacher_id`),
  INDEX (`section_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
