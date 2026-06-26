<?php
require_once __DIR__ . '/functions.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$role = $_SESSION['user_role'] ?? 'guest';
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

$activeNav = $activeNav ?? 'dashboard';
$term = get_global_term();
$schoolYear = $term['year'] ?? '';
$semester   = $term['semester'] ?? '';

$profileName = '';
$profilePicture = null;
$profileDepartment = '';
$profileRole = ucfirst($role);

if ($role === 'teacher' && $userId) {
    $p = get_teacher_profile($userId);
    $profileName = $p['name'] ?? '';
    $profilePicture = $p['profile_picture'] ?? null;
    $profileDepartment = $p['department'] ?? '';
    $profileRole = $p['role'] ?? 'Teacher';
} elseif ($role === 'student' && $userId) {
    $p = get_student_profile($userId);
    $profileName = $p['name'] ?? '';
    $profileRole = $p['role'] ?? 'Student';
} elseif ($role === 'admin') {
    $profileRole = 'Administrator';
    $profileName = 'Admin';
}

$pageTitle = $pageTitle ?? 'CSCQC Portal';
$classesContent    = $classesContent ?? '';
$requestsContent   = $requestsContent ?? '';
$settingsContent   = $settingsContent ?? '';
$content           = $content ?? '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($pageTitle); ?> - CSCQC</title>
<link rel="icon" type="image/png" href="https://cscqcph.com/images/bg/cscqcph.png"/>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
:root{--bg-color:#f1f4f2;--sidebar-bg:#fff;--text-color:#333;--text-muted:#666;--primary-green:#0e4429;--primary-green-hover:#165c39;--light-green-bg:#d8ebd4;--active-nav-bg:#b9deb3;--border-color:#e5e7eb;--card-bg:#fff}
*{box-sizing:border-box;margin:0;padding:0;font-family:"Inter",sans-serif}
body{background:var(--bg-color);color:var(--text-color);display:grid;grid-template-columns:260px 1fr;min-height:100vh;position:relative}
body::before{content:'';position:fixed;inset:0;background-image:url('https://cscqcph.com/images/bg/cscqcph.png');background-repeat:no-repeat;background-position:center;background-size:contain;opacity:.04;pointer-events:none;z-index:0}
#sidebar-toggle{display:none}
.mobile-header{display:none;background:var(--sidebar-bg);border-bottom:1px solid var(--border-color);padding:12px 20px;align-items:center;justify-content:space-between;position:fixed;top:0;left:0;right:0;z-index:100;height:60px}
.menu-toggle-btn{font-size:1.3rem;color:var(--primary-green);cursor:pointer;padding:4px 8px}
.sidebar{background:var(--sidebar-bg);border-right:1px solid var(--border-color);display:flex;flex-direction:column;height:100vh;position:sticky;top:0;z-index:90;width:260px;min-width:260px}
.sidebar-header{display:flex;flex-direction:column;align-items:center;padding:24px 20px 20px;text-align:center;border-bottom:1px solid var(--border-color)}
.sidebar-profile-img{width:88px;height:88px;border-radius:50%;object-fit:cover;border:3px solid var(--border-color);background:#f3f4f6;margin-bottom:12px;box-shadow:0 2px 8px rgba(0,0,0,.08)}
.sidebar-profile-icon{width:88px;height:88px;border-radius:50%;background:var(--light-green-bg);color:var(--primary-green);display:flex;align-items:center;justify-content:center;font-size:2.2rem;margin-bottom:12px;border:3px solid var(--border-color);box-shadow:0 2px 8px rgba(0,0,0,.08)}
.sidebar-profile-icon.admin-profile-icon{background:#fff;overflow:hidden}
.sidebar-profile-name{font-size:1rem;font-weight:700;color:#111;word-break:break-word;line-height:1.3;margin-bottom:2px}
.sidebar-profile-role{font-size:.8rem;font-weight:700;color:var(--primary-green);text-transform:uppercase;letter-spacing:.6px;margin-bottom:2px}
.sidebar-profile-dept{font-size:.75rem;color:var(--text-muted);font-weight:500}
.sidebar-divider{height:1px;background:var(--border-color);margin:0 20px}
.sidebar-nav{flex:1;display:flex;flex-direction:column;padding:16px 12px;overflow-y:auto}
.nav-menu{list-style:none;display:flex;flex-direction:column;gap:4px;width:100%}
.nav-link{display:flex;align-items:center;gap:12px;padding:12px 16px;color:var(--text-muted);font-size:.9rem;font-weight:500;text-decoration:none;border-radius:8px;transition:all .2s ease;border-left:3px solid transparent;min-height:44px}
.nav-link i{font-size:1.05rem;width:20px;text-align:center;flex-shrink:0;color:inherit}
.nav-link:hover{background:#f9fafb;color:var(--primary-green);border-left-color:var(--primary-green)}
.nav-link:hover i{color:var(--primary-green)}
.nav-link.active{background:var(--active-nav-bg);color:var(--primary-green);font-weight:700;border-left-color:var(--primary-green);box-shadow:inset 0 0 0 1px rgba(14,68,41,.08)}
.nav-link.active i{color:var(--primary-green)}
.sidebar-footer{padding:16px 20px;border-top:1px solid var(--border-color)}
.logout-btn{width:100%;padding:12px;background:transparent;color:var(--primary-green);border:1px solid var(--border-color);border-radius:8px;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;font-size:.9rem;transition:all .2s;text-decoration:none}
.logout-btn:hover{background:var(--primary-green);color:#fff;border-color:var(--primary-green)}
.main-content{padding:40px;min-width:0;overflow-y:auto;position:relative;z-index:1}
.view-title{font-size:1.25rem;color:var(--primary-green);font-weight:600;margin-bottom:4px;text-transform:uppercase;letter-spacing:.5px}
.view-subtitle{font-size:.85rem;color:var(--text-muted);margin-bottom:24px}
.global-term-container{display:flex;align-items:center;gap:24px;background:#0c3e21;padding:16px 24px;border-radius:12px;margin-bottom:24px;box-shadow:0 4px 6px -1px rgba(0,0,0,.1);flex-wrap:wrap}
.filter-group{display:flex;align-items:center;gap:12px}
.global-term-container label{color:#fff;font-size:.9rem;font-weight:700;display:flex;align-items:center;gap:8px;white-space:nowrap}
.global-term-container label i{font-size:1rem}
.global-select{padding:8px 14px;border-radius:6px;border:1px solid transparent;font-size:.875rem;background:#fff;color:#1f2937;font-weight:500;cursor:pointer;outline:none;min-width:140px;transition:border-color .2s,box-shadow .2s}
.global-select:focus{border-color:#22c55e;box-shadow:0 0 0 3px rgba(34,197,94,.2)}
.panel-block{background:var(--card-bg);border-radius:8px;padding:24px;box-shadow:0 1px 3px rgba(0,0,0,.05);margin-bottom:24px}
.block-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;gap:16px;flex-wrap:wrap}
.block-title{font-size:1rem;color:var(--primary-green);font-weight:600}
.header-actions{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.table-responsive{width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch;border:1px solid var(--border-color);border-radius:6px;background:#fff}
table{width:100%;border-collapse:collapse;text-align:left;font-size:.85rem;min-width:650px}
th{color:#4b5563;background:#f9fafb;font-weight:600;text-transform:uppercase;font-size:.75rem;padding:12px 16px;border-bottom:1px solid var(--border-color)}
td{padding:14px 16px;color:#1f2937;border-bottom:1px solid #f3f4f6;vertical-align:middle}
th:last-child,.actions-cell{text-align:center;width:100px;min-width:100px}
.actions-cell{white-space:nowrap}
.actions-cell i{font-size:1.15rem;cursor:pointer;margin:0 6px;display:inline-block;transition:transform .2s ease}
.actions-cell i:hover{transform:scale(1.18)}
.fa-pen-to-square{color:#10b981}
.fa-trash-can{color:#ef4444}
.icon-button{background:transparent;border:none;cursor:pointer;padding:6px;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;transition:background .2s}
.icon-button:hover{background:#f3f4f6}
.toggle-password-btn{background:transparent;border:none;cursor:pointer;padding:4px 8px;border-radius:4px;font-size:.8rem;color:#374151}
.toggle-password-btn:hover{background:transparent;color:var(--primary-green)}
.btn-action{background:#fff;color:var(--primary-green);border:1px solid var(--border-color);padding:6px 14px;border-radius:6px;font-size:.8rem;font-weight:500;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .2s;white-space:nowrap}
.btn-action:hover{background:var(--primary-green);color:#fff;border-color:var(--primary-green)}
.btn-approve{background:#10b981;color:#fff;border:none;padding:8px 16px;border-radius:6px;font-size:.85rem;font-weight:500;cursor:pointer;display:inline-flex;align-items:center;gap:6px}
.btn-approve:hover{background:#059669}
.btn-reject{background:#ef4444;color:#fff;border:none;padding:8px 16px;border-radius:6px;font-size:.85rem;font-weight:500;cursor:pointer;display:inline-flex;align-items:center;gap:6px}
.btn-reject:hover{background:#dc2626}
.btn-close{background:#6b7280;color:#fff;border:none;padding:8px 16px;border-radius:6px;font-size:.85rem;font-weight:500;cursor:pointer;display:inline-flex;align-items:center;gap:6px}
.btn-close:hover{background:#4b5563}
.form-group{margin:10px 5px 20px}
.form-group label{display:block;font-size:.85rem;color:var(--text-muted);margin-bottom:8px;font-weight:500}
.form-control{width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:6px;background:#fff;font-size:.9rem;color:#1f2937;outline:none}
.form-control:focus{border-color:var(--primary-green);box-shadow:0 0 0 3px rgba(14,68,41,.15)}
.btn-submit{background:var(--primary-green);color:#fff;border:none;margin-bottom:8px;margin-left:7px;padding:10px 20px;border-radius:6px;font-size:.9rem;font-weight:500;cursor:pointer;display:flex;align-items:center;gap:6px}
.btn-submit:hover{background:var(--primary-green-hover)}
.form-buttons-row{display:flex;gap:12px;align-items:center}
.btn-cancel{background:#e5e7eb;color:#374151;border:none;padding:10px 20px;border-radius:6px;font-size:.9rem;font-weight:500;cursor:pointer}
.modal-backdrop{position:fixed;inset:0;display:none;align-items:center;justify-content:center;background:rgba(15,23,42,.4);backdrop-filter:blur(10px);z-index:999;padding:24px}
.modal-card{background:#fff;border:1px solid #d1d5db;border-radius:18px;padding:24px;box-shadow:0 8px 24px rgba(15,23,42,.08);position:relative;max-width:520px;width:100%}
.modal-close{position:absolute;top:18px;right:18px;background:0 0;border:none;color:#6b7280;font-size:1.1rem;cursor:pointer;padding:8px}
.status-badge{font-size:.72rem;font-weight:800;text-transform:uppercase;padding:4px 12px;border-radius:50px;display:inline-block;letter-spacing:.5px}
.badge-pass{background:#def7ec;color:#03543f}.badge-fail{background:#fde8e8;color:#9b1c1c}.badge-pending{background:#fef3c7;color:#92400e}.badge-approved{background:#d1fae5;color:#065f46}.badge-rejected{background:#fee2e2;color:#991b1b}.badge-closed{background:#e5e7eb;color:#374151}.badge-open{background:#d1fae5;color:#065f46}.badge-extended{background:#fef3c7;color:#92400e}
.alert{padding:12px 16px;border-radius:6px;margin-bottom:16px;font-size:.85rem}
.alert-error{background:#fee2e2;color:#991b1b}
.btn-add{background:var(--primary-green);color:#fff;border:none;padding:8px 16px;border-radius:6px;font-size:.85rem;font-weight:500;cursor:pointer;display:flex;align-items:center;gap:6px;white-space:nowrap;transition:background .2s}
.btn-add:hover{background:var(--primary-green-hover)}
.search-wrapper{position:relative;display:flex;align-items:center;margin-bottom:12px}
.search-wrapper i{position:absolute;left:12px;color:#9ca3af;font-size:.9rem;pointer-events:none}
.search-input{padding:8px 12px 8px 34px;border:1px solid #d1d5db;border-radius:6px;font-size:.85rem;color:#1f2937;width:220px;transition:all .2s}
.search-input:focus{outline:none;border-color:var(--primary-green);width:260px;box-shadow:0 0 0 3px rgba(14,68,41,.15)}
.grades-grid{display:grid;grid-template-columns:1fr;gap:16px}
.grade-row{display:grid;grid-template-columns:120px 1fr 100px 100px 100px 100px 80px;gap:12px;align-items:center;padding:12px 16px;background:#f9fafb;border-radius:8px;border:1px solid var(--border-color)}
.grade-row.header{background:#f3f4f6;font-weight:600;font-size:.75rem;text-transform:uppercase;color:#4b5563;border-bottom:2px solid var(--border-color)}
.student-id{font-weight:600;color:var(--primary-green);font-size:.9rem}
.student-name{font-weight:500;color:#1f2937}
.grade-input-wrapper input{width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:.85rem;text-align:center;background:#fff}
.grade-input-wrapper input:focus{outline:none;border-color:var(--primary-green);box-shadow:0 0 0 3px rgba(14,68,41,.15)}
.grade-input-wrapper input:disabled{background:#f3f4f6;color:#9ca3af;cursor:not-allowed}
.total-grade{font-weight:600;text-align:center;color:#1f2937}
.gwa-badge{font-weight:700;text-align:center;padding:4px 8px;border-radius:4px;font-size:.85rem}
.btn-back{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;background:var(--card-bg);border:1px solid var(--border-color);border-radius:6px;text-decoration:none;color:var(--text-color);font-size:.85rem;font-weight:500;margin-bottom:16px;transition:all .2s}
.btn-back:hover{background:#f9fafb;border-color:var(--primary-green);color:var(--primary-green)}
.sections-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:20px;margin-bottom:24px}
.section-card{background:var(--card-bg);border:1px solid var(--border-color);border-radius:12px;padding:24px;text-decoration:none;color:inherit;box-shadow:0 1px 3px rgba(0,0,0,.05);transition:all .2s;display:block}
.section-card:hover{border-color:var(--primary-green);box-shadow:0 4px 6px rgba(14,68,41,.1);transform:translateY(-2px)}
.section-card-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
.section-code{font-weight:700;font-size:1.1rem;color:var(--primary-green)}
.badge-subjects{background:var(--light-green-bg);color:var(--primary-green);padding:4px 10px;border-radius:50px;font-size:.75rem;font-weight:600}
.section-body{color:var(--text-muted);font-size:.9rem;margin-bottom:16px}
.section-card-footer{display:flex;justify-content:flex-end}
.btn-select{background:var(--primary-green);color:#fff;padding:6px 14px;border-radius:6px;font-size:.8rem;font-weight:600}
.subjects-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:20px;margin-bottom:24px}
.subject-card{background:var(--card-bg);border:1px solid var(--border-color);border-radius:12px;padding:24px;text-decoration:none;color:inherit;box-shadow:0 1px 3px rgba(0,0,0,.05);transition:all .2s;display:block}
.subject-card:hover{border-color:var(--primary-green);box-shadow:0 4px 6px rgba(14,68,41,.1);transform:translateY(-2px)}
.subject-card-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
.subject-code{font-weight:700;font-size:1rem;color:var(--primary-green)}
.subject-units{color:var(--text-muted);font-size:.8rem;font-weight:500}
.subject-body{margin-bottom:16px}
.subject-title{font-weight:500;color:#1f2937;font-size:.95rem}
.subject-card-footer{display:flex;justify-content:flex-end}
.empty-state{text-align:center;padding:40px;color:var(--text-muted);font-size:.95rem;background:var(--card-bg);border-radius:8px;border:1px solid var(--border-color)}
.card-action-row{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:8px}
.request-form-header{margin-bottom:16px}
.request-form-header h3{font-size:1rem;color:var(--primary-green);font-weight:600;margin:0 0 4px}
.request-form-header p{font-size:.85rem;color:var(--text-muted);margin:0}
#requests-table tbody tr{transition:background-color .15s ease}
#requests-table tbody tr:hover{background:#f9fafb}
.filter-select{padding:6px 32px 6px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:.85rem;color:#1f2937;background:#fff;outline:none;appearance:none;background-image:url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%234b5563' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");background-repeat:no-repeat;background-position:right 10px center;background-size:14px}
.filter-select:focus{border-color:var(--primary-green)}
.dashboard-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:20px;margin-bottom:28px}
.dash-card{background:#fff;border:1px solid var(--border-color);border-radius:12px;padding:22px;box-shadow:0 1px 3px rgba(0,0,0,.06);transition:transform .2s ease,box-shadow .2s ease;display:flex;align-items:center;gap:16px;position:relative;overflow:hidden}
.dash-card:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(0,0,0,.08)}
.dash-card-icon{width:48px;height:48px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.4rem;flex-shrink:0}
.dash-card-icon.teachers{background:#eef2ff;color:#4f46e5}
.dash-card-icon.students{background:#ecfdf5;color:#059669}
.dash-card-icon.assignments{background:#fff7ed;color:#ea580c}
.dash-card-body{min-width:0;flex:1}
.dash-card-label{font-size:.8rem;color:var(--text-muted);font-weight:500;margin-bottom:4px}
.dash-card-value{font-size:1.6rem;font-weight:700;color:#111;line-height:1.2}
.dash-card-action{position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:8px;border:1px solid var(--border-color);background:#fff;color:var(--primary-green);display:flex;align-items:center;justify-content:center;text-decoration:none;font-size:.9rem;transition:all .2s;cursor:pointer}
.dash-card-action:hover{background:var(--primary-green);color:#fff;border-color:var(--primary-green)}
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:20px;margin-bottom:24px}
.stats-table-wrap{overflow-x:auto;border:1px solid var(--border-color);border-radius:8px;background:#fff}
.stats-table{width:100%;border-collapse:collapse;font-size:.9rem;table-layout:auto}
.stats-table th{color:#4b5563;background:#f9fafb;font-weight:600;text-transform:uppercase;font-size:.72rem;padding:12px 14px;border-bottom:1px solid var(--border-color);text-align:left;white-space:nowrap}
.stats-table td{padding:12px 14px;color:#1f2937;border-bottom:1px solid #f3f4f6;vertical-align:middle;max-width:220px}
.stats-table tr:hover td{background:#f9fafb}
.stats-table .dept-cell{font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.progress-wrap{display:flex;align-items:center;gap:10px}
.progress-track{flex:1;height:8px;background:#e5e7eb;border-radius:4px;overflow:hidden}
.progress-bar{height:100%;background:var(--primary-green);border-radius:4px;transition:width .4s ease}
.progress-value{font-size:.8rem;color:var(--text-muted);min-width:42px;text-align:right}
.header-row{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px}
.section-title{font-size:1rem;color:var(--primary-green);font-weight:600}
.section-subtitle{font-size:.8rem;color:var(--text-muted)}
.badge{font-size:.7rem;font-weight:700;text-transform:uppercase;padding:4px 10px;border-radius:50px;display:inline-block;letter-spacing:.5px}
.chart-wrap{width:120px;height:120px;position:relative;flex-shrink:0}
.chart-center{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;font-weight:700;color:#111;line-height:1.2}
.activity-feed{display:flex;flex-direction:column;gap:0}
.activity-item{display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid #f3f4f6}
.activity-item:last-child{border-bottom:none}
.activity-icon{width:36px;height:36px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:.9rem;flex-shrink:0}
.activity-icon.deadline{background:#eff6ff;color:#2563eb}
.activity-icon.grade{background:#f0fdf4;color:#16a34a}
.activity-icon.system{background:#fefce8;color:#ca8a04}
.activity-body{min-width:0;flex:1}
.activity-title{font-size:.9rem;font-weight:600;color:#111;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.activity-meta{font-size:.8rem;color:var(--text-muted)}
.btn-request{background:var(--primary-green);color:#fff;border:none;padding:8px 16px;border-radius:6px;font-size:.85rem;font-weight:500;cursor:pointer;display:inline-flex;align-items:center;gap:6px}
.btn-request:hover{background:var(--primary-green-hover)}
.btn-save-all{background:var(--primary-green);color:#fff;border:none;padding:10px 20px;border-radius:6px;font-size:.9rem;font-weight:500;cursor:pointer;display:inline-flex;align-items:center;gap:6px}
.btn-save-all:hover{background:var(--primary-green-hover)}
.form-actions{display:flex;gap:12px;align-items:center}
.settings-grid{display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:24px}
.panel-title{font-size:1rem;color:var(--primary-green);font-weight:600;margin-bottom:12px}
.profile-picture-area{display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:16px}
.profile-picture-preview{width:100px;height:100px;border-radius:50%;object-fit:cover;border:2px solid var(--border-color);background:#f3f4f6}
.profile-picture-placeholder{width:100px;height:100px;border-radius:50%;background:#e5e7eb;display:flex;align-items:center;justify-content:center;font-size:2rem;color:#9ca3af}
.btn-danger{background:#dc2626;color:#fff;border:none;padding:8px 16px;border-radius:6px;font-size:.85rem;font-weight:500;cursor:pointer}
.btn-danger:hover{background:#b91c1c}
.alert-success{background:#d1fae5;color:#065f46}
.alert-info{background:#dbeafe;color:#1e40af}
.grid-3col{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
.grid-2col{display:grid;grid-template-columns:repeat(2,1fr);gap:16px}
.deadline-form-group{background:#f9fafb;border:1px solid var(--border-color);border-radius:8px;padding:20px}
.schedule-form-group{background:#f9fafb;border:1px solid var(--border-color);border-radius:8px;padding:20px}
.status-active{background:#d1fae5;color:#065f46}
.status-inactive{background:#fee2e2;color:#991b1b}
.summary-highlights-strip{background:var(--light-green-bg);border-radius:6px;padding:16px 24px;display:grid;grid-template-columns:repeat(4,1fr);gap:16px}
.metric-item-box p{font-size:.75rem;color:#4b5563;font-weight:500;margin-bottom:4px}
.metric-item-box data{font-size:1.35rem;font-weight:700;color:var(--primary-green);display:block}
.cards-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-bottom:30px}
.card{background:var(--light-green-bg);padding:24px;border-radius:8px}
.card-title{font-size:.85rem;color:#4b5563;font-weight:500;margin-bottom:8px}
.card-value{font-size:2.2rem;font-weight:700;color:var(--primary-green)}
@media(max-width:1024px){body{grid-template-columns:1fr;padding-top:60px}.mobile-header{display:flex}.sidebar{position:fixed;top:60px;left:0;bottom:0;transform:translateX(-100%);width:260px;height:calc(100vh - 60px);box-shadow:4px 0 10px rgba(0,0,0,.1);transition:transform .3s ease}#sidebar-toggle:checked~.sidebar{transform:translateX(0)}.main-content{padding:20px}.sidebar-profile-img,.sidebar-profile-icon{width:70px;height:70px;font-size:1.8rem}.grade-row{grid-template-columns:80px 1fr 70px 70px 70px 70px 60px;gap:8px;padding:10px;font-size:.8rem}.summary-highlights-strip{grid-template-columns:repeat(2,1fr)}.cards-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:768px){.header-actions{width:100%}.search-wrapper,.search-input{width:100%!important}.global-term-container{flex-direction:column;align-items:stretch;gap:12px}.filter-group{justify-content:space-between}.global-select{width:100%}.grade-row{grid-template-columns:1fr;gap:8px;text-align:center}.grade-row.header{display:none}.grade-input-wrapper input{text-align:center}.settings-grid{grid-template-columns:1fr}.summary-highlights-strip{grid-template-columns:1fr}.cards-grid{grid-template-columns:1fr}}
@media(max-width:480px){.panel-block{padding:16px}.header-actions{flex-direction:column;align-items:stretch}.btn-add{justify-content:center}.main-content{padding:12px}}
.grade-record-card{background:#fff;border:1px solid var(--border-color);border-radius:12px;padding:24px;box-shadow:0 2px 8px rgba(0,0,0,.06)}
.grade-record-header{display:flex;align-items:center;gap:16px;margin-bottom:20px;padding-bottom:16px;border-bottom:1px solid var(--border-color)}
.grade-record-avatar{width:56px;height:56px;border-radius:50%;background:var(--light-green-bg);color:var(--primary-green);display:flex;align-items:center;justify-content:center;font-size:1.4rem;flex-shrink:0}
.grade-record-info{flex:1;min-width:0}
.grade-record-name{font-size:1.1rem;font-weight:700;color:#111;margin:0 0 4px}
.grade-record-id{font-size:.85rem;color:var(--text-muted);margin:0 0 2px}
.grade-record-dept{font-size:.8rem;color:var(--text-muted);margin:0}
.grade-record-badge .badge{font-size:.75rem}
.grade-table-wrap{overflow-x:auto;border:1px solid var(--border-color);border-radius:8px}
.grade-override-table{width:100%;border-collapse:collapse;font-size:.85rem}
.grade-override-table th{color:#4b5563;background:#f9fafb;font-weight:600;text-transform:uppercase;font-size:.7rem;padding:10px 12px;border-bottom:1px solid var(--border-color);text-align:left;white-space:nowrap}
.grade-override-table td{padding:10px 12px;color:#1f2937;border-bottom:1px solid #f3f4f6;vertical-align:middle}
.grade-override-table tr:hover td{background:#f9fafb}
.grade-override-table .grade-input{padding:6px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:.85rem;text-align:center;width:72px}
.grade-override-table .grade-input:focus{outline:none;border-color:var(--primary-green);box-shadow:0 0 0 2px rgba(14,68,41,.15)}
.grade-override-table .grade-subject-code{font-weight:700;color:var(--primary-green)}
.grade-override-table .grade-subject-title{font-weight:500;color:#1f2937;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.grade-override-table .grade-units{text-align:center;color:#4b5563}
.grade-override-table .grade-avg,.grade-override-table .grade-gwa{font-weight:700;text-align:center}
.grade-override-table .btn-save-override{background:var(--primary-green);color:#fff;border:none;padding:6px 10px;border-radius:4px;font-size:.75rem;cursor:pointer;display:inline-flex;align-items:center;justify-content:center}
.grade-override-table .btn-save-override:hover{background:var(--primary-green-hover)}
.grade-empty{color:var(--text-muted);padding:24px 0;text-align:center}
</style>
</head>
<body>
<input type="checkbox" id="sidebar-toggle">
<label for="sidebar-toggle" class="mobile-header">
  <span class="menu-toggle-btn"><i class="fa-solid fa-bars"></i></span>
</label>


<aside class="sidebar">
  <div class="sidebar-header">
    <div class="sidebar-profile">
      <?php if ($role==='teacher' && $profilePicture && file_exists(__DIR__.'/../uploads/teachers/'.$profilePicture)): ?>
        <img src="uploads/teachers/<?php echo htmlspecialchars($profilePicture); ?>" alt="Profile" class="sidebar-profile-img" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
        <div class="sidebar-profile-icon" style="display:none"><i class="fa-solid fa-user"></i></div>
      <?php elseif ($role==='admin'): ?>
        <img src="https://cscqcph.com/images/bg/cscqcph.png" alt="CSCQC" class="sidebar-profile-img">
      <?php else: ?>
        <div class="sidebar-profile-icon <?php echo $role==='student' ? 'student-profile-icon' : ''; ?>">
          <?php if ($role==='student'): ?><i class="fa-solid fa-user-graduate"></i>
          <?php else: ?><i class="fa-solid fa-user"></i><?php endif; ?>
        </div>
      <?php endif; ?>
      <div class="sidebar-profile-name"><?php echo htmlspecialchars($profileName ?: $profileRole.' User'); ?></div>
      <div class="sidebar-profile-role"><?php echo htmlspecialchars($profileRole); ?></div>
      <?php if ($role==='teacher' && !empty($profileDepartment)): ?>
        <div class="sidebar-profile-dept"><?php echo htmlspecialchars($profileDepartment); ?></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="sidebar-divider"></div>
  <nav class="sidebar-nav" aria-label="Main Navigation">
    <div class="nav-menu" id="sidebar-nav-menu">
      <?php
      $navItems = [];
      if ($role==='admin') {
        $navItems = [
          ['href'=>'admin.php','icon'=>'fa-table-cells-large','label'=>'Dashboard','nav'=>'dashboard'],
          ['href'=>'teachers.php','icon'=>'fa-users','label'=>'Teachers','nav'=>'teachers'],
          ['href'=>'section.php','icon'=>'fa-book-open','label'=>'Section & Department','nav'=>'sections'],
          ['href'=>'students.php','icon'=>'fa-user-graduate','label'=>'Students','nav'=>'students'],
          ['href'=>'assign.php','icon'=>'fa-gear','label'=>'Assign Module','nav'=>'assign'],
          ['href'=>'deadline_manager.php','icon'=>'fa-calendar-days','label'=>'Grade Deadlines','nav'=>'deadlines'],
          ['href'=>'viewing_manager.php','icon'=>'fa-eye','label'=>'Grade Viewing','nav'=>'viewing'],
          ['href'=>'grade_requests.php','icon'=>'fa-clipboard-list','label'=>'Grade Requests','nav'=>'requests'],
          ['href'=>'admin_grade_viewer.php','icon'=>'fa-pen-to-square','label'=>'View Grade','nav'=>'grade_override'],
        ];
      } elseif ($role==='teacher') {
        $navItems = [
          ['href'=>'teacher_module.php?view=classes&global_year='.urlencode($schoolYear).'&global_sem='.urlencode($semester),'icon'=>'fa-list-ul','label'=>'My Classes','nav'=>'classes'],
          ['href'=>'teacher_requests.php?view=requests&global_year='.urlencode($schoolYear).'&global_sem='.urlencode($semester),'icon'=>'fa-paper-plane','label'=>'My Requests','nav'=>'requests'],
          ['href'=>'teacher_settings.php?view=settings&global_year='.urlencode($schoolYear).'&global_sem='.urlencode($semester),'icon'=>'fa-gear','label'=>'Settings','nav'=>'settings'],
        ];
      }
      foreach ($navItems as $item):
        $isActive = ($activeNav === ($item['nav'] ?? ''));
        $baseUrl = $item['href'];
        $url = parse_url($baseUrl);
        $path = $url['path'] ?? $baseUrl;
        $query = [];
        if (!empty($url['query'])) {
            parse_str($url['query'], $query);
        }
        $query['global_year'] = $schoolYear;
        $query['global_sem'] = $semester;
        $href = $path . '?' . http_build_query($query);
      ?>
        <a href="<?php echo htmlspecialchars($href); ?>" class="nav-link <?php echo $isActive?'active':''; ?>">
          <i class="fa-solid <?php echo htmlspecialchars($item['icon']); ?>"></i>
          <?php echo htmlspecialchars($item['label']); ?>
        </a>
      <?php endforeach; ?>
    </div>
  </nav>
  <div class="sidebar-footer">
    <a href="#" class="logout-btn" id="btn-logout"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
  </div>
</aside>

<div class="main-content">
  <form method="get" action="" style="margin-bottom:0">
    <input type="hidden" name="global_year" id="hidden-global-year" value="<?php echo htmlspecialchars($schoolYear); ?>">
    <input type="hidden" name="global_sem" id="hidden-global-sem" value="<?php echo htmlspecialchars($semester); ?>">
    <div class="global-term-container">
      <div class="filter-group">
        <label for="global-filter-year"><i class="fa-solid fa-calendar-days" aria-hidden="true"></i> Academic Year:</label>
        <select id="global-filter-year" class="global-select">
          <option value="2025-2026" <?php echo $schoolYear==='2025-2026'?'selected':''; ?>>2025–2026</option>
          <option value="2026-2027" <?php echo $schoolYear==='2026-2027'?'selected':''; ?>>2026–2027</option>
        </select>
      </div>
      <div class="filter-group">
        <label for="global-filter-sem"><i class="fa-solid fa-clock" aria-hidden="true"></i> Semester:</label>
        <select id="global-filter-sem" class="global-select">
          <option value="1st Semester" <?php echo $semester==='1st Semester'?'selected':''; ?>>1st Semester</option>
          <option value="2nd Semester" <?php echo $semester==='2nd Semester'?'selected':''; ?>>2nd Semester</option>
          <option value="Summer" <?php echo $semester==='Summer'?'selected':''; ?>>Summer</option>
        </select>
      </div>
    </div>
  </form>

  <?php if ($role==='teacher'): ?>
  <div class="tab-content" id="content-classes" style="display:<?php echo $activeNav==='classes'?'block':'none'; ?>">
    <?php echo $classesContent; ?>
  </div>
  <div class="tab-content" id="content-requests" style="display:<?php echo $activeNav==='requests'?'block':'none'; ?>">
    <?php echo $requestsContent; ?>
  </div>
  <div class="tab-content" id="content-settings" style="display:<?php echo $activeNav==='settings'?'block':'none'; ?>">
    <?php echo $settingsContent; ?>
  </div>
  <?php else: ?>
  <div class="tab-content" style="display:block">
    <?php echo $content; ?>
  </div>
  <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded',function(){
  var y=document.getElementById('global-filter-year'),s=document.getElementById('global-filter-sem');
  function sync(){var u=new URL(window.location);u.searchParams.set('global_year',y.value);u.searchParams.set('global_sem',s.value);u.searchParams.delete('school_year');u.searchParams.delete('semester');u.searchParams.delete('academic_year');window.location.href=u.toString()}
  if(y)y.addEventListener('change',sync);
  if(s)s.addEventListener('change',sync);
  var t=document.getElementById('sidebar-toggle'),sb=document.querySelector('.sidebar');
  if(t&&sb){t.addEventListener('click',function(){sb.classList.toggle('is-open')})}
  var lb=document.getElementById('btn-logout');
  if(lb){lb.addEventListener('click',function(e){e.preventDefault();if(confirm('Are you sure you want to log out?'))window.location.href='login.php?logout=1'})}
});
</script>
</body>
</html>
