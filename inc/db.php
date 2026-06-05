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
  name VARCHAR(255) NOT NULL,
  email VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) DEFAULT NULL,
  password VARCHAR(255) DEFAULT NULL,
  department VARCHAR(128) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_teacher_id (teacher_id),
  UNIQUE KEY uniq_teacher_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS students (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id VARCHAR(32) DEFAULT NULL,
  name VARCHAR(255) NOT NULL,
  email VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) DEFAULT NULL,
  password VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_student_id (student_id),
  UNIQUE KEY uniq_student_email (email)
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
CREATE TABLE IF NOT EXISTS assignments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT UNSIGNED NOT NULL,
  section_id INT UNSIGNED NOT NULL,
  module VARCHAR(255) DEFAULT NULL,
  school_year VARCHAR(20) DEFAULT NULL,
  semester VARCHAR(20) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_assignment_teacher (teacher_id),
  INDEX idx_assignment_section (section_id)
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
}
