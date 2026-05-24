SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `submissions`;
DROP TABLE IF EXISTS `system_settings`;
DROP TABLE IF EXISTS `courses`;
DROP TABLE IF EXISTS `semesters`;
DROP TABLE IF EXISTS `users`;

SET FOREIGN_KEY_CHECKS = 1;

-- 1. ตารางผู้ใช้ (users)
CREATE TABLE `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(100) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `fullname` VARCHAR(255) NOT NULL,
  `role` ENUM('admin', 'teacher') NOT NULL DEFAULT 'teacher',
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  KEY `idx_users_role` (`role`),
  KEY `idx_users_status` (`status`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- 2. ตารางภาคเรียน (semesters)
CREATE TABLE `semesters` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `semester_name` VARCHAR(20) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_semesters_name` (`semester_name`),
  KEY `idx_semesters_is_active` (`is_active`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- 3. ตารางรายวิชา (courses)
CREATE TABLE `courses` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `course_code` VARCHAR(50) NOT NULL,
  `course_name` VARCHAR(255) NOT NULL,
  `teacher_id` INT UNSIGNED NOT NULL,
  `semester_id` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_courses_code_teacher_semester` (`course_code`, `teacher_id`, `semester_id`),
  KEY `idx_courses_teacher` (`teacher_id`),
  KEY `idx_courses_semester` (`semester_id`),
  CONSTRAINT `fk_courses_teacher`
    FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_courses_semester`
    FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- 4. ตารางกำหนดเวลาและเปิดระบบย่อย (system_settings)
CREATE TABLE `system_settings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `system_type` ENUM('course_syllabus', 'lesson_plan', 'teaching_materials') NOT NULL,
  `semester_id` INT UNSIGNED NOT NULL,
  `deadline_date` DATETIME NOT NULL,
  `is_open` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_system_settings_type_semester` (`system_type`, `semester_id`),
  KEY `idx_system_settings_semester` (`semester_id`),
  CONSTRAINT `fk_system_settings_semester`
    FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- 5. ตารางเก็บประวัติการส่งเอกสารของครู (submissions)
CREATE TABLE `submissions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `course_id` INT UNSIGNED NOT NULL,
  `system_type` ENUM('course_syllabus', 'lesson_plan', 'teaching_materials') NOT NULL,
  `file_path` VARCHAR(500) NULL,
  `drive_link` VARCHAR(500) NULL,
  `submitted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `submission_timing` ENUM('on_time', 'late') NOT NULL DEFAULT 'on_time',
  `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  `feedback` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_submissions_course` (`course_id`),
  KEY `idx_submissions_status` (`status`),
  KEY `idx_submissions_timing` (`submission_timing`),
  CONSTRAINT `fk_submissions_course`
    FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ── ข้อมูลตั้งต้นเพื่อใช้งานและทดสอบ (Seed Data) ───────────────────────

-- 1. เพิ่มผู้ใช้งานจำลอง (รหัสผ่านสำหรับทุกคนคือ: username + 123)
-- admin ➔ admin123
-- teacher01 ➔ teacher01123
-- teacher02 ➔ teacher02123
-- teacher03 ➔ teacher03123
INSERT INTO `users`
  (`id`, `username`, `password`, `fullname`, `role`, `status`)
VALUES
  (1, 'admin', '$2y$10$jGkOp5qOea7xdawY/Q4.POBqabGkOyEOOgq9JLNkyICVn5eekhMou', 'ผู้ดูแลระบบ งานวิชาการ', 'admin', 'active'),
  (2, 'teacher01', '$2y$10$T5l8/M31JDuGTbjRFaT2buhJJHHXsqX2c09OUM3tWIKIRY3qtRxsG', 'นายสมชาย ใจดี', 'teacher', 'active'),
  (3, 'teacher02', '$2y$10$dsjLWK6ouuk02AbNUc.yTO9fT9KTgqYNNJS46W8aCqwtV9yuEJTMO', 'นางสาวสุภาวดี รักเรียน', 'teacher', 'active'),
  (4, 'teacher03', '$2y$10$wRR9KAtTlKWK3Q/3ikXMjeZqtUxAFRdXO7HLlxBj8/uAyWwvV12mm', 'นายกิตติพงษ์ ช่างคิด', 'teacher', 'active');

-- 2. เพิ่มภาคเรียนจำลอง
INSERT INTO `semesters`
  (`id`, `semester_name`, `is_active`)
VALUES
  (1, '1/2569', 1),
  (2, '2/2569', 0);

-- 3. เพิ่มรายวิชาให้ครูแต่ละคนในภาคเรียน 1/2569
INSERT INTO `courses`
  (`id`, `course_code`, `course_name`, `teacher_id`, `semester_id`)
VALUES
  (1, '20001-1001', 'ภาษาไทยเพื่ออาชีพ', 2, 1),
  (2, '20001-2001', 'คอมพิวเตอร์และสารสนเทศเพื่องานอาชีพ', 3, 1),
  (3, '20100-1001', 'งานฝึกฝีมือ', 4, 1);

-- 4. ตั้งค่าเวลาส่งเดดไลน์เริ่มต้นให้ภาคเรียน 1/2569
INSERT INTO `system_settings`
  (`system_type`, `semester_id`, `deadline_date`, `is_open`)
VALUES
  ('course_syllabus', 1, '2026-06-15 23:59:59', 1),
  ('lesson_plan', 1, '2026-06-30 23:59:59', 1),
  ('teaching_materials', 1, '2026-07-15 23:59:59', 1);
