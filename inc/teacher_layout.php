<?php
require_once __DIR__ . '/functions.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'teacher') {
    header('Location: login.php');
    exit;
}
$userId = intval($_SESSION['user_id']);
$profile = get_teacher_profile($userId);
$userName = $profile['name'] ?? '';
$profilePicture = $profile['profile_picture'] ?? null;

$activeNav = $_GET['view'] ?? 'classes';
$term = get_global_term();
$schoolYear = $term['year'];
$semester   = $term['semester'];

$_SESSION['teacher_sy']  = $schoolYear;
$_SESSION['teacher_sem'] = $semester;

$classesContent = $classesContent ?? '';
$requestsContent = $requestsContent ?? '';
$settingsContent = $settingsContent ?? '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Teacher Panel - CSCQC</title>
    <link rel="icon" type="image/png" href="https://cscqcph.com/images/bg/cscqcph.png"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <style>
        :root {
            --bg-color: #f1f4f2;
            --sidebar-bg: #ffffff;
            --text-color: #333333;
            --text-muted: #666666;
            --primary-green: #0e4429;
            --primary-green-hover: #165c39;
            --light-green-bg: #d8ebd4;
            --active-nav-bg: #b9deb3;
            --border-color: #e5e7eb;
            --card-bg: #ffffff;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: "Inter", sans-serif;
        }

        body {
            background-color: var(--bg-color);
            color: var(--text-color);
            display: grid;
            grid-template-columns: 260px 1fr;
            min-height: 100vh;
            position: relative;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image: url('https://cscqcph.com/images/bg/cscqcph.png');
            background-repeat: no-repeat;
            background-position: center;
            background-size: contain;
            opacity: .04;
            pointer-events: none;
            z-index: 0;
        }

        .main-content {
            position: relative;
            z-index: 1;
        }

        #sidebar-toggle {
            display: none;
        }

        .mobile-header {
            display: none;
            background-color: var(--sidebar-bg);
            border-bottom: 1px solid var(--border-color);
            padding: 12px 20px;
            align-items: center;
            justify-content: space-between;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 100;
            height: 60px;
        }

        .menu-toggle-btn {
            font-size: 1.3rem;
            color: var(--primary-green);
            cursor: pointer;
            padding: 4px 8px;
        }

        .sidebar {
            background-color: var(--sidebar-bg);
            border-right: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            height: 100vh;
            position: sticky;
            top: 0;
            z-index: 90;
            width: 260px;
            min-width: 260px;
        }

        .sidebar-header {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 28px 20px 20px;
            text-align: center;
            border-bottom: 1px solid var(--border-color);
        }

        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            padding: 0 12px;
            align-self: flex-start;
        }

        .sidebar-brand img {
            width: 36px;
            height: 36px;
            object-fit: contain;
        }

        .sidebar-brand-text h2 {
            font-size: 0.95rem;
            font-weight: 700;
            color: #111827;
        }

        .sidebar-brand-text p {
            font-size: 0.75rem;
            color: var(--text-muted);
        }

        .sidebar-profile {
            display: flex;
            flex-direction: column;
            align-items: center;
            width: 100%;
        }

        .sidebar-profile-img {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid var(--border-color);
            background-color: #f3f4f6;
            margin-bottom: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }

        .sidebar-profile-icon {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            background-color: var(--light-green-bg);
            color: var(--primary-green);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.2rem;
            margin-bottom: 12px;
            border: 4px solid var(--border-color);
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }

        .sidebar-profile-name {
            font-size: 1rem;
            font-weight: 700;
            color: #111827;
            word-break: break-word;
            line-height: 1.3;
            margin-bottom: 2px;
        }

        .sidebar-profile-role {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--primary-green);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 2px;
        }

        .sidebar-profile-dept {
            font-size: 0.75rem;
            color: var(--text-muted);
            font-weight: 500;
        }

        .sidebar-divider {
            height: 1px;
            background-color: var(--border-color);
            margin: 0 20px;
        }

        .sidebar-nav {
            flex: 1;
            display: flex;
            flex-direction: column;
            padding: 16px 12px;
            overflow-y: auto;
        }

        .nav-menu {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 4px;
            width: 100%;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            color: var(--text-muted);
            font-size: 0.9rem;
            font-weight: 500;
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.2s ease;
            border-left: 3px solid transparent;
            min-height: 44px;
        }

        .nav-link i {
            font-size: 1.05rem;
            width: 20px;
            text-align: center;
            flex-shrink: 0;
        }

        .nav-link:hover {
            background-color: #f9fafb;
            color: var(--primary-green);
            border-left-color: var(--primary-green);
        }

        .nav-link.active {
            background-color: var(--active-nav-bg);
            color: var(--primary-green);
            font-weight: 700;
            border-left-color: var(--primary-green);
            box-shadow: inset 0 0 0 1px rgba(14,68,41,0.08);
        }

        .sidebar-footer {
            padding: 16px 20px;
            border-top: 1px solid var(--border-color);
        }

        .logout-btn {
            width: 100%;
            padding: 12px;
            background-color: transparent;
            color: var(--primary-green);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-size: 0.9rem;
            transition: all 0.2s;
            text-decoration: none;
        }

        .logout-btn:hover {
            background-color: var(--primary-green);
            color: white;
            border-color: var(--primary-green);
        }

        .main-content {
            padding: 40px;
            min-width: 0;
            overflow-y: auto;
        }

        .view-title {
            font-size: 1.25rem;
            color: var(--primary-green);
            font-weight: 600;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .view-subtitle {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-bottom: 24px;
        }

        .global-term-container {
            display: flex;
            align-items: center;
            gap: 24px;
            background-color: #0c3e21;
            padding: 16px 24px;
            border-radius: 12px;
            margin-bottom: 24px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            flex-wrap: wrap;
        }

        .filter-group {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .global-term-container label {
            color: #ffffff;
            font-size: 0.9rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
        }

        .global-term-container label i {
            font-size: 1rem;
        }

        .global-select {
            padding: 8px 14px;
            border-radius: 6px;
            border: 1px solid transparent;
            font-size: 0.875rem;
            background-color: #ffffff;
            color: #1f2937;
            font-weight: 500;
            cursor: pointer;
            outline: none;
            min-width: 140px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .global-select:focus {
            border-color: #22c55e;
            box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.2);
        }

        .panel-block {
            background-color: var(--card-bg);
            border-radius: 8px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            margin-bottom: 24px;
        }

        .block-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            gap: 16px;
            flex-wrap: wrap;
        }

        .block-title {
            font-size: 1rem;
            color: var(--primary-green);
            font-weight: 600;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .table-responsive {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background-color: #ffffff;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 0.85rem;
            min-width: 650px;
        }

        th {
            color: #4b5563;
            background-color: #f9fafb;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.75rem;
            padding: 12px 16px;
            border-bottom: 1px solid var(--border-color);
        }

        td {
            padding: 14px 16px;
            color: #1f2937;
            border-bottom: 1px solid #f3f4f6;
            vertical-align: middle;
        }

        th:last-child,
        .actions-cell {
            text-align: center;
            width: 100px;
            min-width: 100px;
        }

        .actions-cell {
            white-space: nowrap;
        }

        .actions-cell i {
            font-size: 1.15rem;
            cursor: pointer;
            margin: 0 6px;
            display: inline-block;
            transition: transform 0.2s ease;
        }

        .actions-cell i:hover {
            transform: scale(1.18);
        }

        .fa-pen-to-square {
            color: #10b981;
        }

        .fa-trash-can {
            color: #ef4444;
        }

        .form-group {
            margin: 10px 5px 20px;
        }

        .form-group label {
            display: block;
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-bottom: 8px;
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            background-color: #fff;
            font-size: 0.9rem;
            color: #1f2937;
            outline: none;
        }

        .form-control:focus {
            border-color: var(--primary-green);
            box-shadow: 0 0 0 3px rgba(14, 68, 41, 0.15);
        }

        .btn-submit {
            background-color: var(--primary-green);
            color: white;
            border: none;
            margin-bottom: 8px;
            margin-left: 7px;
            padding: 10px 20px;
            border-radius: 6px;
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .btn-submit:hover {
            background-color: var(--primary-green-hover);
        }

        .form-buttons-row {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .btn-cancel {
            background-color: #e5e7eb;
            color: #374151;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
        }

        .modal-backdrop {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            background: rgba(15, 23, 42, 0.4);
            backdrop-filter: blur(10px);
            z-index: 999;
            padding: 24px;
        }

        .modal-card {
            background-color: #ffffff;
            border: 1px solid #d1d5db;
            border-radius: 18px;
            padding: 24px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08);
            position: relative;
            max-width: 520px;
            width: 100%;
        }

        .modal-close {
            position: absolute;
            top: 18px;
            right: 18px;
            background: transparent;
            border: none;
            color: #6b7280;
            font-size: 1.1rem;
            cursor: pointer;
            padding: 8px;
        }

        .status-badge {
            font-size: 0.72rem;
            font-weight: 800;
            text-transform: uppercase;
            padding: 4px 12px;
            border-radius: 50px;
            display: inline-block;
            letter-spacing: 0.5px;
        }

        .badge-pass { background-color: #def7ec; color: #03543f; }
        .badge-fail { background-color: #fde8e8; color: #9b1c1c; }
        .badge-pending { background-color: #fef3c7; color: #92400e; }
        .badge-approved { background-color: #d1fae5; color: #065f46; }
        .badge-rejected { background-color: #fee2e2; color: #991b1b; }
        .badge-closed { background-color: #e5e7eb; color: #374151; }
        .badge-open { background-color: #d1fae5; color: #065f46; }

        .alert {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 16px;
            font-size: 0.85rem;
        }

        .alert-error {
            background-color: #fee2e2;
            color: #991b1b;
        }

        .btn-add {
            background-color: var(--primary-green);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 500;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
            transition: background 0.2s;
        }

        .btn-add:hover {
            background-color: var(--primary-green-hover);
        }

        .search-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .search-wrapper i {
            position: absolute;
            left: 12px;
            color: #9ca3af;
            font-size: 0.9rem;
            pointer-events: none;
        }

        .search-input {
            padding: 8px 12px 8px 34px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 0.85rem;
            color: #1f2937;
            width: 220px;
            transition: all 0.2s;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary-green);
            width: 260px;
            box-shadow: 0 0 0 3px rgba(14, 68, 41, 0.15);
        }

        .grades-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 16px;
        }

        .grade-row {
            display: grid;
            grid-template-columns: 120px 1fr 100px 100px 100px 100px 80px;
            gap: 12px;
            align-items: center;
            padding: 12px 16px;
            background-color: #f9fafb;
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }

        .grade-row.header {
            background-color: #f3f4f6;
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            color: #4b5563;
            border-bottom: 2px solid var(--border-color);
        }

        .student-id {
            font-weight: 600;
            color: var(--primary-green);
            font-size: 0.9rem;
        }

        .student-name {
            font-weight: 500;
            color: #1f2937;
        }

        .grade-input-wrapper input {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 0.85rem;
            text-align: center;
            background-color: #fff;
        }

        .grade-input-wrapper input:focus {
            outline: none;
            border-color: var(--primary-green);
            box-shadow: 0 0 0 3px rgba(14, 68, 41, 0.15);
        }

        .grade-input-wrapper input:disabled {
            background-color: #f3f4f6;
            color: #9ca3af;
            cursor: not-allowed;
        }

        .total-grade {
            font-weight: 600;
            text-align: center;
            color: #1f2937;
        }

        .gwa-badge {
            font-weight: 700;
            text-align: center;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.85rem;
        }

        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            text-decoration: none;
            color: var(--text-color);
            font-size: 0.85rem;
            font-weight: 500;
            margin-bottom: 16px;
            transition: all 0.2s;
        }

        .btn-back:hover {
            background-color: #f9fafb;
            border-color: var(--primary-green);
            color: var(--primary-green);
        }

        .sections-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
            margin-bottom: 24px;
        }

        .section-card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 24px;
            text-decoration: none;
            color: inherit;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            transition: all 0.2s;
            display: block;
        }

        .section-card:hover {
            border-color: var(--primary-green);
            box-shadow: 0 4px 6px rgba(14, 68, 41, 0.1);
            transform: translateY(-2px);
        }

        .section-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }

        .section-code {
            font-weight: 700;
            font-size: 1.1rem;
            color: var(--primary-green);
        }

        .badge-subjects {
            background-color: var(--light-green-bg);
            color: var(--primary-green);
            padding: 4px 10px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .section-body {
            color: var(--text-muted);
            font-size: 0.9rem;
            margin-bottom: 16px;
        }

        .section-card-footer {
            display: flex;
            justify-content: flex-end;
        }

        .btn-select {
            background-color: var(--primary-green);
            color: white;
            padding: 6px 14px;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .subjects-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
            margin-bottom: 24px;
        }

        .subject-card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 24px;
            text-decoration: none;
            color: inherit;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            transition: all 0.2s;
            display: block;
        }

        .subject-card:hover {
            border-color: var(--primary-green);
            box-shadow: 0 4px 6px rgba(14, 68, 41, 0.1);
            transform: translateY(-2px);
        }

        .subject-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }

        .subject-code {
            font-weight: 700;
            font-size: 1rem;
            color: var(--primary-green);
        }

        .subject-units {
            color: var(--text-muted);
            font-size: 0.8rem;
            font-weight: 500;
        }

        .subject-body {
            margin-bottom: 16px;
        }

        .subject-title {
            font-weight: 500;
            color: #1f2937;
            font-size: 0.95rem;
        }

        .subject-card-footer {
            display: flex;
            justify-content: flex-end;
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: var(--text-muted);
            font-size: 0.95rem;
            background-color: var(--card-bg);
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }

        .card-action-row {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
            margin-top: 8px;
        }

        .request-form-header {
            margin-bottom: 16px;
        }

        .request-form-header h3 {
            font-size: 1rem;
            color: var(--primary-green);
            font-weight: 600;
            margin: 0 0 4px 0;
        }

        .request-form-header p {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin: 0;
        }

        #requests-table tbody tr {
            transition: background-color 0.15s ease;
        }

        #requests-table tbody tr:hover {
            background-color: #f9fafb;
        }

        .filter-select {
            padding: 6px 32px 6px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 0.85rem;
            color: #1f2937;
            background-color: #fff;
            outline: none;
            appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%234b5563' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 10px center;
            background-size: 14px;
        }

        .filter-select:focus {
            border-color: var(--primary-green);
        }

        .btn-request {
            background-color: var(--primary-green);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-request:hover {
            background-color: var(--primary-green-hover);
        }

        .btn-save-all {
            background-color: var(--primary-green);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-save-all:hover {
            background-color: var(--primary-green-hover);
        }

        .form-actions {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .settings-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 24px;
        }

        .panel-title {
            font-size: 1rem;
            color: var(--primary-green);
            font-weight: 600;
            margin-bottom: 12px;
        }

        .profile-picture-area {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }

        .profile-picture-preview {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--border-color);
            background: #f3f4f6;
        }

        .profile-picture-placeholder {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: #9ca3af;
        }

        .btn-danger {
            background-color: #dc2626;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 500;
            cursor: pointer;
        }

        .btn-danger:hover {
            background-color: #b91c1c;
        }

        .alert-success {
            background-color: #d1fae5;
            color: #065f46;
        }

        .alert-info {
            background-color: #dbeafe;
            color: #1e40af;
        }

        @media (max-width: 1024px) {
            body {
                grid-template-columns: 1fr;
                padding-top: 60px;
            }

            .mobile-header {
                display: flex;
            }

            .sidebar {
                position: fixed;
                top: 60px;
                left: 0;
                bottom: 0;
                transform: translateX(-100%);
                width: 260px;
                height: calc(100vh - 60px);
                box-shadow: 4px 0 10px rgba(0, 0, 0, 0.1);
                transition: transform 0.3s ease;
            }

            #sidebar-toggle:checked ~ .sidebar {
                transform: translateX(0);
            }

            .main-content {
                padding: 20px;
            }

            .sidebar-brand {
                display: none;
            }

            .sidebar-profile-img,
            .sidebar-profile-icon {
                width: 70px;
                height: 70px;
                font-size: 1.8rem;
            }

            .grade-row {
                grid-template-columns: 80px 1fr 70px 70px 70px 70px 60px;
                gap: 8px;
                padding: 10px;
                font-size: 0.8rem;
            }
        }

        @media (max-width: 768px) {
            .header-actions {
                width: 100%;
            }

            .search-wrapper,
            .search-input {
                width: 100% !important;
            }

            .global-term-container {
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
            }

            .filter-group {
                justify-content: space-between;
            }

            .global-select {
                width: 100%;
            }

            .grade-row {
                grid-template-columns: 1fr;
                gap: 8px;
                text-align: center;
            }

            .grade-row.header {
                display: none;
            }

            .grade-input-wrapper input {
                text-align: center;
            }

            .settings-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .panel-block {
                padding: 16px;
            }

            .header-actions {
                flex-direction: column;
                align-items: stretch;
            }

            .btn-add {
                justify-content: center;
            }

            .main-content {
                padding: 12px;
            }
        }
    </style>
</head>
<body>
    <input type="checkbox" id="sidebar-toggle" />
    <label for="sidebar-toggle" class="mobile-header">
        <div class="brand" style="padding:0;margin:0;">
            <img src="https://cscqcph.com/images/bg/cscqcph.png" alt="CSCQC" style="width:32px;height:32px;object-fit:contain;font-size:1.5rem;color:var(--primary-green);margin-right:10px;">
            <div class="brand-text"><h2 style="font-size:.9rem;">Teacher Panel</h2></div>
        </div>
        <span class="menu-toggle-btn"><i class="fa-solid fa-bars"></i></span>
    </label>

    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-profile">
                <?php if ($profilePicture && file_exists(__DIR__ . '/../uploads/teachers/' . $profilePicture)): ?>
                    <img src="uploads/teachers/<?php echo htmlspecialchars($profilePicture); ?>" alt="Profile" class="sidebar-profile-img" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';" />
                    <div class="sidebar-profile-icon" style="display:none;"><i class="fa-solid fa-user"></i></div>
                <?php else: ?>
                    <div class="sidebar-profile-icon"><i class="fa-solid fa-user"></i></div>
                <?php endif; ?>
                <div class="sidebar-profile-name"><?php echo htmlspecialchars($userName ?: 'Teacher'); ?></div>
                <div class="sidebar-profile-role"><?php echo htmlspecialchars($profile['teacher_id'] ?? 'Teacher'); ?></div>
                <?php if (!empty($profile['department'])): ?>
                    <div class="sidebar-profile-dept"><?php echo htmlspecialchars($profile['department']); ?></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="sidebar-divider"></div>
        <nav class="sidebar-nav" aria-label="Main Navigation">
            <div class="nav-menu">
                <a href="teacher_module.php?view=classes&global_year=<?php echo urlencode($schoolYear); ?>&global_sem=<?php echo urlencode($semester); ?>" class="nav-link <?php echo $activeNav==='classes'?'active':''; ?>"><i class="fa-solid fa-list-ul"></i> My Classes</a>
                <a href="teacher_requests.php?view=requests&global_year=<?php echo urlencode($schoolYear); ?>&global_sem=<?php echo urlencode($semester); ?>" class="nav-link <?php echo $activeNav==='requests'?'active':''; ?>"><i class="fa-solid fa-paper-plane"></i> My Requests</a>
                <a href="teacher_settings.php?view=settings&global_year=<?php echo urlencode($schoolYear); ?>&global_sem=<?php echo urlencode($semester); ?>" class="nav-link <?php echo $activeNav==='settings'?'active':''; ?>"><i class="fa-solid fa-gear"></i> Settings</a>
            </div>
        </nav>
        <div class="sidebar-footer">
            <a href="#" class="logout-btn" id="btn-logout"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
        </div>
    </aside>
    <div class="main-content">
        <form method="get" action="" style="margin-bottom:0;">
            <input type="hidden" name="global_year" id="hidden-global-year" value="<?php echo htmlspecialchars($schoolYear); ?>">
            <input type="hidden" name="global_sem" id="hidden-global-sem" value="<?php echo htmlspecialchars($semester); ?>">
            <div class="global-term-container">
                <div class="filter-group">
                    <label for="global-filter-year">
                        <i class="fa-solid fa-calendar-days" aria-hidden="true"></i>
                        Academic Year:
                    </label>
                    <select id="global-filter-year" class="global-select">
                        <option value="2025-2026" <?php echo $schoolYear==='2025-2026'?'selected':''; ?>>2025–2026</option>
                        <option value="2026-2027" <?php echo $schoolYear==='2026-2027'?'selected':''; ?>>2026–2027</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="global-filter-sem">
                        <i class="fa-solid fa-clock" aria-hidden="true"></i> Semester:
                    </label>
                    <select id="global-filter-sem" class="global-select">
                        <option value="1st Semester" <?php echo $semester==='1st Semester'?'selected':''; ?>>1st Semester</option>
                        <option value="2nd Semester" <?php echo $semester==='2nd Semester'?'selected':''; ?>>2nd Semester</option>
                        <option value="Summer" <?php echo $semester==='Summer'?'selected':''; ?>>Summer</option>
                    </select>
                </div>
            </div>
        </form>

        <div class="tab-content" id="content-classes" style="display: <?php echo $activeNav==='classes'?'block':'none'; ?>;">
            <?php echo $classesContent ?? ''; ?>
        </div>
        <div class="tab-content" id="content-requests" style="display: <?php echo $activeNav==='requests'?'block':'none'; ?>;">
            <?php echo $requestsContent ?? ''; ?>
        </div>
        <div class="tab-content" id="content-settings" style="display: <?php echo $activeNav==='settings'?'block':'none'; ?>;">
            <?php echo $settingsContent ?? ''; ?>
        </div>
    </div>
    <script>
        const yearSelect = document.getElementById('global-filter-year');
        const semSelect = document.getElementById('global-filter-sem');
        function syncGlobalFilter() {
            const year = document.getElementById('global-filter-year').value;
            const sem  = document.getElementById('global-filter-sem').value;
            const url  = new URL(window.location);
            url.searchParams.set('global_year', year);
            url.searchParams.set('global_sem', sem);
            url.searchParams.delete('school_year');
            url.searchParams.delete('semester');
            url.searchParams.delete('academic_year');
            window.location.href = url.toString();
        }
        document.addEventListener('DOMContentLoaded', function() {
            if (yearSelect) yearSelect.addEventListener('change', syncGlobalFilter);
            if (semSelect) semSelect.addEventListener('change', syncGlobalFilter);

            const sidebarToggle = document.getElementById('sidebar-toggle');
            const sidebar = document.getElementById('sidebar');
            if (sidebarToggle && sidebar) {
                sidebarToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('is-open');
                });
            }

            const logoutBtn = document.getElementById('btn-logout');
            if (logoutBtn) {
                logoutBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    if (confirm('Are you sure you want to log out?')) {
                        window.location.href = 'login.php?logout=1';
                    }
                });
            }
        });
    </script>
</body>
</html>
