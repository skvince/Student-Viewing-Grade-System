-- 008_nullable_student_teacher_codes.sql
USE `student_viewing`;
ALTER TABLE `students`
  MODIFY COLUMN `student_id` VARCHAR(50) NULL UNIQUE;
ALTER TABLE `teachers`
  MODIFY COLUMN `teacher_id` VARCHAR(50) NULL UNIQUE;
