<?php
require_once __DIR__ . '/db.php';

function csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    $token = csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES) . '">';
}

function verify_csrf(): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $token = $_POST['csrf_token'] ?? '';
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function db_query(string $sql) {
    $conn = db_connect();
    if (!$conn) return false;
    $res = $conn->query($sql);
    $conn->close();
    return $res;
}

function db_escape(string $value): string {
    $conn = db_connect();
    if (!$conn) return '';
    $escaped = $conn->real_escape_string($value);
    $conn->close();
    return $escaped;
}

function audit_log(string $action, ?int $user_id = null, ?string $grading_period = null, ?string $academic_year = null, ?string $semester = null, ?int $request_id = null, ?int $teacher_id = null, ?int $subject_id = null, ?int $section_id = null): bool {
    $conn = db_connect();
    if (!$conn) return false;
    $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, action, grading_period, academic_year, semester, request_id, teacher_id, subject_id, section_id, ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) { $conn->close(); return false; }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmt->bind_param('issssiiiis', $user_id, $action, $grading_period, $academic_year, $semester, $request_id, $teacher_id, $subject_id, $section_id, $ip);
    $ok = $stmt->execute();
    $stmt->close();
    $conn->close();
    return (bool) $ok;
}

function make_full_name(string $first, string $middle, string $last): string {
    $parts = array_filter([$first, $middle, $last], fn($p) => $p !== '');
    return implode(' ', $parts);
}

function generate_password(string $lastName, ?int $id = null): string {
    // Convert spaces to underscores, as requested (e.g., "De la Cruz" => "De_la_Cruz").
    // Keep only alphanumeric and underscores for the password base.
    $base = str_replace(' ', '_', trim($lastName));
    $base = preg_replace('/[^a-zA-Z0-9_]/', '', $base);

    // Collapse multiple underscores to a single underscore.
    $base = preg_replace('/_+/', '_', $base);

    // Trim base in case last name is empty after sanitization.
    $base = $base === '' ? 'X' : $base;

    if ($id !== null) {
        return (string)$id . $base;
    }
    return (string)rand(1000, 9999) . $base;
}

