-- 013_alter_audit_logs_add_context.sql
ALTER TABLE `audit_logs`
  ADD COLUMN `academic_year` VARCHAR(20) NULL,
  ADD COLUMN `semester` VARCHAR(20) NULL,
  ADD COLUMN `grading_period` VARCHAR(20) NULL,
  ADD COLUMN `request_id` INT UNSIGNED NULL,
  ADD COLUMN `teacher_id` INT UNSIGNED NULL,
  ADD COLUMN `subject_id` INT UNSIGNED NULL,
  ADD COLUMN `section_id` INT UNSIGNED NULL;

