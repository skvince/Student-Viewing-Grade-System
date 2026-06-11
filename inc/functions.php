<?php
require_once __DIR__ . '/db.php';

/**
 * Simple query helper that returns mysqli_result or false.
 */
function db_query(string $sql) {
    $conn = db_connect();
    if (!$conn) return false;
    $res = $conn->query($sql);
    $conn->close();
    return $res;
}

/**
 * Escape a string using a transient connection.
 */
function db_escape(string $value): string {
    $conn = db_connect();
    if (!$conn) return '';
    $escaped = $conn->real_escape_string($value);
    $conn->close();
    return $escaped;
}

/**
 * Audit log helper — stores a simple record in `audit_logs`.
 * Returns true on success.
 */
function audit_log(string $action, ?int $user_id = null): bool {
    $conn = db_connect();
    if (!$conn) return false;
    $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, action, ip) VALUES (?, ?, ?)");
    if (!$stmt) { $conn->close(); return false; }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmt->bind_param('iss', $user_id, $action, $ip);
    $ok = $stmt->execute();
    $stmt->close();
    $conn->close();
    return (bool) $ok;
}

/**
 * Generate full name from parts.
 */
function make_full_name(string $first, string $middle, string $last): string {
    $parts = array_filter([$first, $middle, $last], fn($p) => $p !== '');
    return implode(' ', $parts);
}

/**
 * Generate auto password: ID + LastName (or random prefix if no ID yet).
 */
function generate_password(string $lastName, ?int $id = null): string {
    $base = preg_replace('/[^a-zA-Z0-9]/', '', $lastName);
    if ($id) {
        return $id . $base;
    }
    return rand(1000, 9999) . $base;
}

/**
 * Authenticate teacher by teacher_id or student_id.
 * Returns user array on success, null on failure.
 */
function authenticate_user(string $username, string $password): ?array {
    $conn = db_connect();
    if (!$conn) return null;

    $stmt = $conn->prepare("SELECT id, teacher_id, first_name, middle_name, last_name, password_hash, password FROM teachers WHERE teacher_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $row = $res->fetch_assoc()) {
            $hash = $row['password_hash'] ?? '';
            $legacy = $row['password'] ?? '';
            if ($hash && password_verify($password, $hash)) {
                $stmt->close();
                $conn->close();
                return array_merge($row, ['role' => 'teacher']);
            }
            if ($legacy && $legacy === $password) {
                $stmt->close();
                $conn->close();
                return array_merge($row, ['role' => 'teacher']);
            }
        }
        $stmt->close();
    }

    $stmt = $conn->prepare("SELECT id, student_id, first_name, middle_name, last_name, password_hash, password FROM students WHERE student_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $row = $res->fetch_assoc()) {
            $hash = $row['password_hash'] ?? '';
            $legacy = $row['password'] ?? '';
            if ($hash && password_verify($password, $hash)) {
                $stmt->close();
                $conn->close();
                return array_merge($row, ['role' => 'student']);
            }
            if ($legacy && $legacy === $password) {
                $stmt->close();
                $conn->close();
                return array_merge($row, ['role' => 'student']);
            }
        }
        $stmt->close();
    }

    $conn->close();
    return null;
}

/**
 * Get teacher's assigned sections with subjects for a given school_year and semester.
 */
