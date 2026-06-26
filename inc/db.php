<?php
/**
 * Database connection helper (MySQLi)
 * Update credentials below for your environment.
 */
function db_connect(): ?mysqli {
    $host = 'localhost';
    $user = 'root';
    $pass = '';
    $db   = 'student_viewing';
    mysqli_report(MYSQLI_REPORT_OFF);
    $conn = @new mysqli($host, $user, $pass, $db);
    if ($conn->connect_errno) {
        if ($conn->connect_errno === 1049) {
            $tmp = @new mysqli($host, $user, $pass);
            if ($tmp->connect_errno) {
                error_log('DB server connect error: ' . $tmp->connect_error);
                return null;
            }
            $create = "CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
            if (! $tmp->query($create)) {
                error_log('Create DB failed: ' . $tmp->error);
                $tmp->close();
                return null;
            }
            $tmp->close();
            $conn = @new mysqli($host, $user, $pass, $db);
            if ($conn->connect_errno) {
                error_log('DB connect error after create: ' . $conn->connect_error);
                return null;
            }
        } else {
            error_log('DB connect error: ' . $conn->connect_error);
            return null;
        }
    }
    $conn->set_charset('utf8mb4');
    db_initialize_schema($conn);
    return $conn;
}

function db_initialize_schema(mysqli $conn): void {
    $schemaStatements = [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS teachers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  teacher_id VARCHAR(32) DEFAULT NULL,
  first_name VARCHAR(100) NOT NULL,
  middle_name VARCHAR(100) DEFAULT NULL,
  last_name VARCHAR(100) NOT NULL,
  name VARCHAR(255) DEFAULT NULL,
  email VARCHAR(255) DEFAULT NULL,
  password_hash VARCHAR(255) DEFAULT NULL,
  password VARCHAR(255) DEFAULT NULL,
  department VARCHAR(128) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_teacher_id (teacher_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS students (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id VARCHAR(32) DEFAULT NULL,
  first_name VARCHAR(100) NOT NULL,
  middle_name VARCHAR(100) DEFAULT NULL,
  last_name VARCHAR(100) NOT NULL,
  name VARCHAR(255) DEFAULT NULL,
  email VARCHAR(255) DEFAULT NULL,
  password_hash VARCHAR(255) DEFAULT NULL,
  password VARCHAR(255) DEFAULT NULL,
  section_id INT UNSIGNED DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_student_id (student_id),
  INDEX idx_student_section (section_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS sections (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  section_code VARCHAR(64) DEFAULT NULL,
  name VARCHAR(255) NOT NULL,
  department VARCHAR(128) DEFAULT NULL,
  school_year VARCHAR(20) DEFAULT NULL,
  semester VARCHAR(20) DEFAULT NULL,
  teacher_id INT UNSIGNED DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_section_code (section_code),
  INDEX idx_section_teacher (teacher_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS subjects (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subject_code VARCHAR(64) NOT NULL,
  title VARCHAR(255) NOT NULL,
  school_year VARCHAR(20) DEFAULT NULL,
  semester VARCHAR(20) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_subject_code (subject_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS assignments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT UNSIGNED NOT NULL,
  section_id INT UNSIGNED NOT NULL,
  subject_id INT UNSIGNED NOT NULL,
  school_year VARCHAR(20) DEFAULT NULL,
  semester VARCHAR(20) DEFAULT NULL,
  module VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_assignment_teacher (teacher_id),
  INDEX idx_assignment_section (section_id),
  INDEX idx_assignment_subject (subject_id),
  CONSTRAINT fk_assignments_teacher FOREIGN KEY (teacher_id) REFERENCES teachers (id) ON DELETE RESTRICT,
  CONSTRAINT fk_assignments_section FOREIGN KEY (section_id) REFERENCES sections (id) ON DELETE RESTRICT,
  CONSTRAINT fk_assignments_subject FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS grades (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id INT UNSIGNED NOT NULL,
  subject_id INT UNSIGNED NOT NULL,
  teacher_id INT UNSIGNED NOT NULL,
  section_id INT UNSIGNED NOT NULL,
  school_year VARCHAR(20) NOT NULL,
  semester VARCHAR(20) NOT NULL,
  prelim DECIMAL(5,2) DEFAULT NULL,
  midterm DECIMAL(5,2) DEFAULT NULL,
  finals DECIMAL(5,2) DEFAULT NULL,
  average DECIMAL(5,2) DEFAULT NULL,
  gwa VARCHAR(10) DEFAULT NULL,
  remarks VARCHAR(20) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_grade_record (student_id, subject_id, school_year, semester),
  INDEX idx_grade_student (student_id),
  INDEX idx_grade_subject (subject_id),
  INDEX idx_grade_teacher (teacher_id),
  INDEX idx_grade_section (section_id),
  CONSTRAINT fk_grade_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE,
  CONSTRAINT fk_grade_subject FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE CASCADE,
  CONSTRAINT fk_grade_teacher FOREIGN KEY (teacher_id) REFERENCES teachers (id) ON DELETE RESTRICT,
  CONSTRAINT fk_grade_section FOREIGN KEY (section_id) REFERENCES sections (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS audit_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED DEFAULT NULL,
  action VARCHAR(100) NOT NULL,
  ip VARCHAR(45) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
    ];

    foreach ($schemaStatements as $sql) {
        if (! $conn->query($sql)) {
            error_log('DB schema init failed: ' . $conn->error);
        }
    }

    $colRes = $conn->query("SHOW COLUMNS FROM assignments");
    $existingCols = [];
    if ($colRes) {
        while ($r = $colRes->fetch_assoc()) $existingCols[] = $r['Field'];
        $colRes->free();
    }
    $alters = [];
    if (!in_array('subject_id', $existingCols, true)) {
        $alters[] = 'ADD COLUMN subject_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER section_id';
        $alters[] = 'ADD INDEX idx_assignment_subject (subject_id)';
    }
    if (!in_array('module', $existingCols, true)) {
        $alters[] = 'ADD COLUMN module VARCHAR(255) DEFAULT NULL AFTER semester';
    }
    if ($alters) {
        $conn->query('ALTER TABLE assignments ' . implode(', ', $alters));
    }

    $teacherCols = [];
    $tc = $conn->query("SHOW COLUMNS FROM teachers");
    if ($tc) {
        while ($r = $tc->fetch_assoc()) $teacherCols[] = $r['Field'];
        $tc->free();
    }
    $tAlters = [];
    if (!in_array('first_name', $teacherCols, true)) $tAlters[] = 'ADD COLUMN first_name VARCHAR(100) NOT NULL DEFAULT "" AFTER id';
    if (!in_array('middle_name', $teacherCols, true)) $tAlters[] = 'ADD COLUMN middle_name VARCHAR(100) DEFAULT NULL AFTER first_name';
    if (!in_array('last_name', $teacherCols, true)) $tAlters[] = 'ADD COLUMN last_name VARCHAR(100) NOT NULL DEFAULT "" AFTER middle_name';
    if (!in_array('section_id', $teacherCols, true)) $tAlters[] = 'ADD COLUMN section_id INT UNSIGNED DEFAULT NULL AFTER last_name';
    if ($tAlters) { $conn->query('ALTER TABLE teachers ' . implode(', ', $tAlters)); }

    $studentCols = [];
    $sc = $conn->query("SHOW COLUMNS FROM students");
    if ($sc) {
        while ($r = $sc->fetch_assoc()) $studentCols[] = $r['Field'];
        $sc->free();
    }
    $sAlters = [];
    if (!in_array('first_name', $studentCols, true)) $sAlters[] = 'ADD COLUMN first_name VARCHAR(100) NOT NULL DEFAULT "" AFTER id';
    if (!in_array('middle_name', $studentCols, true)) $sAlters[] = 'ADD COLUMN middle_name VARCHAR(100) DEFAULT NULL AFTER first_name';
    if (!in_array('last_name', $studentCols, true)) $sAlters[] = 'ADD COLUMN last_name VARCHAR(100) NOT NULL DEFAULT "" AFTER middle_name';
    if (!in_array('department', $studentCols, true)) $sAlters[] = 'ADD COLUMN department VARCHAR(128) DEFAULT NULL AFTER last_name';
    if (!in_array('section_id', $studentCols, true)) $sAlters[] = 'ADD COLUMN section_id INT UNSIGNED DEFAULT NULL AFTER department';
    if ($sAlters) { $conn->query('ALTER TABLE students ' . implode(', ', $sAlters)); }

    $subjCols = [];
    $sj = $conn->query("SHOW COLUMNS FROM subjects");
    if ($sj) {
        while ($r = $sj->fetch_assoc()) $subjCols[] = $r['Field'];
        $sj->free();
    }
    if (!in_array('units', $subjCols, true)) {
        $conn->query("ALTER TABLE subjects ADD COLUMN units INT UNSIGNED NOT NULL DEFAULT 3 AFTER title");
    }

    $gradeCheck = $conn->query("SHOW TABLES LIKE 'grades'");
    if (!$gradeCheck || $gradeCheck->num_rows === 0) {
        $conn->query("CREATE TABLE IF NOT EXISTS grades (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          student_id INT UNSIGNED NOT NULL,
          subject_id INT UNSIGNED NOT NULL,
          teacher_id INT UNSIGNED NOT NULL,
          section_id INT UNSIGNED NOT NULL,
          school_year VARCHAR(20) NOT NULL,
          semester VARCHAR(20) NOT NULL,
          prelim DECIMAL(5,2) DEFAULT NULL,
          midterm DECIMAL(5,2) DEFAULT NULL,
          finals DECIMAL(5,2) DEFAULT NULL,
          average DECIMAL(5,2) DEFAULT NULL,
          gwa VARCHAR(10) DEFAULT NULL,
          remarks VARCHAR(20) DEFAULT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_grade_record (student_id, subject_id, school_year, semester),
          INDEX idx_grade_student (student_id),
          INDEX idx_grade_subject (subject_id),
          INDEX idx_grade_teacher (teacher_id),
          INDEX idx_grade_section (section_id),
          CONSTRAINT fk_grade_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE,
          CONSTRAINT fk_grade_subject FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE CASCADE,
          CONSTRAINT fk_grade_teacher FOREIGN KEY (teacher_id) REFERENCES teachers (id) ON DELETE RESTRICT,
          CONSTRAINT fk_grade_section FOREIGN KEY (section_id) REFERENCES sections (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    if ($gradeCheck) $gradeCheck->free();

    $idxRes = $conn->query("SHOW INDEX FROM students WHERE Key_name = 'uniq_student_email'");
    if ($idxRes && $idxRes->num_rows > 0) {
        $conn->query("ALTER TABLE students DROP INDEX uniq_student_email");
    }
    if ($idxRes) $idxRes->free();

    $idxRes = $conn->query("SHOW INDEX FROM teachers WHERE Key_name = 'uniq_teacher_email'");
    if ($idxRes && $idxRes->num_rows > 0) {
        $conn->query("ALTER TABLE teachers DROP INDEX uniq_teacher_email");
    }
    if ($idxRes) $idxRes->free();

    $auditAlters = [];
    $auditCols = [];
    $ac = $conn->query("SHOW COLUMNS FROM audit_logs");
    if ($ac) {
        while ($r = $ac->fetch_assoc()) $auditCols[] = $r['Field'];
        $ac->free();
    }
    foreach (['academic_year', 'semester', 'grading_period', 'request_id', 'teacher_id', 'subject_id', 'section_id'] as $col) {
        if (!in_array($col, $auditCols, true)) {
            $auditAlters[] = "ADD COLUMN {$col} VARCHAR(20) DEFAULT NULL AFTER action";
            if (in_array($col, ['request_id', 'teacher_id', 'subject_id', 'section_id'], true)) {
                $auditAlters[] = "ADD INDEX idx_audit_{$col} ({$col})";
            }
        }
    }
    if ($auditAlters) {
        $conn->query('ALTER TABLE audit_logs ' . implode(', ', $auditAlters));
    }

$dlCheck = $conn->query("SHOW TABLES LIKE 'submission_deadlines'");
     if (!$dlCheck || $dlCheck->num_rows === 0) {
         $conn->query("CREATE TABLE IF NOT EXISTS submission_deadlines (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            school_year VARCHAR(20) NOT NULL,
            semester VARCHAR(20) NOT NULL,
            grading_period ENUM('prelim','midterm','finals') NOT NULL,
            start_date DATETIME DEFAULT NULL,
            end_date DATETIME DEFAULT NULL,
            status ENUM('open','closed','extended') NOT NULL DEFAULT 'open',
            extended_until DATETIME DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_deadline (school_year, semester, grading_period)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
     } else {
         $startDateCheck = $conn->query("SHOW COLUMNS FROM submission_deadlines WHERE Field = 'start_date'");
         if ($startDateCheck && $startDateCheck->num_rows === 0) {
             $conn->query("ALTER TABLE submission_deadlines ADD COLUMN start_date DATETIME DEFAULT NULL");
         } elseif ($startDateCheck && $startDateCheck->num_rows > 0) {
             $conn->query("ALTER TABLE submission_deadlines MODIFY COLUMN start_date DATETIME DEFAULT NULL");
         }
         if ($startDateCheck) $startDateCheck->free();
         $endDateCheck = $conn->query("SHOW COLUMNS FROM submission_deadlines WHERE Field = 'end_date'");
         if ($endDateCheck && $endDateCheck->num_rows === 0) {
             $conn->query("ALTER TABLE submission_deadlines ADD COLUMN end_date DATETIME DEFAULT NULL");
         } elseif ($endDateCheck && $endDateCheck->num_rows > 0) {
             $conn->query("ALTER TABLE submission_deadlines MODIFY COLUMN end_date DATETIME DEFAULT NULL");
         }
         if ($endDateCheck) $endDateCheck->free();
         $statusCheck = $conn->query("SHOW COLUMNS FROM submission_deadlines WHERE Field = 'status'");
         if ($statusCheck && $statusCheck->num_rows === 0) {
             $conn->query("ALTER TABLE submission_deadlines ADD COLUMN status ENUM('open','closed','extended') NOT NULL DEFAULT 'open'");
         } elseif ($statusCheck && $statusCheck->num_rows > 0) {
             $conn->query("ALTER TABLE submission_deadlines MODIFY COLUMN status ENUM('open','closed','extended') NOT NULL DEFAULT 'open'");
         }
         if ($statusCheck) $statusCheck->free();
         $extendedCheck = $conn->query("SHOW COLUMNS FROM submission_deadlines WHERE Field = 'extended_until'");
         if ($extendedCheck && $extendedCheck->num_rows === 0) {
             $conn->query("ALTER TABLE submission_deadlines ADD COLUMN extended_until DATETIME DEFAULT NULL");
         }
         if ($extendedCheck) $extendedCheck->free();
         $deadlineCheck = $conn->query("SHOW COLUMNS FROM submission_deadlines WHERE Field = 'deadline'");
         if ($deadlineCheck && $deadlineCheck->num_rows > 0) {
             $conn->query("ALTER TABLE submission_deadlines DROP COLUMN deadline");
         }
         if ($deadlineCheck) $deadlineCheck->free();
         $idxCheck = $conn->query("SHOW INDEX FROM submission_deadlines WHERE Key_name = 'uniq_deadline'");
         if ($idxCheck && $idxCheck->num_rows === 0) {
             $conn->query("ALTER TABLE submission_deadlines ADD UNIQUE KEY uniq_deadline (school_year, semester, grading_period)");
         }
         if ($idxCheck) $idxCheck->free();
     }
    if ($dlCheck) $dlCheck->free();

    $reqCheck = $conn->query("SHOW TABLES LIKE 'grade_change_requests'");
    if (!$reqCheck || $reqCheck->num_rows === 0) {
        $conn->query("CREATE TABLE IF NOT EXISTS grade_change_requests (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          teacher_id INT UNSIGNED NOT NULL,
          section_id INT UNSIGNED NOT NULL,
          subject_id INT UNSIGNED NOT NULL,
          school_year VARCHAR(20) NOT NULL,
          semester VARCHAR(20) NOT NULL,
          grading_period ENUM('prelim','midterm','finals') NOT NULL,
          reason TEXT NOT NULL,
          status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
          admin_response TEXT DEFAULT NULL,
          admin_id INT UNSIGNED DEFAULT NULL,
          expires_at DATETIME DEFAULT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX idx_request_teacher (teacher_id),
          INDEX idx_request_status (status),
          CONSTRAINT fk_request_teacher FOREIGN KEY (teacher_id) REFERENCES teachers (id) ON DELETE CASCADE,
          CONSTRAINT fk_request_section FOREIGN KEY (section_id) REFERENCES sections (id) ON DELETE CASCADE,
          CONSTRAINT fk_request_subject FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    if ($reqCheck) $reqCheck->free();

    $permCheck = $conn->query("SHOW TABLES LIKE 'permission_grants'");
    if (!$permCheck || $permCheck->num_rows === 0) {
        $conn->query("CREATE TABLE IF NOT EXISTS permission_grants (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          teacher_id INT UNSIGNED NOT NULL,
          school_year VARCHAR(20) NOT NULL,
          semester VARCHAR(20) NOT NULL,
          grading_period ENUM('prelim','midterm','finals') NOT NULL,
          granted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          expires_at DATETIME DEFAULT NULL,
          revoked_at DATETIME DEFAULT NULL,
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          INDEX idx_permission_teacher (teacher_id),
          INDEX idx_permission_active (is_active),
          CONSTRAINT fk_permission_teacher FOREIGN KEY (teacher_id) REFERENCES teachers (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    if ($permCheck) $permCheck->free();

    $notifCheck = $conn->query("SHOW TABLES LIKE 'notifications'");
    if (!$notifCheck || $notifCheck->num_rows === 0) {
        $conn->query("CREATE TABLE IF NOT EXISTS notifications (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          user_id INT UNSIGNED NOT NULL,
          user_role ENUM('teacher','admin','student') NOT NULL,
          title VARCHAR(255) NOT NULL,
          message TEXT NOT NULL,
          type ENUM('info','success','warning','error') NOT NULL DEFAULT 'info',
          link VARCHAR(255) DEFAULT NULL,
          is_read TINYINT(1) NOT NULL DEFAULT 0,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_notification_user (user_id),
          INDEX idx_notification_read (is_read)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } else {
        $notifColCheck = $conn->query("SHOW COLUMNS FROM notifications WHERE Field = 'user_role'");
        if ($notifColCheck && $notifColCheck->num_rows > 0) {
            $conn->query("ALTER TABLE notifications MODIFY COLUMN user_role ENUM('teacher','admin','student') NOT NULL");
        }
    }
    if ($notifCheck) $notifCheck->free();

    $viewCheck = $conn->query("SHOW TABLES LIKE 'grade_viewing_schedules'");
    if (!$viewCheck || $viewCheck->num_rows === 0) {
        $conn->query("CREATE TABLE IF NOT EXISTS grade_viewing_schedules (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          school_year VARCHAR(20) NOT NULL,
          semester VARCHAR(20) NOT NULL,
          start_date DATETIME NOT NULL,
          end_date DATETIME NOT NULL,
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_viewing_schedule (school_year, semester)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    if ($viewCheck) $viewCheck->free();

    $accessReqCheck = $conn->query("SHOW TABLES LIKE 'grade_access_requests'");
    if (!$accessReqCheck || $accessReqCheck->num_rows === 0) {
        $conn->query("CREATE TABLE IF NOT EXISTS grade_access_requests (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          student_id INT UNSIGNED NOT NULL,
          school_year VARCHAR(20) NOT NULL,
          semester VARCHAR(20) NOT NULL,
          reason TEXT DEFAULT NULL,
          status ENUM('pending','approved','rejected','expired') NOT NULL DEFAULT 'pending',
          admin_id INT UNSIGNED DEFAULT NULL,
          admin_response TEXT DEFAULT NULL,
          access_start DATETIME DEFAULT NULL,
          access_end DATETIME DEFAULT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX idx_gar_student (student_id),
          INDEX idx_gar_status (status),
          CONSTRAINT fk_gar_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    if ($accessReqCheck) $accessReqCheck->free();

    $reqSchedCheck = $conn->query("SHOW TABLES LIKE 'grade_request_schedules'");
    if (!$reqSchedCheck || $reqSchedCheck->num_rows === 0) {
        $conn->query("CREATE TABLE IF NOT EXISTS grade_request_schedules (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          school_year VARCHAR(20) NOT NULL,
          semester VARCHAR(20) NOT NULL,
          start_date DATETIME NOT NULL,
          end_date DATETIME NOT NULL,
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_req_schedule (school_year, semester)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    if ($reqSchedCheck) $reqSchedCheck->free();

    $teacherPicCheck = $conn->query("SHOW COLUMNS FROM teachers WHERE Field = 'profile_picture'");
    if (!$teacherPicCheck || $teacherPicCheck->num_rows === 0) {
        $conn->query("ALTER TABLE teachers ADD COLUMN profile_picture VARCHAR(255) DEFAULT NULL AFTER last_name");
    }
    if ($teacherPicCheck) $teacherPicCheck->free();
}
