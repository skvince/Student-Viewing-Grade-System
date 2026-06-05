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
    // audit_logs schema uses column `ip` and `created_at` defaults to CURRENT_TIMESTAMP.
    $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, action, ip) VALUES (?, ?, ?)");
    if (!$stmt) { $conn->close(); return false; }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmt->bind_param('iss', $user_id, $action, $ip);
    $ok = $stmt->execute();
    $stmt->close();
    $conn->close();
    return (bool) $ok;
}
