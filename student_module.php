<?php require_once __DIR__ . '/inc/functions.php'; ?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>CSCQC Portal</title>

    <link rel="icon" type="image/png" href="https://cscqcph.com/images/bg/cscqcph.png"/>

    <link
      href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap"
      rel="stylesheet"
    />
    <link
      rel="stylesheet"
      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"
    />

    <style>
      :root {
        --bg-color: #f1f4f2;
        --text-color: #333333;
        --text-muted: #666666;
        --primary-green: #0e4429;
        --primary-green-hover: #165c39;
        --light-green-bg: #d8ebd4;
        --card-bg: #ffffff;
        --border-color: #e5e7eb;
        --success-badge: #14532d;
        --success-badge-bg: #dcfce7;
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
        min-height: 100vh;
        padding: 20px 40px;
      }

      /* --- UPPER HEADER / NAVIGATION --- */
      .header-nav {
        background-color: var(--primary-green);
        color: #ffffff;
        padding: 14px 24px;
        border-radius: 8px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        flex-wrap: wrap;
        gap: 16px;
      }

      .brand-info h1 {
        font-size: 1rem;
        font-weight: 700;
        letter-spacing: 0.3px;
      }

      .brand-info p {
        font-size: 0.8rem;
        opacity: 0.85;
        margin-top: 2px;
      }

      .user-controls {
        display: flex;
        align-items: center;
        gap: 20px;
        flex-wrap: wrap;
      }

      .student-profile {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.9rem;
        font-weight: 500;
        background-color: rgba(255, 255, 255, 0.1);
        padding: 6px 14px;
        border-radius: 20px;
      }

      .logout-btn {
        background-color: transparent;
        color: #ffffff;
        border: 1px solid rgba(255, 255, 255, 0.4);
        border-radius: 6px;
        padding: 8px 16px;
        font-size: 0.85rem;
        font-weight: 500;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
        transition: all 0.2s;
      }

      .logout-btn:hover {
        background-color: #ffffff;
        color: var(--primary-green);
        border-color: #ffffff;
      }

      /* --- FILTER SECTION --- */
      .filter-card {
        background-color: var(--card-bg);
        border-radius: 8px;
        border: 1px solid var(--border-color);
        padding: 20px;
        margin-bottom: 24px;
      }

      .filter-card legend {
        font-size: 0.85rem;
        font-weight: 600;
        color: var(--text-muted);
        margin-bottom: 12px;
      }

      .filter-group-selectors {
        display: flex;
        gap: 16px;
        flex-wrap: wrap;
      }

      .form-control {
        padding: 8px 36px 8px 12px;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        background-color: #fff;
        font-size: 0.85rem;
        color: #1f2937;
        appearance: none;
        background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%234b5563' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
        background-repeat: no-repeat;
        background-position: right 12px center;
        background-size: 14px;
        cursor: pointer;
        min-width: 180px;
      }

      /* --- GRADES SHEET CONTAINER --- */
      .grades-panel-block {
        background-color: var(--card-bg);
        border-radius: 8px;
        border: 1px solid var(--border-color);
        padding: 24px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
      }

      .grades-panel-header {
        margin-bottom: 20px;
      }

      .grades-panel-header h2 {
        font-size: 1rem;
        color: var(--primary-green);
        font-weight: 600;
      }

      .grades-panel-header p {
        font-size: 0.75rem;
        color: var(--text-muted);
        margin-top: 2px;
      }

      .table-responsive {
        width: 100%;
        overflow-x: auto;
        margin-bottom: 24px;
      }

      .grades-table {
        width: 100%;
        border-collapse: collapse;
        text-align: left;
        font-size: 0.85rem;
        min-width: 850px;
      }

      .grades-table th {
        color: #8492a6;
        font-weight: 600;
        text-transform: uppercase;
        font-size: 0.75rem;
        padding: 12px 8px;
        border-bottom: 1px solid var(--border-color);
      }

      .grades-table td {
        padding: 14px 8px;
        color: #1f2937;
        border-bottom: 1px solid #f3f4f6;
      }

      .text-center { text-align: center; }
      .th-center { text-align: center !important; }
      .highlight-gwa { color: var(--primary-green); font-weight: 700; }

      .badge-passed {
        background-color: var(--success-badge-bg);
        color: var(--success-badge);
        padding: 4px 12px;
        border-radius: 12px;
        font-size: 0.75rem;
        font-weight: 600;
        display: inline-block;
      }

      /* --- FOOTER BANNER --- */
      .summary-highlights-strip {
        background-color: var(--light-green-bg);
        border-radius: 6px;
        padding: 16px 24px;
        display: grid;
        grid-template-columns: repeat(2, 1fr); /* Two columns now */
        gap: 16px;
      }

      .metric-item-box p {
        font-size: 0.75rem;
        color: #4b5563;
        font-weight: 500;
        margin-bottom: 4px;
      }

      .metric-item-box data {
        font-size: 1.35rem;
        font-weight: 700;
        color: var(--primary-green);
        display: block;
      }

      @media (max-width: 480px) {
        .summary-highlights-strip { grid-template-columns: 1fr; }
      }
    </style>
  </head>
  <body>

    <header class="header-nav">
      <div class="brand-info">
        <h1>Student — College Of St. Catherine</h1>
        <p>Quezon City</p>
      </div>
      <div class="user-controls">
        <div class="student-profile">
          <i class="fa-solid fa-circle-user"></i>
          <span>Jane O. Smith</span>
        </div>
        <button type="button" class="logout-btn">
          <i class="fa-solid fa-right-from-bracket"></i> Logout
        </button>
      </div>
    </header>

    <main>
      <form action="#" method="GET" class="filter-card">
        <fieldset style="border: none;">
          <legend>Select Year & Semester</legend>
          <div class="filter-group-selectors">
            <select name="academic_year" class="form-control">
              <option value="2025-2026" selected>2025 – 2026</option>
            </select>
            <select name="semester" class="form-control">
              <option value="2nd-semester" selected>2nd Semester</option>
              <option value="1st-semester">1st Semester</option>
            </select>
          </div>
        </fieldset>
      </form>

      <section class="grades-panel-block">
        <header class="grades-panel-header">
          <h2>View Subject Grades</h2>
          <p>Academic Year: 2025 – 2026 | 2nd Semester</p>
        </header>

        <div class="table-responsive">
          <table class="grades-table">
            <thead>
              <tr>
                <th scope="col">Subject Code</th>
                <th scope="col">Subject Name</th>
                <th scope="col" class="th-center">Units</th>
                <th scope="col" class="th-center">Prelim</th>
                <th scope="col" class="th-center">Midterm</th>
                <th scope="col" class="th-center">Finals</th>
                <th scope="col" class="th-center">Avg. %</th>
                <th scope="col" class="th-center">GWA (Pt)</th>
                <th scope="col" style="text-align: right; padding-right: 20px;">Remarks</th>
              </tr>
            </thead>
            <tbody id="student-grades-table-body">
              <tr>
                <td colspan="9" style="text-align:center; color:#666; padding: 24px;">No grade records available.</td>
              </tr>
            </tbody>
          </table>
        </div>

        <footer class="summary-highlights-strip">
          <div class="metric-item-box">
            <p>Total Registered Units</p>
            <data value="0" id="summary-total-units">0</data>
          </div>
          <div class="metric-item-box">
            <p>Subjects Passed</p>
            <data value="0" id="summary-subjects-passed">0</data>
          </div>
        </footer>
      </section>
    </main>

  </body>
</html>