function authenticate_user(string $username, string $password): ?array {
    $conn = db_connect();
    if (!$conn) return null;

    // 1) Teacher login by teacher_id (supports both `T-001` and `001` inputs)
    $normalizedTeacherUsername = $username;
    if (preg_match('/^T-?(\d{1,})$/i', $username, $m)) {
        $num = (int)$m[1];
        $normalizedTeacherUsername = 'T-' . str_pad((string)$num, 3, '0', STR_PAD_LEFT);
    }

    $stmt = $conn->prepare("SELECT id, teacher_id, first_name, middle_name, last_name, password_hash, password FROM teachers WHERE teacher_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $normalizedTeacherUsername);
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

            // If teacher exists but has no usable password fields, fall through to return null.
        }
        $stmt->close();
    }

    // 2) Backward compatibility for teachers created before credentials existed.
    // If teacher_id/password_hash are missing, allow login by database `id`.
    if (ctype_digit($username)) {
        $tid = (int)$username;
        $stmt = $conn->prepare("SELECT id, teacher_id, first_name, middle_name, last_name, password_hash, password FROM teachers WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $tid);
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
            $fallback = generate_password($row['last_name'], (int)$row['id']);
            if ($fallback === $password) {
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

function get_teacher_assignments(int $teacherId, string $schoolYear, string $semester): array {
    $schoolYear = normalize_school_year($schoolYear);
    $semester = normalize_semester($semester);

    $conn = db_connect();
    if (!$conn) return [];

    $stmt = $conn->prepare("SELECT a.id as assignment_id, a.section_id, a.subject_id, a.school_year, a.semester,
            s.name AS section_code, s.name as section_name,
            sj.subject_code, sj.title as subject_title, sj.units,
            sec.department
        FROM assignments a
        LEFT JOIN sections s ON a.section_id = s.id
        LEFT JOIN subjects sj ON a.subject_id = sj.id
        LEFT JOIN sections sec ON a.section_id = sec.id
        WHERE a.teacher_id = ? AND a.school_year = ? AND a.semester = ?
        ORDER BY s.name ASC, sj.subject_code ASC");
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

function get_section_students(int $sectionId): array {
    $conn = db_connect();
    if (!$conn) return [];

    $stmt = $conn->prepare("SELECT s.id, s.student_id, s.first_name, s.middle_name, s.last_name, s.name,
            sec.name AS section_code, sec.name as section_name
        FROM students s
        LEFT JOIN sections sec ON s.section_id = sec.id
        WHERE s.section_id = ?
        ORDER BY s.last_name ASC, s.first_name ASC");
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

function get_grade(int $studentId, int $subjectId, string $schoolYear, string $semester): ?array {
    $conn = db_connect();
    if (!$conn) return null;

    $stmt = $conn->prepare("SELECT id, prelim, midterm, finals, average, gwa, remarks
        FROM grades
        WHERE student_id = ? AND subject_id = ? AND school_year = ? AND semester = ?
        LIMIT 1");
    if (!$stmt) { $conn->close(); return null; }

    $stmt->bind_param('iiss', $studentId, $subjectId, $schoolYear, $semester);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;

    $stmt->close();
    $conn->close();
    return $row ?: null;
}

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
        $stmt = $conn->prepare("UPDATE grades SET prelim = ?, midterm = ?, finals = ?, average = ?, gwa = ?, remarks = ?, teacher_id = ?
            WHERE student_id = ? AND subject_id = ? AND school_year = ? AND semester = ?");
        if (!$stmt) { $conn->close(); return false; }

        $stmt->bind_param('ddddssiiiss', $prelim, $midterm, $finals, $avg, $gwa, $remarks, $teacherId,
            $studentId, $subjectId, $schoolYear, $semester);
        $ok = $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare("INSERT INTO grades (student_id, subject_id, teacher_id, section_id, school_year, semester, prelim, midterm, finals, average, gwa, remarks)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) { $conn->close(); return false; }

        $stmt->bind_param('iiiisssddsss', $studentId, $subjectId, $teacherId, $sectionId, $schoolYear, $semester,
            $prelim, $midterm, $finals, $avg, $gwa, $remarks);
        $ok = $stmt->execute();
        $stmt->close();
    }

    $conn->close();
    return (bool)$ok;
}

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

function get_student_grades(int $studentId, string $schoolYear = '', string $semester = ''): array {
    $conn = db_connect();
    if (!$conn) return [];

    $sql = "SELECT g.school_year, g.semester, g.prelim, g.midterm, g.finals, g.average, g.gwa, g.remarks,
            sj.subject_code, sj.title as subject_title, sj.units
        FROM grades g
        LEFT JOIN subjects sj ON g.subject_id = sj.id
        WHERE g.student_id = ?";

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

function get_student_assigned_subjects(int $studentId, string $schoolYear = '', string $semester = ''): array {
    $conn = db_connect();
    if (!$conn) return [];

    $sql = "SELECT DISTINCT sj.subject_code, sj.title, sj.units,
            a.school_year, a.semester
        FROM assignments a
        LEFT JOIN subjects sj ON a.subject_id = sj.id
        LEFT JOIN students s ON s.section_id = a.section_id
        WHERE s.id = ?";

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

function get_available_terms(int $userId, string $role): array {
    if ($role === 'teacher') {
        $conn = db_connect();
        if (!$conn) return ['years' => [], 'semesters' => []];
        $stmt = $conn->prepare("SELECT DISTINCT school_year, semester FROM assignments WHERE teacher_id = ? ORDER BY school_year DESC, semester DESC");
        if (!$stmt) { $conn->close(); return ['years' => [], 'semesters' => []]; }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
    } else {
        $pairs = get_student_valid_terms($userId);
        $res = null;
    }

    $years = [];
    $semesters = [];

    if ($res) {
        while ($r = $res->fetch_assoc()) {
            if (!in_array($r['school_year'], $years, true)) $years[] = $r['school_year'];
            if (!in_array($r['semester'], $semesters, true)) $semesters[] = $r['semester'];
        }
        $res->free();
    }
    if (!empty($conn)) $conn->close();
    return ['years' => $years, 'semesters' => $semesters];
}

function get_department_student_stats(string $schoolYear = '', string $semester = ''): array {
    $conn = db_connect();
    if (!$conn) return [];

    $sql = "SELECT s.department, COUNT(*) AS total_students FROM students s WHERE 1=1";
    $params = '';
    $vals = [];

    if ($schoolYear) {
        $sql .= " AND (s.id IN (SELECT student_id FROM grades WHERE school_year = ?) OR s.id NOT IN (SELECT student_id FROM grades WHERE school_year = ?))";
        $params .= 'ss';
        $vals[] = $schoolYear;
        $vals[] = $schoolYear;
    }
    if ($semester) {
        $sql .= " AND (s.id IN (SELECT student_id FROM grades WHERE semester = ?) OR s.id NOT IN (SELECT student_id FROM grades WHERE semester = ?))";
        $params .= 'ss';
        $vals[] = $semester;
        $vals[] = $semester;
    }

    $sql .= " GROUP BY s.department ORDER BY total_students DESC";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if ($params) {
            $stmt->bind_param($params, ...$vals);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) $rows[] = $row;
        $stmt->close();
    } else {
        $rows = [];
    }
    $conn->close();
    return $rows;
}

function get_department_teacher_stats(string $schoolYear = '', string $semester = ''): array {
    $conn = db_connect();
    if (!$conn) return [];

    $sql = "SELECT t.department, COUNT(*) AS total_teachers FROM teachers t WHERE 1=1";
    $params = '';
    $vals = [];

    if ($schoolYear) {
        $sql .= " AND (t.id IN (SELECT teacher_id FROM assignments WHERE school_year = ?) OR t.id NOT IN (SELECT teacher_id FROM assignments WHERE school_year = ?))";
        $params .= 'ss';
        $vals[] = $schoolYear;
        $vals[] = $schoolYear;
    }
    if ($semester) {
        $sql .= " AND (t.id IN (SELECT teacher_id FROM assignments WHERE semester = ?) OR t.id NOT IN (SELECT teacher_id FROM assignments WHERE semester = ?))";
        $params .= 'ss';
        $vals[] = $semester;
        $vals[] = $semester;
    }

    $sql .= " GROUP BY t.department ORDER BY total_teachers DESC";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if ($params) {
            $stmt->bind_param($params, ...$vals);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) $rows[] = $row;
        $stmt->close();
    } else {
        $rows = [];
    }
    if (!empty($conn)) $conn->close();
    return $rows;
}

function get_upcoming_deadlines(string $schoolYear, string $semester, int $limit = 5): array {
    $conn = db_connect();
    if (!$conn) return [];

    $stmt = $conn->prepare("SELECT id, school_year, semester, grading_period, start_date, end_date, status, extended_until, created_at, updated_at
        FROM submission_deadlines
        WHERE school_year = ? AND semester = ?
        ORDER BY FIELD(grading_period, 'prelim', 'midterm', 'finals'), created_at DESC
        LIMIT ?");
    if (!$stmt) { $conn->close(); return []; }

    $stmt->bind_param('ssi', $schoolYear, $semester, $limit);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    $conn->close();
    return $rows;
}

function get_student_valid_terms(int $userId): array {
    $conn = db_connect();
    if (!$conn) return [];

    $stmt = $conn->prepare("SELECT DISTINCT a.school_year, a.semester
        FROM assignments a
        LEFT JOIN students s ON s.section_id = a.section_id
        WHERE s.id = ?
        UNION
        SELECT DISTINCT g.school_year, g.semester
        FROM grades g
        WHERE g.student_id = ?
        ORDER BY school_year DESC, semester DESC");
    if (!$stmt) { $conn->close(); return []; }

    $stmt->bind_param('ii', $userId, $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $pairs = [];
    while ($r = $res->fetch_assoc()) {
        $pairs[] = ['year' => $r['school_year'], 'semester' => $r['semester']];
    }

    $stmt->close();
    $conn->close();
    return $pairs;
}

function get_semesters_for_year(array $pairs, string $year): array {
    $semesterOrder = ['1st Semester', '2nd Semester', 'Summer'];
    $semesters = [];
    foreach ($semesterOrder as $semester) {
        foreach ($pairs as $pair) {
            if (($pair['year'] ?? '') === $year && ($pair['semester'] ?? '') === $semester && !in_array($semester, $semesters, true)) {
                $semesters[] = $semester;
            }
        }
    }
    foreach ($pairs as $pair) {
        if (($pair['year'] ?? '') === $year && !in_array($pair['semester'] ?? '', $semesters, true)) {
            $semesters[] = $pair['semester'];
        }
    }
    return $semesters;
}

function get_first_available_student_term(array $pairs, string $preferredYear): ?array {
    $semesterOrder = ['1st Semester', '2nd Semester', 'Summer'];
    $yearPairs = array_values(array_filter($pairs, fn($pair) => ($pair['year'] ?? '') === $preferredYear));
    $candidates = $yearPairs ?: $pairs;

    usort($candidates, function ($a, $b) use ($semesterOrder) {
        $yearCompare = strcmp($b['year'] ?? '', $a['year'] ?? '');
        if ($yearCompare !== 0) return $yearCompare;

        $rankA = array_search($a['semester'] ?? '', $semesterOrder, true);
        $rankB = array_search($b['semester'] ?? '', $semesterOrder, true);
        $rankA = $rankA === false ? 999 : $rankA;
        $rankB = $rankB === false ? 999 : $rankB;
        return $rankA <=> $rankB;
    });

    foreach ($semesterOrder as $semester) {
        foreach ($candidates as $pair) {
            if (($pair['semester'] ?? '') === $semester) return $pair;
        }
    }

    return $candidates[0] ?? null;
}

function get_term_options(): array {
    return [
        'years' => ['2025-2026', '2026-2027'],
        'semesters' => ['1st Semester', '2nd Semester', 'Summer'],
    ];
}

function normalize_school_year($year): string {
    $year = is_string($year) ? trim($year) : '';
    $year = str_replace(['–', '—'], '-', $year);
    return preg_match('/^\d{4}-\d{4}$/', $year) ? $year : '';
}

function normalize_semester($semester): string {
    $semester = is_string($semester) ? trim($semester) : '';
    $semester = preg_replace('/\s+/', ' ', $semester);

    if ($semester === '') return '';

    $lower = strtolower($semester);
    if (in_array($lower, ['1', '1st', '1st sem', '1st semester', 'first', 'first semester'], true)) return '1st Semester';
    if (in_array($lower, ['2', '2nd', '2nd sem', '2nd semester', 'second', 'second semester'], true)) return '2nd Semester';
    if (in_array($lower, ['summer', 'summer term', '3rd', '3rd semester'], true)) return 'Summer';

    if (preg_match('/\b1(?:st)?\b/', $lower)) return '1st Semester';
    if (preg_match('/\b2(?:nd)?\b/', $lower)) return '2nd Semester';
    if (preg_match('/sum/i', $lower)) return 'Summer';

    return $semester;
}

function get_global_term(): array {
    $options = get_term_options();
    $yearCandidates = array_filter([
        $_GET['global_year'] ?? '',
        $_SESSION['global_year'] ?? '',
        $_GET['school_year'] ?? '',
        $_GET['academic_year'] ?? '',
        $_SESSION['teacher_sy'] ?? '',
        $_SESSION['student_filter_year'] ?? '',
    ], fn($v) => normalize_school_year($v) !== '');

    $semCandidates = array_filter([
        $_GET['global_sem'] ?? '',
        $_GET['global_semester'] ?? '',
        $_SESSION['global_sem'] ?? '',
        $_GET['semester'] ?? '',
        $_SESSION['teacher_sem'] ?? '',
        $_SESSION['student_filter_sem'] ?? '',
    ], fn($v) => normalize_semester($v) !== '');

    $year = normalize_school_year(reset($yearCandidates));
    $sem = normalize_semester(reset($semCandidates));

    if (!in_array($year, $options['years'], true)) $year = $options['years'][0] ?? '2025-2026';
    if (!in_array($sem, $options['semesters'], true)) $sem = $options['semesters'][0] ?? '1st Semester';

    $_SESSION['global_year'] = $year;
    $_SESSION['global_sem'] = $sem;
    $_SESSION['teacher_sy'] = $year;
    $_SESSION['teacher_sem'] = $sem;
    $_SESSION['student_filter_year'] = $year;
    $_SESSION['student_filter_sem'] = $sem;

    return ['year' => $year, 'semester' => $sem];
}

function get_departments(): array {
    $conn = db_connect();
    if (!$conn) return [];

    $conn->query(
        "CREATE TABLE IF NOT EXISTS departments (" .
        "id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, " .
        "department_code VARCHAR(100) NOT NULL, " .
        "name VARCHAR(255) NOT NULL, " .
        "created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, " .
        "UNIQUE KEY unique_department_code (department_code)" .
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $res = $conn->query("SELECT id, department_code, name FROM departments ORDER BY name ASC");
    $rows = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) $rows[] = $row;
        $res->free();
    }
    $conn->close();
    return $rows;
}

function get_all_deadlines(string $schoolYear, string $semester): array {
    $conn = db_connect();
    if (!$conn) return [];

    $stmt = $conn->prepare("SELECT id, school_year, semester, grading_period, start_date, end_date, status, extended_until, created_at, updated_at
        FROM submission_deadlines
        WHERE school_year = ? AND semester = ?
        ORDER BY FIELD(grading_period, 'prelim', 'midterm', 'finals')");
    if (!$stmt) { $conn->close(); return []; }

    $stmt->bind_param('ss', $schoolYear, $semester);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    $conn->close();
    return $rows;
}

function get_deadline_status(string $schoolYear, string $semester, string $gradingPeriod): string {
    $conn = db_connect();
    if (!$conn) return 'closed';

    $stmt = $conn->prepare("SELECT status, start_date, end_date, extended_until FROM submission_deadlines
        WHERE school_year = ? AND semester = ? AND grading_period = ? LIMIT 1");
    if (!$stmt) { $conn->close(); return 'closed'; }

    $stmt->bind_param('sss', $schoolYear, $semester, $gradingPeriod);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    $conn->close();

    if (!$row) return 'closed';

    $status = $row['status'];
    if ($status === 'extended' && $row['extended_until']) {
        $now = new DateTime();
        $exp = new DateTime($row['extended_until']);
        if ($now > $exp) {
            return 'closed';
        }
    }

    if ($status === 'open' || $status === 'extended') {
        $now = new DateTime();
        $end = new DateTime($row['end_date']);
        if ($now > $end && $status !== 'extended') {
            return 'closed';
        }
    }

    return $status;
}

function get_deadline(string $schoolYear, string $semester, string $gradingPeriod): ?array {
    $conn = db_connect();
    if (!$conn) return null;

    $stmt = $conn->prepare("SELECT id, school_year, semester, grading_period, start_date, end_date, status, extended_until, created_at, updated_at
        FROM submission_deadlines
        WHERE school_year = ? AND semester = ? AND grading_period = ? LIMIT 1");
    if (!$stmt) { $conn->close(); return null; }

    $stmt->bind_param('sss', $schoolYear, $semester, $gradingPeriod);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    $conn->close();

    return $row ?: null;
}

function set_deadline(string $schoolYear, string $semester, string $gradingPeriod, ?string $startDate, ?string $endDate, string $status, ?string $extendedUntil = null): bool {
    $conn = db_connect();
    if (!$conn) return false;

    $schoolYear = normalize_school_year($schoolYear);
    $semester = normalize_semester($semester);
    $gradingPeriod = strtolower(trim($gradingPeriod));
    if (!in_array($gradingPeriod, ['prelim', 'midterm', 'finals'], true)) {
        $conn->close();
        return false;
    }

    $check = $conn->prepare("SELECT id FROM submission_deadlines WHERE school_year = ? AND semester = ? AND grading_period = ? LIMIT 1");
    if (!$check) { $conn->close(); return false; }
    $check->bind_param('sss', $schoolYear, $semester, $gradingPeriod);
    $check->execute();
    $res = $check->get_result();
    $exists = $res && $res->fetch_assoc();
    $check->close();

    if ($exists) {
        $stmt = $conn->prepare("UPDATE submission_deadlines SET start_date = ?, end_date = ?, status = ?, extended_until = ?, updated_at = NOW()
            WHERE school_year = ? AND semester = ? AND grading_period = ?");
        if (!$stmt) { $conn->close(); return false; }
        $stmt->bind_param('sssssss', $startDate, $endDate, $status, $extendedUntil, $schoolYear, $semester, $gradingPeriod);
        $ok = $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare("INSERT INTO submission_deadlines (school_year, semester, grading_period, start_date, end_date, status, extended_until, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
        if (!$stmt) { $conn->close(); return false; }
        $stmt->bind_param('sssssss', $schoolYear, $semester, $gradingPeriod, $startDate, $endDate, $status, $extendedUntil);
        $ok = $stmt->execute();
        $stmt->close();
    }

    $conn->close();
    return (bool)$ok;
}

function has_teacher_submitted_grades(int $teacherId, string $schoolYear, string $semester, string $gradingPeriod): bool {
    $conn = db_connect();
    if (!$conn) return false;

    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM grades WHERE teacher_id = ? AND school_year = ? AND semester = ? AND {$gradingPeriod} IS NOT NULL LIMIT 1");
    if (!$stmt) { $conn->close(); return false; }

    $stmt->bind_param('iss', $teacherId, $schoolYear, $semester);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    $conn->close();

    return $row ? ((int)$row['cnt'] > 0) : false;
}

function is_period_open_for_teacher(int $teacherId, string $schoolYear, string $semester, string $gradingPeriod): bool {
    $conn = db_connect();
    if (!$conn) return false;

    $stmt = $conn->prepare("SELECT id FROM permission_grants
        WHERE teacher_id = ? AND school_year = ? AND semester = ? AND grading_period = ?
            AND is_active = 1 AND (expires_at IS NULL OR expires_at > NOW())
        LIMIT 1");
    if (!$stmt) { $conn->close(); return false; }

    $stmt->bind_param('isss', $teacherId, $schoolYear, $semester, $gradingPeriod);
    $stmt->execute();
    $res = $stmt->get_result();
    $hasGrant = $res && $res->fetch_assoc();
    $stmt->close();
    $conn->close();

    if ($hasGrant) return true;

    $status = get_deadline_status($schoolYear, $semester, $gradingPeriod);
    if ($status === 'open' || $status === 'extended') {
        return !has_teacher_submitted_grades($teacherId, $schoolYear, $semester, $gradingPeriod);
    }

    return false;
}

function create_notification(int $userId, string $userRole, string $title, string $message, string $type = 'info', ?string $link = null): bool {
    $conn = db_connect();
    if (!$conn) return false;

    $stmt = $conn->prepare("INSERT INTO notifications (user_id, user_role, title, message, type, link)
        VALUES (?, ?, ?, ?, ?, ?)");
    if (!$stmt) { $conn->close(); return false; }

    $stmt->bind_param('isssss', $userId, $userRole, $title, $message, $type, $link);
    $ok = $stmt->execute();
    $stmt->close();
    $conn->close();
    return (bool)$ok;
}

function get_teacher_notifications(int $teacherId): array {
    $conn = db_connect();
    if (!$conn) return [];

    $stmt = $conn->prepare("SELECT id, title, message, type, link, is_read, created_at
        FROM notifications
        WHERE user_id = ? AND user_role = 'teacher'
        ORDER BY created_at DESC
        LIMIT 50");
    if (!$stmt) { $conn->close(); return []; }

    $stmt->bind_param('i', $teacherId);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    $conn->close();
    return $rows;
}

function save_grade_change_request(int $teacherId, int $sectionId, int $subjectId, string $schoolYear, string $semester, string $gradingPeriod, string $reason): ?int {
    $conn = db_connect();
    if (!$conn) return null;

    $stmt = $conn->prepare("INSERT INTO grade_change_requests (teacher_id, section_id, subject_id, school_year, semester, grading_period, reason, status, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())");
    if (!$stmt) { $conn->close(); return null; }

    $stmt->bind_param('iiissss', $teacherId, $sectionId, $subjectId, $schoolYear, $semester, $gradingPeriod, $reason);
    $ok = $stmt->execute();
    $requestId = $conn->insert_id;
    $stmt->close();
    $conn->close();

    if ($ok && $requestId) {
        $teacher = db_query("SELECT first_name, middle_name, last_name FROM teachers WHERE id = " . intval($teacherId) . " LIMIT 1");
        $teacherName = $teacher ? ($teacher->fetch_assoc()['first_name'] ?? '') : '';
        audit_log('grade_change_request_submitted', $teacherId, $gradingPeriod, $schoolYear, $semester, $requestId, $teacherId, $subjectId, $sectionId);
        create_notification(0, 'admin', 'New Grade Change Request', "Teacher {$teacherName} requested permission for {$gradingPeriod} grades in {$schoolYear} {$semester}.", 'info', 'grade_requests.php');
    }

    return $ok ? $requestId : null;
}

function update_grade_change_request_status(int $requestId, string $status, ?int $adminId, ?string $adminResponse, ?string $expiresAt = null): bool {
    $conn = db_connect();
    if (!$conn) return false;

    $setParts = ["status = ?", "admin_id = ?", "admin_response = ?", "updated_at = NOW()"];
    $types = 'sis';
    $vals = [$status, $adminId, $adminResponse];

    if ($expiresAt !== null) {
        $setParts[] = "expires_at = ?";
        $types .= 's';
        $vals[] = $expiresAt;
    }

    $sql = "UPDATE grade_change_requests SET " . implode(', ', $setParts) . " WHERE id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) { $conn->close(); return false; }

    $vals[] = $requestId;
    $types .= 'i';
    $stmt->bind_param($types, ...$vals);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok) {
        $req = db_query("SELECT teacher_id, school_year, semester, grading_period FROM grade_change_requests WHERE id = " . intval($requestId) . " LIMIT 1");
        if ($req && $row = $req->fetch_assoc()) {
            $teacherId = (int)($row['teacher_id'] ?? 0);
            $schoolYear = $row['school_year'] ?? '';
            $semester = $row['semester'] ?? '';
            $gradingPeriod = $row['grading_period'] ?? '';

            if ($status === 'approved') {
                if ($expiresAt) {
                    $grant = $conn->prepare("INSERT INTO permission_grants (teacher_id, school_year, semester, grading_period, expires_at, is_active)
                        VALUES (?, ?, ?, ?, ?, 1)");
                    if ($grant) {
                        $grant->bind_param('issss', $teacherId, $schoolYear, $semester, $gradingPeriod, $expiresAt);
                        $grant->execute();
                        $grant->close();
                    }
                }

                $expiresLabel = $expiresAt ? (' until ' . date('M d, Y h:i A', strtotime($expiresAt))) : 'permanently';
                create_notification($teacherId, 'teacher', 'Request Approved',
                    "Your {$gradingPeriod} grade editing request for {$schoolYear} {$semester} has been approved ({$expiresLabel}).", 'success');
            } else {
                create_notification($teacherId, 'teacher', 'Request Rejected',
                    "Your {$gradingPeriod} grade editing request for {$schoolYear} {$semester} has been rejected.", 'error');
            }

            audit_log('grade_change_request_' . $status, $adminId ?? 0, $gradingPeriod, $schoolYear, $semester, $requestId, $teacherId, null, null);
        }
        if ($req) $req->free();
    }

    $conn->close();
    return (bool)$ok;
}

function revoke_expired_permissions(): bool {
    $conn = db_connect();
    if (!$conn) return false;

    $stmt = $conn->prepare("UPDATE permission_grants SET is_active = 0, revoked_at = NOW()
        WHERE is_active = 1 AND expires_at IS NOT NULL AND expires_at <= NOW()");
    if (!$stmt) { $conn->close(); return false; }
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok) {
        $revoked = $conn->affected_rows;
        if ($revoked > 0) {
            $res = $conn->query("SELECT teacher_id, school_year, semester, grading_period FROM permission_grants
                WHERE is_active = 0 AND revoked_at >= NOW() - INTERVAL 1 MINUTE LIMIT 100");
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $teacherId = (int)($row['teacher_id'] ?? 0);
                    $gradingPeriod = $row['grading_period'] ?? '';
                    $schoolYear = $row['school_year'] ?? '';
                    $semester = $row['semester'] ?? '';
                    create_notification($teacherId, 'teacher', 'Permission Expired',
                        "Your temporary editing permission for {$gradingPeriod} ({$schoolYear} {$semester}) has expired.", 'warning');
                    audit_log('permission_expired', $teacherId, $gradingPeriod, $schoolYear, $semester, null, $teacherId, null, null);
                }
                $res->free();
            }
        }
    }

    $conn->close();
    return (bool)$ok;
}

function create_teacher(string $firstName, ?string $middleName, string $lastName, ?string $department): array {
    $conn = db_connect();
    if (!$conn) return ['success' => false, 'error' => 'Database connection failed.'];

    // First insert the identity record.
    $stmt = $conn->prepare("INSERT INTO teachers (first_name, middle_name, last_name, department) VALUES (?, ?, ?, ?)");
    if (!$stmt) { $conn->close(); return ['success' => false, 'error' => $conn->error]; }

    $stmt->bind_param('ssss', $firstName, $middleName, $lastName, $department);
    $ok = $stmt->execute();
    $id = $conn->insert_id;
    $stmt->close();

    if (! $ok || ! $id) {
        $conn->close();
        return ['success' => false, 'error' => 'Failed to create teacher.'];
    }

    // Standardized username format for teacher login: T-000, T-001, ...
    $padded = str_pad((string)$id, 3, '0', STR_PAD_LEFT);
    $teacherId = 'T-' . $padded;

    // Temporary password: keep deterministic and simple (lastName + id base).
    // Admin should share this as-is.
    $tempPassword = generate_password($lastName, (int)$id);

    $hash = password_hash($tempPassword, PASSWORD_DEFAULT);

    $upd = $conn->prepare("UPDATE teachers SET teacher_id = ?, password_hash = ? WHERE id = ?");
    if (! $upd) {
        $conn->close();
        return ['success' => false, 'error' => $conn->error];
    }
    $upd->bind_param('ssi', $teacherId, $hash, $id);
    $updOk = $upd->execute();
    $upd->close();

    $conn->close();

    if ($updOk) {
        return [
            'success' => true,
            'id' => (int)$id,
            'teacher_id' => $teacherId,
            'temp_password' => $tempPassword,
        ];
    }

    return ['success' => false, 'error' => 'Failed to finalize teacher login credentials.'];
}


function update_teacher(int $teacherId, string $firstName, ?string $middleName, string $lastName, ?string $department, ?string $email): bool {
    $conn = db_connect();
    if (!$conn) return false;

    $stmt = $conn->prepare("UPDATE teachers SET first_name = ?, middle_name = ?, last_name = ?, department = ? WHERE id = ?");
    if (!$stmt) { $conn->close(); return false; }

    $stmt->bind_param('ssssi', $firstName, $middleName, $lastName, $department, $teacherId);
    $ok = $stmt->execute();
    $stmt->close();
    $conn->close();

    return (bool)$ok;
}

function delete_teacher(int $teacherId): bool {
    $conn = db_connect();
    if (!$conn) return false;

    $stmt = $conn->prepare("DELETE FROM teachers WHERE id = ?");
    if (!$stmt) { $conn->close(); return false; }

    $stmt->bind_param('i', $teacherId);
    $ok = $stmt->execute();
    $stmt->close();
    $conn->close();

    return (bool)$ok;
}

function create_student(string $firstName, ?string $middleName, string $lastName, ?int $sectionId, ?string $department): array {
    $conn = db_connect();
    if (!$conn) return ['success' => false, 'error' => 'Database connection failed.'];

    $stmt = $conn->prepare("INSERT INTO students (first_name, middle_name, last_name, section_id, department) VALUES (?, ?, ?, ?, ?)");
    if (!$stmt) { $conn->close(); return ['success' => false, 'error' => $conn->error]; }

    $stmt->bind_param('sssis', $firstName, $middleName, $lastName, $sectionId, $department);
    $ok = $stmt->execute();
    $id = $conn->insert_id;
    $stmt->close();

    if ($ok && $id) {
        $upd = $conn->prepare("UPDATE students SET student_id = ? WHERE id = ?");
        if ($upd) {
            $generatedId = 'S-' . sprintf('%03d', $id);
            $upd->bind_param('si', $generatedId, $id);
            $upd->execute();
            $upd->close();
        }

        $tempPassword = generate_password($lastName, (int)$id);
        $hash = password_hash($tempPassword, PASSWORD_DEFAULT);
        $pwdUpd = $conn->prepare("UPDATE students SET password_hash = ? WHERE id = ?");
        if ($pwdUpd) {
            $pwdUpd->bind_param('si', $hash, $id);
            $pwdUpd->execute();
            $pwdUpd->close();
        }

        $conn->close();

        return [
            'success' => true,
            'id' => (int)$id,
            'student_id' => $generatedId,
            'temp_password' => $tempPassword,
        ];
    }

    $conn->close();
    return ['success' => false, 'error' => 'Failed to create student.'];
}

function update_student(int $studentId, string $firstName, ?string $middleName, string $lastName, ?int $sectionId, ?string $department, ?string $email): bool {
    $conn = db_connect();
    if (!$conn) return false;

    $stmt = $conn->prepare("UPDATE students SET first_name = ?, middle_name = ?, last_name = ?, section_id = ?, department = ? WHERE id = ?");
    if (!$stmt) { $conn->close(); return false; }

    $stmt->bind_param('sssisi', $firstName, $middleName, $lastName, $sectionId, $department, $studentId);
    $ok = $stmt->execute();
    $stmt->close();
    $conn->close();

    return (bool)$ok;
}

function delete_subject(int $subjectId): bool {
    $conn = db_connect();
    if (!$conn) return false;

    $st = $conn->prepare("SELECT COUNT(*) as cnt FROM assignments WHERE subject_id = ? UNION SELECT COUNT(*) as cnt FROM grades WHERE subject_id = ?");
    if (!$st) { $conn->close(); return false; }

    $st->bind_param('ii', $subjectId, $subjectId);
    $st->execute();
    $res = $st->get_result();
    $count = 0;
    while ($r = $res->fetch_assoc()) {
        $count += (int)($r['cnt'] ?? 0);
    }
    $st->close();

    if ($count > 0) {
        $conn->close();
        return false;
    }

    $st = $conn->prepare("DELETE FROM subjects WHERE id = ? LIMIT 1");
    if (!$st) { $conn->close(); return false; }

    $st->bind_param('i', $subjectId);
    $ok = $st->execute();
    $st->close();
    $conn->close();

    return (bool)$ok;
}

function delete_student(int $studentId): bool {
    $conn = db_connect();
    if (!$conn) return false;

    $stmt = $conn->prepare("DELETE FROM students WHERE id = ?");
    if (!$stmt) { $conn->close(); return false; }

    $stmt->bind_param('i', $studentId);
    $ok = $stmt->execute();
    $stmt->close();
    $conn->close();

    return (bool)$ok;
}

function get_grade_viewing_schedules(string $schoolYear = '', string $semester = ''): array {
    $conn = db_connect();
    if (!$conn) return [];

    $sql = "SELECT id, school_year, semester, start_date, end_date, is_active, created_at, updated_at FROM grade_viewing_schedules WHERE 1=1";
    $params = '';
    $vals = [];

    if ($schoolYear) {
        $sql .= " AND school_year = ?";
        $params .= 's';
        $vals[] = $schoolYear;
    }
    if ($semester) {
        $sql .= " AND semester = ?";
        $params .= 's';
        $vals[] = $semester;
    }

    $sql .= " ORDER BY school_year DESC, semester DESC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) { $conn->close(); return []; }
    if ($params) {
        $stmt->bind_param($params, ...$vals);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    $conn->close();
    return $rows;
}

function get_grade_viewing_schedule(string $schoolYear, string $semester): ?array {
    $conn = db_connect();
    if (!$conn) return null;

    $stmt = $conn->prepare("SELECT id, school_year, semester, start_date, end_date, is_active, created_at, updated_at FROM grade_viewing_schedules WHERE school_year = ? AND semester = ? LIMIT 1");
    if (!$stmt) { $conn->close(); return null; }

    $stmt->bind_param('ss', $schoolYear, $semester);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    $conn->close();
    return $row ?: null;
}

function is_grade_viewing_active(string $schoolYear, string $semester): bool {
    $conn = db_connect();
    if (!$conn) return false;

    $stmt = $conn->prepare("SELECT id, start_date, end_date, is_active FROM grade_viewing_schedules WHERE school_year = ? AND semester = ? AND is_active = 1 LIMIT 1");
    if (!$stmt) { $conn->close(); return false; }

    $stmt->bind_param('ss', $schoolYear, $semester);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    $conn->close();

    if (!$row) return false;

    $now = new DateTime();
    $start = new DateTime($row['start_date']);
    $end = new DateTime($row['end_date']);
    return $now >= $start && $now <= $end;
}

function save_grade_viewing_schedule(string $schoolYear, string $semester, string $startDate, string $endDate, bool $isActive): ?int {
    $conn = db_connect();
    if (!$conn) return null;

    $check = $conn->prepare("SELECT id FROM grade_viewing_schedules WHERE school_year = ? AND semester = ? LIMIT 1");
    if (!$check) { $conn->close(); return null; }
    $check->bind_param('ss', $schoolYear, $semester);
    $check->execute();
    $res = $check->get_result();
    $exists = $res && $res->fetch_assoc();
    $check->close();

    if ($exists) {
        $stmt = $conn->prepare("UPDATE grade_viewing_schedules SET start_date = ?, end_date = ?, is_active = ?, updated_at = NOW() WHERE school_year = ? AND semester = ?");
        if (!$stmt) { $conn->close(); return null; }
        $isActiveInt = $isActive ? 1 : 0;
        $stmt->bind_param('ssiss', $startDate, $endDate, $isActiveInt, $schoolYear, $semester);
        $ok = $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare("INSERT INTO grade_viewing_schedules (school_year, semester, start_date, end_date, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())");
        if (!$stmt) { $conn->close(); return null; }
        $isActiveInt = $isActive ? 1 : 0;
        $stmt->bind_param('ssssi', $schoolYear, $semester, $startDate, $endDate, $isActiveInt);
        $ok = $stmt->execute();
        $insertId = $conn->insert_id;
        $stmt->close();
        $conn->close();
        return $ok ? $insertId : null;
    }

    $conn->close();
    return (bool)$ok ? 0 : null;
}

function toggle_grade_viewing_schedule(int $id, bool $isActive): bool {
    $conn = db_connect();
    if (!$conn) return false;

    $stmt = $conn->prepare("UPDATE grade_viewing_schedules SET is_active = ?, updated_at = NOW() WHERE id = ?");
    if (!$stmt) { $conn->close(); return false; }
    $isActiveInt = $isActive ? 1 : 0;
    $stmt->bind_param('ii', $isActiveInt, $id);
    $ok = $stmt->execute();
    $stmt->close();
    $conn->close();
    return (bool)$ok;
}

function create_grade_access_request(int $studentId, string $schoolYear, string $semester, ?string $reason): ?int {
    $conn = db_connect();
    if (!$conn) return null;

    $stmt = $conn->prepare("INSERT INTO grade_access_requests (student_id, school_year, semester, reason, status, created_at, updated_at)
        VALUES (?, ?, ?, ?, 'pending', NOW(), NOW())");
    if (!$stmt) { $conn->close(); return null; }
    $stmt->bind_param('isss', $studentId, $schoolYear, $semester, $reason);
    $ok = $stmt->execute();
    $requestId = $conn->insert_id;
    $stmt->close();
    $conn->close();

    if ($ok && $requestId) {
        create_notification(0, 'admin', 'New Grade Access Request',
            "A student has requested grade access for {$schoolYear} {$semester}.", 'info', 'grade_requests.php');
    }

    return $ok ? $requestId : null;
}

function get_student_access_requests(int $studentId): array {
    $conn = db_connect();
    if (!$conn) return [];

    $stmt = $conn->prepare("SELECT id, school_year, semester, reason, status, admin_response, access_start, access_end, created_at, updated_at
        FROM grade_access_requests WHERE student_id = ? ORDER BY created_at DESC");
    if (!$stmt) { $conn->close(); return []; }

    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        if (($r['status'] ?? '') === 'approved' && $r['access_end']) {
            $now = new DateTime();
            $end = new DateTime($r['access_end']);
            if ($now > $end) {
                $r['status'] = 'expired';
            }
        }
        $rows[] = $r;
    }
    $stmt->close();
    $conn->close();
    return $rows;
}

function get_all_access_requests(array $filters = []): array {
    $conn = db_connect();
    if (!$conn) return [];

    $sql = "SELECT gar.id, gar.student_id, gar.school_year, gar.semester, gar.reason, gar.status, gar.admin_response, gar.access_start, gar.access_end, gar.created_at,
            s.first_name, s.middle_name, s.last_name, s.student_id, s.department,
            sec.name AS section_name
        FROM grade_access_requests gar
        LEFT JOIN students s ON gar.student_id = s.id
        LEFT JOIN sections sec ON s.section_id = sec.id
        WHERE 1=1";
    $params = '';
    $vals = [];

    if (!empty($filters['school_year'])) {
        $sql .= " AND gar.school_year = ?";
        $params .= 's';
        $vals[] = $filters['school_year'];
    }
    if (!empty($filters['semester'])) {
        $sql .= " AND gar.semester = ?";
        $params .= 's';
        $vals[] = $filters['semester'];
    }
    if (!empty($filters['department'])) {
        $sql .= " AND s.department = ?";
        $params .= 's';
        $vals[] = $filters['department'];
    }
    if (!empty($filters['status'])) {
        $sql .= " AND gar.status = ?";
        $params .= 's';
        $vals[] = $filters['status'];
    }
    if (!empty($filters['student_id'])) {
        $sql .= " AND gar.student_id = ?";
        $params .= 'i';
        $vals[] = $filters['student_id'];
    }

    $sql .= " ORDER BY gar.created_at DESC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) { $conn->close(); return []; }
    if ($params) {
        $stmt->bind_param($params, ...$vals);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $now = new DateTime();
        if (($r['status'] ?? '') === 'approved' && $r['access_end']) {
            $end = new DateTime($r['access_end']);
            if ($now > $end) {
                $r['status'] = 'expired';
            }
        }
        $rows[] = $r;
    }
    $stmt->close();
    $conn->close();
    return $rows;
}

function update_grade_access_request(int $requestId, string $status, ?int $adminId, ?string $adminResponse, ?string $accessStart = null, ?string $accessEnd = null): bool {
    $conn = db_connect();
    if (!$conn) return false;

    $setParts = ["status = ?", "admin_id = ?", "admin_response = ?", "updated_at = NOW()"];
    $types = 'sis';
    $vals = [$status, $adminId, $adminResponse];

    if ($accessStart !== null) {
        $setParts[] = "access_start = ?";
        $types .= 's';
        $vals[] = $accessStart;
    }
    if ($accessEnd !== null) {
        $setParts[] = "access_end = ?";
        $types .= 's';
        $vals[] = $accessEnd;
    }

    $sql = "UPDATE grade_access_requests SET " . implode(', ', $setParts) . " WHERE id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) { $conn->close(); return false; }

    $vals[] = $requestId;
    $types .= 'i';
    $stmt->bind_param($types, ...$vals);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok) {
        $req = db_query("SELECT student_id, school_year, semester FROM grade_access_requests WHERE id = " . intval($requestId) . " LIMIT 1");
        if ($req && $row = $req->fetch_assoc()) {
            $studentId = (int)($row['student_id'] ?? 0);
            $schoolYear = $row['school_year'] ?? '';
            $semester = $row['semester'] ?? '';

            if ($status === 'approved') {
                $accessLabel = $accessEnd ? (' until ' . date('M d, Y h:i A', strtotime($accessEnd))) : '';
                create_notification($studentId, 'student', 'Request Approved',
                    "Your grade access request for {$schoolYear} {$semester} has been approved ({$accessLabel}).", 'success', 'student_requests.php');
            } elseif ($status === 'rejected') {
                create_notification($studentId, 'student', 'Request Rejected',
                    "Your grade access request for {$schoolYear} {$semester} has been rejected.", 'error', 'student_requests.php');
            }
        }
        if ($req) $req->free();
    }

    $conn->close();
    return (bool)$ok;
}

function check_student_grade_access(int $studentId, string $schoolYear, string $semester): bool {
    if (is_grade_viewing_active($schoolYear, $semester)) {
        return true;
    }

    $conn = db_connect();
    if (!$conn) return false;

    $stmt = $conn->prepare("SELECT id, access_start, access_end, status FROM grade_access_requests
        WHERE student_id = ? AND school_year = ? AND semester = ? AND status = 'approved'
        ORDER BY created_at DESC LIMIT 1");
    if (!$stmt) { $conn->close(); return false; }

    $stmt->bind_param('iss', $studentId, $schoolYear, $semester);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    $conn->close();

    if (!$row) return false;

    $now = new DateTime();
    if ($row['access_start'] && $now < new DateTime($row['access_start'])) {
        return false;
    }
    if ($row['access_end'] && $now > new DateTime($row['access_end'])) {
        return false;
    }

    return true;
}

function get_student_notifications(int $studentId): array {
    $conn = db_connect();
    if (!$conn) return [];

    $stmt = $conn->prepare("SELECT id, title, message, type, link, is_read, created_at
        FROM notifications
        WHERE user_id = ? AND user_role = 'student'
        ORDER BY created_at DESC
        LIMIT 50");
    if (!$stmt) { $conn->close(); return []; }

    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    $conn->close();
    return $rows;
}

function get_student_unread_count(int $studentId): int {
    $conn = db_connect();
    if (!$conn) return 0;

    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM notifications WHERE user_id = ? AND user_role = 'student' AND is_read = 0");
    if (!$stmt) { $conn->close(); return 0; }

    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    $conn->close();
    return (int)($row['cnt'] ?? 0);
}

function change_user_password(int $userId, string $role, string $currentPassword, string $newPassword): array {
    $conn = db_connect();
    if (!$conn) return ['success' => false, 'error' => 'Database connection failed.'];

    $table = $role === 'teacher' ? 'teachers' : 'students';
    $idField = $role === 'teacher' ? 'id' : 'id';

    $stmt = $conn->prepare("SELECT id, password_hash, password FROM {$table} WHERE {$idField} = ? LIMIT 1");
    if (!$stmt) { $conn->close(); return ['success' => false, 'error' => 'Query failed.']; }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        $conn->close();
        return ['success' => false, 'error' => 'User not found.'];
    }

    $hash = $row['password_hash'] ?? '';
    $legacy = $row['password'] ?? '';
    $valid = false;

    if ($hash && password_verify($currentPassword, $hash)) {
        $valid = true;
    } elseif ($legacy && $legacy === $currentPassword) {
        $valid = true;
    }

    if (!$valid) {
        $conn->close();
        return ['success' => false, 'error' => 'Current password is incorrect.'];
    }

    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $upd = $conn->prepare("UPDATE {$table} SET password_hash = ? WHERE {$idField} = ?");
    if (!$upd) { $conn->close(); return ['success' => false, 'error' => 'Update failed.']; }

    $upd->bind_param('si', $newHash, $userId);
    $ok = $upd->execute();
    $upd->close();
    $conn->close();

    if ($ok) {
        audit_log('change_password', $userId);
        if ($role === 'student') {
            create_notification($userId, 'student', 'Password Changed', 'Your password has been changed successfully.', 'success', 'student_settings.php');
        } else {
            create_notification($userId, 'teacher', 'Password Changed', 'Your password has been changed successfully.', 'success', 'teacher_settings.php');
        }
        return ['success' => true];
    }

    return ['success' => false, 'error' => 'Failed to update password.'];
}

function save_teacher_profile_picture(int $teacherId, array $file): array {
    $conn = db_connect();
    if (!$conn) return ['success' => false, 'error' => 'Database connection failed.'];

    $uploadDir = __DIR__ . '/../uploads/teachers/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'File upload error.'];
    }

    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $fileType = $file['type'] ?? '';
    if (!in_array($fileType, $allowedTypes, true)) {
        return ['success' => false, 'error' => 'Invalid file type. Only JPG, PNG, GIF, and WebP are allowed.'];
    }

    $maxSize = 2 * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        return ['success' => false, 'error' => 'File size must be less than 2MB.'];
    }

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $newFilename = 'teacher_' . $teacherId . '_' . time() . '.' . $ext;
    $destination = $uploadDir . $newFilename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        return ['success' => false, 'error' => 'Failed to save uploaded file.'];
    }

    $old = $conn->query("SELECT profile_picture FROM teachers WHERE id = " . intval($teacherId) . " LIMIT 1");
    $oldRow = $old ? $old->fetch_assoc() : null;
    if ($old) $old->free();

    $stmt = $conn->prepare("UPDATE teachers SET profile_picture = ? WHERE id = ?");
    if (!$stmt) { $conn->close(); return ['success' => false, 'error' => 'Database update failed.']; }

    $stmt->bind_param('si', $newFilename, $teacherId);
    $ok = $stmt->execute();
    $stmt->close();

    if ($oldRow && $oldRow['profile_picture'] && $oldRow['profile_picture'] !== $newFilename) {
        $oldPath = $uploadDir . $oldRow['profile_picture'];
        if (file_exists($oldPath)) unlink($oldPath);
    }

    $conn->close();

    if ($ok) {
        create_notification($teacherId, 'teacher', 'Profile Picture Updated', 'Your profile picture has been updated successfully.', 'success', 'teacher_settings.php');
        return ['success' => true, 'filename' => $newFilename];
    }

    return ['success' => false, 'error' => 'Failed to update database.'];
}

function delete_teacher_profile_picture(int $teacherId): bool {
    $conn = db_connect();
    if (!$conn) return false;

    $stmt = $conn->prepare("SELECT profile_picture FROM teachers WHERE id = ? LIMIT 1");
    if (!$stmt) { $conn->close(); return false; }
    $stmt->bind_param('i', $teacherId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row || empty($row['profile_picture'])) {
        $conn->close();
        return false;
    }

    $upd = $conn->prepare("UPDATE teachers SET profile_picture = NULL WHERE id = ?");
    if (!$upd) { $conn->close(); return false; }
    $upd->bind_param('i', $teacherId);
    $ok = $upd->execute();
    $upd->close();

    $uploadDir = __DIR__ . '/../uploads/teachers/';
    $oldPath = $uploadDir . $row['profile_picture'];
    if (file_exists($oldPath)) unlink($oldPath);

    $conn->close();
    return (bool)$ok;
}

function revoke_expired_access_grants(): bool {
    $conn = db_connect();
    if (!$conn) return false;

    $now = (new DateTime())->format('Y-m-d H:i:s');

    $stmt = $conn->prepare("UPDATE grade_access_requests SET status = 'expired', updated_at = NOW()
        WHERE status = 'approved' AND access_end IS NOT NULL AND access_end <= ?");
    if (!$stmt) { $conn->close(); return false; }
    $stmt->bind_param('s', $now);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok && $conn->affected_rows > 0) {
        $res = $conn->query("SELECT gar.id, gar.student_id, gar.school_year, gar.semester
            FROM grade_access_requests gar
            WHERE gar.status = 'expired' AND gar.updated_at >= NOW() - INTERVAL 1 MINUTE
            LIMIT 100");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $sid = (int)($row['student_id'] ?? 0);
                $sy = $row['school_year'] ?? '';
                $sem = $row['semester'] ?? '';
                if ($sid) {
                    create_notification($sid, 'student', 'Grade Access Expired',
                        "Your grade access for {$sy} {$sem} has expired.", 'warning', 'student_requests.php');
                }
            }
            $res->free();
        }
    }

    $conn->close();
    return (bool)$ok;
}

function get_teacher_profile(int $teacherId): array {
    $conn = db_connect();
    if (!$conn) return ['name' => '', 'profile_picture' => null, 'department' => '', 'role' => 'Teacher'];

    $stmt = $conn->prepare('SELECT teacher_id, name, profile_picture, department FROM teachers WHERE id = ? LIMIT 1');
    if (!$stmt) { $conn->close(); return ['name' => '', 'profile_picture' => null, 'department' => '', 'role' => 'Teacher', 'teacher_id' => '']; }

    $stmt->bind_param('i', $teacherId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    $conn->close();

    if (!$row) return ['name' => '', 'profile_picture' => null, 'department' => '', 'role' => 'Teacher', 'teacher_id' => ''];

    return [
        'name' => $row['name'] ?? '',
        'profile_picture' => $row['profile_picture'] ?? null,
        'department' => $row['department'] ?? '',
        'role' => 'Teacher',
        'teacher_id' => $row['teacher_id'] ?? '',
    ];
}

function get_student_profile(int $studentId): array {
    $conn = db_connect();
    if (!$conn) return ['name' => '', 'section_id' => 0, 'role' => 'Student'];

    $stmt = $conn->prepare('SELECT first_name, middle_name, last_name, section_id FROM students WHERE id = ? LIMIT 1');
    if (!$stmt) { $conn->close(); return ['name' => '', 'section_id' => 0, 'role' => 'Student']; }

    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    $conn->close();

    if (!$row) return ['name' => '', 'section_id' => 0, 'role' => 'Student'];

    $fullName = trim(($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
    $fullName = preg_replace('/\s+/', ' ', $fullName);

    return [
        'name' => $fullName,
        'section_id' => (int)($row['section_id'] ?? 0),
        'role' => 'Student',
    ];
}

