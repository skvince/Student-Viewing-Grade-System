-- 007_alter_teachers_add_department.sql
USE `student_viewing`;
ALTER TABLE `teachers` 
  ADD COLUMN `department` VARCHAR(255) DEFAULT NULL AFTER `email`;
