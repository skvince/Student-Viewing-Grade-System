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
    // Disable mysqli exceptions and try to connect; if database doesn't exist, create it.
    mysqli_report(MYSQLI_REPORT_OFF);
    $conn = @new mysqli($host, $user, $pass, $db);
    if ($conn->connect_errno) {
        // 1049 = Unknown database
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
            // try reconnect to the newly created database
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
}