function get_teacher_assignments(int $teacherId, string $schoolYear, string $semester): array {
    $conn = db_connect();
    if (!$conn) return [];
    $stmt = $conn->prepare("
        SELECT a.id as assignment_id, a.section_id, a.subject_id, a.school_year, a.semester,
               s.section_code, s.name as section_name,
               sj.subject_code, sj.title as subject_title, sj.units,
               sec.department
        FROM assignments a
        LEFT JOIN sections s ON a.section_id = s.id
        LEFT JOIN subjects sj ON a.subject_id = sj.id
        LEFT JOIN sections sec ON a.section_id = sec.id
        WHERE a.teacher_id = ? AND a.school_year = ? AND a.semester = ?
        ORDER BY s.section_code ASC, sj.subject_code ASC
    ");
    if (!$stmt) { $conn->close(); return []; }
    $stmt->bind_param('iss', $teacherId, $schoolYear, $semester);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    $conn->close();
    return $rows;
}

/**
 * Get students in a section.
 */
function get_section_students(int $sectionId): array {
    $conn = db_connect();
    if (!$conn) return [];
    $stmt = $conn->prepare("
        SELECT s.id, s.student_id, s.first_name, s.middle_name, s.last_name, s.name,
               sec.section_code, sec.name as section_name
        FROM students s
        LEFT JOIN sections sec ON s.section_id = sec.id
        WHERE s.section_id = ?
        ORDER BY s.last_name ASC, s.first_name ASC
    ");
    if (!$stmt) { $conn->close(); return []; }
    $stmt->bind_param('i', $sectionId);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    $conn->close();
    return $rows;
}

/**
 * Get existing grades for a student in a subject/section/term.
 */
function get_grade(int $studentId, int $subjectId, string $schoolYear, string $semester): ?array {
    $conn = db_connect();
    if (!$conn) return null;
    $stmt = $conn->prepare("
        SELECT id, prelim, midterm, finals, average, gwa, remarks
        FROM grades
        WHERE student_id = ? AND subject_id = ? AND school_year = ? AND semester = ?
        LIMIT 1
    ");
    if (!$stmt) { $conn->close(); return null; }
    $stmt->bind_param('iiss', $studentId, $subjectId, $schoolYear, $semester);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    $conn->close();
    return $row ?: null;
}

/**
 * Save or update a grade record.
 */
function save_grade(int $studentId, int $subjectId, int $teacherId, int $sectionId,
                    string $schoolYear, string $semester, ?float $prelim, ?float $midterm, ?float $finals): bool {
    $conn = db_connect();
    if (!$conn) return false;

    $avg = null;
    $gwa = null;
    $remarks = null;
    if ($prelim !== null && $midterm !== null && $finals !== null) {
        $avg = round(($prelim + $midterm + $finals) / 3, 2);
        $gwa = gwa_from_percentage($avg);
        $remarks = $avg >= 75 ? 'Passed' : 'Failed';
    }

    $existing = get_grade($studentId, $subjectId, $schoolYear, $semester);
    if ($existing) {
        $stmt = $conn->prepare("
            UPDATE grades SET prelim = ?, midterm = ?, finals = ?, average = ?, gwa = ?, remarks = ?, teacher_id = ?
            WHERE student_id = ? AND subject_id = ? AND school_year = ? AND semester = ?
        ");
        if (!$stmt) { $conn->close(); return false; }
        $stmt->bind_param('ddddssiiiss',
            $prelim, $midterm, $finals, $avg, $gwa, $remarks, $teacherId,
            $studentId, $subjectId, $schoolYear, $semester
        );
        $ok = $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare("
            INSERT INTO grades (student_id, subject_id, teacher_id, section_id, school_year, semester, prelim, midterm, finals, average, gwa, remarks)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) { $conn->close(); return false; }
        $stmt->bind_param('iiiisssddsss',
            $studentId, $subjectId, $teacherId, $sectionId, $schoolYear, $semester,
            $prelim, $midterm, $finals, $avg, $gwa, $remarks
        );
        $ok = $stmt->execute();
        $stmt->close();
    }
    $conn->close();
    return (bool) $ok;
}

/**
 * Convert percentage to GWA equivalent.
 */
function gwa_from_percentage(float $percentage): string {
    if ($percentage >= 97) return '1.00';
    if ($percentage >= 94) return '1.25';
    if ($percentage >= 91) return '1.50';
    if ($percentage >= 88) return '1.75';
    if ($percentage >= 85) return '2.00';
    if ($percentage >= 82) return '2.25';
    if ($percentage >= 79) return '2.50';
    if ($percentage >= 76) return '2.75';
    if ($percentage >= 75) return '3.00';
    return '5.00';
}

/**
 * Get student's grades for display, filtered by year/semester.
 */
function get_student_grades(int $studentId, string $schoolYear = '', string $semester = ''): array {
    $conn = db_connect();
    if (!$conn) return [];
    $sql = "
        SELECT g.school_year, g.semester, g.prelim, g.midterm, g.finals, g.average, g.gwa, g.remarks,
               sj.subject_code, sj.title as subject_title, sj.units
        FROM grades g
        LEFT JOIN subjects sj ON g.subject_id = sj.id
        WHERE g.student_id = ?
    ";
    $params = 'i';
    $vals = [$studentId];
    if ($schoolYear) {
        $sql .= " AND g.school_year = ?";
        $params .= 's';
        $vals[] = $schoolYear;
    }
    if ($semester) {
        $sql .= " AND g.semester = ?";
        $params .= 's';
        $vals[] = $semester;
    }
    $sql .= " ORDER BY g.school_year DESC, g.semester DESC, sj.subject_code ASC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) { $conn->close(); return []; }
    $stmt->bind_param($params, ...$vals);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    $conn->close();
    return $rows;
}

/**
 * Get subjects assigned to a student's section for a given year/semester.
 * Returns subjects even if no grade record exists yet.
 */
function get_student_assigned_subjects(int $studentId, string $schoolYear = '', string $semester = ''): array {
    $conn = db_connect();
    if (!$conn) return [];
    $sql = "
        SELECT DISTINCT sj.subject_code, sj.title, sj.units,
               a.school_year, a.semester
        FROM assignments a
        LEFT JOIN subjects sj ON a.subject_id = sj.id
        LEFT JOIN students s ON s.section_id = a.section_id
        WHERE s.id = ?
    ";
    $params = 'i';
    $vals = [$studentId];
    if ($schoolYear) {
        $sql .= " AND a.school_year = ?";
        $params .= 's';
        $vals[] = $schoolYear;
    }
    if ($semester) {
        $sql .= " AND a.semester = ?";
        $params .= 's';
        $vals[] = $semester;
    }
    $sql .= " ORDER BY sj.subject_code ASC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) { $conn->close(); return []; }
    $stmt->bind_param($params, ...$vals);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    $conn->close();
    return $rows;
}

/**
 * Get available school years and semesters from assignments/grades.
 */
function get_available_terms(int $userId, string $role): array {
    $conn = db_connect();
    if (!$conn) return ['years' => [], 'semesters' => []];
    if ($role === 'teacher') {
        $stmt = $conn->prepare("SELECT DISTINCT school_year, semester FROM assignments WHERE teacher_id = ? ORDER BY school_year DESC");
        $stmt->bind_param('i', $userId);
    } else {
        $stmt = $conn->prepare("SELECT DISTINCT g.school_year, g.semester FROM grades g WHERE g.student_id = ? ORDER BY g.school_year DESC");
        $stmt->bind_param('i', $userId);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $years = [];
    $semesters = [];
    while ($r = $res->fetch_assoc()) {
        if (!in_array($r['school_year'], $years, true)) $years[] = $r['school_year'];
        if (!in_array($r['semester'], $semesters, true)) $semesters[] = $r['semester'];
    }
    $stmt->close();
    $conn->close();
    return ['years' => $years, 'semesters' => $semesters];
}

/**
 * Create teacher account with auto-generated password.
 */
function create_teacher(string $firstName, string $middleName, string $lastName, ?string $department = null): array {
    $conn = db_connect();
    if (!$conn) return ['success' => false, 'error' => 'Database connection failed'];
    $fullName = make_full_name($firstName, $middleName, $lastName);
    $stmt = $conn->prepare("INSERT INTO teachers (first_name, middle_name, last_name, name, department) VALUES (?, ?, ?, ?, ?)");
    if (!$stmt) { $conn->close(); return ['success' => false, 'error' => $conn->error]; }
    $stmt->bind_param('sssss', $firstName, $middleName, $lastName, $fullName, $department);
    if (!$stmt->execute()) { $err = $stmt->error; $stmt->close(); $conn->close(); return ['success' => false, 'error' => $err]; }
    $newId = $conn->insert_id;
    $code = sprintf('T-%03d', $newId);
    $upd = $conn->prepare("UPDATE teachers SET teacher_id = ? WHERE id = ?");
    if ($upd) { $upd->bind_param('si', $code, $newId); $upd->execute(); $upd->close(); }
    $password = generate_password($lastName, $newId);
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $upd2 = $conn->prepare("UPDATE teachers SET password_hash = ? WHERE id = ?");
    if ($upd2) { $upd2->bind_param('si', $hash, $newId); $upd2->execute(); $upd2->close(); }
    $stmt->close();
    $conn->close();
    return ['success' => true, 'id' => $newId, 'teacher_id' => $code, 'password' => $password, 'name' => $fullName];
}

/**
 * Create student account with auto-generated password.
 */
function create_student(string $firstName, string $middleName, string $lastName, ?int $sectionId = null, ?string $department = null): array {
    $conn = db_connect();
    if (!$conn) return ['success' => false, 'error' => 'Database connection failed'];
    $fullName = make_full_name($firstName, $middleName, $lastName);
    $stmt = $conn->prepare("INSERT INTO students (first_name, middle_name, last_name, name, section_id, department) VALUES (?, ?, ?, ?, ?, ?)");
    if (!$stmt) { $conn->close(); return ['success' => false, 'error' => $conn->error]; }
    $stmt->bind_param('ssssis', $firstName, $middleName, $lastName, $fullName, $sectionId, $department);
    if (!$stmt->execute()) { $err = $stmt->error; $stmt->close(); $conn->close(); return ['success' => false, 'error' => $err]; }
    $newId = $conn->insert_id;
    $code = sprintf('S-%03d', $newId);
    $upd = $conn->prepare("UPDATE students SET student_id = ? WHERE id = ?");
    if ($upd) { $upd->bind_param('si', $code, $newId); $upd->execute(); $upd->close(); }
    $password = strtoupper(substr($firstName, 0, 1)) . $lastName;
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $upd2 = $conn->prepare("UPDATE students SET password_hash = ? WHERE id = ?");
    if ($upd2) { $upd2->bind_param('si', $hash, $newId); $upd2->execute(); $upd2->close(); }
    $stmt->close();
    $conn->close();
    return ['success' => true, 'id' => $newId, 'student_id' => $code, 'password' => $password, 'name' => $fullName];
}

/**
 * Update teacher record.
 */
function update_teacher(int $id, string $firstName, string $middleName, string $lastName, ?string $department = null, ?string $password = null): bool {
    $conn = db_connect();
    if (!$conn) return false;
    $fullName = make_full_name($firstName, $middleName, $lastName);
    if ($password) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE teachers SET first_name = ?, middle_name = ?, last_name = ?, name = ?, department = ?, password_hash = ? WHERE id = ?");
        if (!$stmt) { $conn->close(); return false; }
        $stmt->bind_param('ssssssi', $firstName, $middleName, $lastName, $fullName, $department, $hash, $id);
    } else {
        $stmt = $conn->prepare("UPDATE teachers SET first_name = ?, middle_name = ?, last_name = ?, name = ?, department = ? WHERE id = ?");
        if (!$stmt) { $conn->close(); return false; }
        $stmt->bind_param('sssssi', $firstName, $middleName, $lastName, $fullName, $department, $id);
    }
    $ok = $stmt->execute();
    $stmt->close();
    $conn->close();
    return (bool) $ok;
}

/**
 * Update student record.
 */
function update_student(int $id, string $firstName, string $middleName, string $lastName, ?int $sectionId = null, ?string $password = null): bool {
    $conn = db_connect();
    if (!$conn) return false;
    $fullName = make_full_name($firstName, $middleName, $lastName);
    if ($password) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE students SET first_name = ?, middle_name = ?, last_name = ?, name = ?, section_id = ?, password_hash = ? WHERE id = ?");
        if (!$stmt) { $conn->close(); return false; }
        $stmt->bind_param('ssssisi', $firstName, $middleName, $lastName, $fullName, $sectionId, $hash, $id);
    } else {
        $stmt = $conn->prepare("UPDATE students SET first_name = ?, middle_name = ?, last_name = ?, name = ?, section_id = ? WHERE id = ?");
        if (!$stmt) { $conn->close(); return false; }
        $stmt->bind_param('ssssii', $firstName, $middleName, $lastName, $fullName, $sectionId, $id);
    }
    $ok = $stmt->execute();
    $stmt->close();
    $conn->close();
    return (bool) $ok;
}

/**
 * Delete a teacher.
 */
function delete_teacher(int $id): bool {
    $conn = db_connect();
    if (!$conn) return false;
    $stmt = $conn->prepare("DELETE FROM teachers WHERE id = ?");
    if (!$stmt) { $conn->close(); return false; }
    $stmt->bind_param('i', $id);
    $ok = $stmt->execute();
    $stmt->close();
    $conn->close();
    return (bool) $ok;
}

/**
 * Delete a student.
 */
function delete_student(int $id): bool {
    $conn = db_connect();
    if (!$conn) return false;
    $stmt = $conn->prepare("DELETE FROM students WHERE id = ?");
    if (!$stmt) { $conn->close(); return false; }
    $stmt->bind_param('i', $id);
    $ok = $stmt->execute();
    $stmt->close();
    $conn->close();
    return (bool) $ok;
}

/**
 * Get all teachers for dropdowns.
 */
function get_all_teachers(): array {
    $conn = db_connect();
    if (!$conn) return [];
    $res = $conn->query("SELECT id, teacher_id, first_name, middle_name, last_name, name, department FROM teachers ORDER BY last_name ASC, first_name ASC");
    if (!$res) { $conn->close(); return []; }
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $res->free();
    $conn->close();
    return $rows;
}

/**
 * Get all students for dropdowns.
 */
function get_all_students(): array {
    $conn = db_connect();
    if (!$conn) return [];
    $res = $conn->query("SELECT s.id, s.student_id, s.first_name, s.middle_name, s.last_name, s.name, s.section_id, sec.section_code, sec.name as section_name FROM students s LEFT JOIN sections sec ON s.section_id = sec.id ORDER BY s.last_name ASC, s.first_name ASC");
    if (!$res) { $conn->close(); return []; }
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $res->free();
    $conn->close();
    return $rows;
}

/**
 * Get all sections.
 */
function get_all_sections(): array {
    $conn = db_connect();
    if (!$conn) return [];
    $res = $conn->query("SELECT id, section_code, name, department, school_year, semester FROM sections ORDER BY created_at DESC");
    if (!$res) { $conn->close(); return []; }
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $res->free();
    $conn->close();
    return $rows;
}

/**
 * Get all subjects.
 */
function get_all_subjects(): array {
    $conn = db_connect();
    if (!$conn) return [];
    $res = $conn->query("SELECT id, subject_code, title, school_year, semester FROM subjects ORDER BY subject_code ASC");
    if (!$res) { $conn->close(); return []; }
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $res->free();
    $conn->close();
    return $rows;
}
