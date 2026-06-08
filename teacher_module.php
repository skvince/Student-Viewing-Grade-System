<?php require_once __DIR__ . '/inc/functions.php'; ?>
<?php
session_start();
// allow entry if a teacher_id query param is provided (redirect from creation),
// otherwise require an existing logged-in teacher session role.
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'teacher') {
  if (isset($_GET['teacher_id'])) {
    $_SESSION['user_role'] = 'teacher';
    $_SESSION['user_id'] = intval($_GET['teacher_id']);
  } else {
    header('Location: login.php');
    exit;
  }
}
// fetch teacher name for display
$userName = '';
if (!empty($_SESSION['user_id'])) {
  $conn = db_connect();
  if ($conn) {
    $id = intval($_SESSION['user_id']);
    $stmt = $conn->prepare('SELECT name FROM teachers WHERE id = ? LIMIT 1');
    if ($stmt) {
      $stmt->bind_param('i', $id);
      $stmt->execute();
      $res = $stmt->get_result();
      if ($res && $row = $res->fetch_assoc()) {
        $userName = $row['name'] ?? '';
      }
      $stmt->close();
    }
    $conn->close();
  }
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>CSCQC Portal</title>
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
        display: flex;
        height: 100vh;
        overflow: hidden;
      }

      /* --- SIDEBAR STYLE --- */
      .sidebar {
        width: 260px;
        background-color: var(--sidebar-bg);
        border-right: 1px solid var(--border-color);
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        padding: 20px 0;
        flex-shrink: 0;
      }

      .brand {
        display: flex;
        align-items: center;
        padding: 0 24px;
        margin-bottom: 30px;
      }

      .brand i {
        font-size: 2rem;
        color: var(--primary-green);
        margin-right: 12px;
      }

      .brand-text h2 {
        font-size: 1rem;
        font-weight: 700;
        color: #111827;
      }

      .brand-text p {
        font-size: 0.75rem;
        color: var(--text-muted);
      }

      .nav-menu {
        list-style: none;
      }

      .nav-item {
        display: flex;
        align-items: center;
        padding: 12px 24px;
        color: var(--primary-green);
        background-color: var(--active-nav-bg);
        font-size: 0.9rem;
        font-weight: 600;
        cursor: pointer;
        border-left: 4px solid transparent;
        width: 100%;
        text-align: left;
        border: none;
      }

      .nav-item i {
        margin-right: 12px;
        font-size: 1.1rem;
        width: 20px;
        text-align: center;
      }

      .logout-btn {
        margin: 0 20px;
        padding: 12px;
        background-color: var(--primary-green);
        color: white;
        border: none;
        border-radius: 6px;
        font-weight: 500;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        text-decoration: none;
        font-size: 0.9rem;
        transition: background 0.2s;
      }

      .logout-btn:hover { background-color: var(--primary-green-hover); }

      /* --- MAIN CONTENT STYLE --- */
      .main-content {
        flex-grow: 1;
        padding: 40px;
        overflow-y: auto;
      }

      .view-title {
        font-size: 1.1rem;
        color: var(--primary-green);
        font-weight: 600;
        margin-bottom: 4px;
      }

      .view-subtitle {
        font-size: 0.9rem;
        color: var(--text-muted);
        font-weight: 400;
        margin-bottom: 24px;
      }

      /* --- CONTROLLER STATE LOGIC --- */
      .state-controller { display: none; }

      #view-section-list { display: block; }
      #view-students-container { display: none; }

      #trigger-section-active:checked ~ .main-content #view-section-list { display: none; }
      #trigger-section-active:checked ~ .main-content #view-students-container { display: block; }

      /* --- GLOBAL SYSTEM CONTROLS --- */
      .global-term-container {
        display: flex;
        gap: 15px;
        background-color: #0e4429;
        padding: 14px 20px;
        border-radius: 8px;
        margin-bottom: 24px;
        align-items: center;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
      }

      .global-term-container label {
        color: #ffffff;
        font-size: 0.85rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 8px;
      }

      .global-select {
        padding: 6px 12px;
        border-radius: 4px;
        border: 1px solid #165c39;
        font-size: 0.85rem;
        background-color: #ffffff;
        color: #333333;
        font-weight: 500;
        cursor: pointer;
        outline: none;
      }

      /* --- SEARCH & SELECT FILTER UTILITIES --- */
      .filter-toolbar {
        display: flex;
        gap: 15px;
        margin-bottom: 20px;
        background-color: var(--card-bg);
        padding: 16px;
        border-radius: 8px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
      }

      .search-box-wrapper {
        position: relative;
        flex-grow: 1;
      }

      .search-box-wrapper i {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: #9ca3af;
      }

      .search-input {
        width: 100%;
        padding: 10px 10px 10px 40px;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: 0.875rem;
      }

      .search-input:focus, .filter-select:focus {
        outline: none;
        border-color: var(--primary-green);
        box-shadow: 0 0 0 2px rgba(14, 68, 41, 0.1);
      }

      .filter-select {
        padding: 10px 32px 10px 14px;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: 0.875rem;
        background-color: white;
        min-width: 160px;
        cursor: pointer;
      }

      /* --- ACCORDION LIST / SECTIONS STYLE --- */
      .accordion-container {
        background-color: var(--card-bg);
        border-radius: 8px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        overflow: hidden;
      }

      .accordion-header {
        display: flex;
        justify-content: flex-end;
        padding: 14px 24px;
        border-bottom: 1px solid var(--border-color);
        color: var(--primary-green);
        font-size: 1.2rem;
      }

      .section-row-btn {
        display: flex;
        justify-content: space-between;
        align-items: center;
        width: 100%;
        padding: 16px 24px;
        background: none;
        border: none;
        border-bottom: 1px solid #f3f4f6;
        color: #1f2937;
        font-size: 0.875rem;
        font-weight: 500;
        cursor: pointer;
        transition: background-color 0.15s;
      }

      .section-row-btn:last-child { border-bottom: none; }
      .section-row-btn:hover { background-color: #f9fafb; }
      
      .row-subject-tag {
        font-size: 0.8rem;
        color: var(--text-muted);
        font-weight: 400;
        background-color: #f3f4f6;
        padding: 4px 10px;
        border-radius: 4px;
      }

      /* --- STUDENT LIST TABLE STYLE --- */
      .panel-block {
        background-color: var(--card-bg);
        border-radius: 8px;
        padding: 24px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
      }

      table {
        width: 100%;
        border-collapse: collapse;
        text-align: left;
        font-size: 0.85rem;
        margin-bottom: 24px;
      }

      th {
        color: #8492a6;
        font-weight: 600;
        text-transform: uppercase;
        font-size: 0.75rem;
        padding-bottom: 16px;
        border-bottom: 1px solid var(--border-color);
        padding-right: 10px;
      }

      td {
        padding: 12px 10px 12px 0;
        color: #1f2937;
        border-bottom: 1px solid #f3f4f6;
        vertical-align: middle;
      }

      .grade-input {
        width: 75px;
        padding: 6px 8px;
        border: 1px solid #d1d5db;
        border-radius: 4px;
        font-size: 0.85rem;
        text-align: center;
      }

      .grade-input:focus {
        outline: none;
        border-color: var(--primary-green);
        box-shadow: 0 0 0 2px rgba(14, 68, 41, 0.1);
      }

      .calculated-final {
        font-weight: 600;
        font-size: 0.9rem;
      }

      .gwa-badge {
        font-weight: 700;
        font-size: 0.85rem;
        background-color: #edf2f7;
        padding: 4px 8px;
        border-radius: 4px;
        color: #2d3748;
      }

      .status-badge {
        font-size: 0.72rem;
        font-weight: 800;
        text-transform: uppercase;
        padding: 4px 10px;
        border-radius: 50px;
        display: inline-block;
        letter-spacing: 0.5px;
      }

      .badge-empty { background-color: #edf2f7; color: #718096; }
      .badge-pass { background-color: #def7ec; color: #03543f; }
      .badge-fail { background-color: #fde8e8; color: #9b1c1c; }

      .btn-back {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background-color: white;
        color: #4b5563;
        border: 1px solid #d1d5db;
        padding: 8px 16px;
        border-radius: 6px;
        font-size: 0.85rem;
        font-weight: 500;
        cursor: pointer;
        transition: background-color 0.15s;
      }

      .btn-back:hover { background-color: #f9fafb; }

      .btn-save-all {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background-color: var(--primary-green);
        color: white;
        border: none;
        padding: 8px 18px;
        border-radius: 6px;
        font-size: 0.85rem;
        font-weight: 600;
        cursor: pointer;
        margin-right: 10px;
        transition: background-color 0.15s;
      }
      .btn-save-all:hover { background-color: var(--primary-green-hover); }
    </style>
  </head>
  <body>

    <input type="radio" name="view-state" id="trigger-base" class="state-controller" checked />
    <input type="radio" name="view-state" id="trigger-section-active" class="state-controller" />

    <aside class="sidebar">
      <div>
        <div class="brand">
          <i class="fa-solid fa-graduation-cap" aria-hidden="true"></i>
          <div class="brand-text">
            <h2>Teacher Panel</h2>
            <p>CSCQC</p>
          </div>
        </div>

        <nav class="nav-menu" aria-label="Main Navigation">
          <label for="trigger-base" class="nav-item" id="sidebar-nav-list" style="cursor:pointer;">
            <i class="fa-solid fa-list-ul" aria-hidden="true"></i> Student List
          </label>
        </nav>
      </div>

      <div style="padding: 0 20px 20px 20px; text-align: center;">
        <div style="margin-bottom:8px; color:#374151; font-weight:600;"><?php echo htmlspecialchars($userName ?: ''); ?></div>
        <a href="login.php?logout=1" class="logout-btn">
          <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i> Logout
        </a>
      </div>
    </aside>

    <main class="main-content">
      
      <div class="global-term-container">
        <label for="global-sy-select">
          <i class="fa-solid fa-calendar-days"></i> Academic Year:
        </label>
        <select id="global-sy-select" class="global-select">
          <option value="2025-2026">2025–2026</option>
          <option value="2024-2025">2024–2025</option>
        </select>

        <label for="global-sem-select">
          <i class="fa-solid fa-clock"></i> Semester:
        </label>
        <select id="global-sem-select" class="global-select">
          <option value="1st Semester">1st Semester</option>
          <option value="2nd Semester">2nd Semester</option>
          <option value="Summer">Summer</option>
        </select>
      </div>
      
      <section id="view-section-list" aria-labelledby="heading-student-list">
        <h1 id="heading-student-list" class="view-title" style="margin-bottom: 24px;">Assigned Class Sections</h1>

        <div class="filter-toolbar">
          <div class="search-box-wrapper">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" id="search-sections-input" class="search-input" placeholder="Search by section or subject name...">
          </div>
          <select id="filter-year-select" class="filter-select">
            <option value="all">All Years</option>
            <option value="1st Year">1st Year</option>
            <option value="2nd Year">2nd Year</option>
          </select>
        </div>

        <div class="accordion-container" id="sections-wrapper">
          <div class="accordion-header">
            <i class="fa-solid fa-angle-up" aria-hidden="true"></i>
          </div>

          <div class="empty-section-message" style="padding: 24px; text-align: center; color: #666;">
            No sections assigned yet.
          </div>
        </div>
      </section>

      <section id="view-students-container" aria-labelledby="active-section-title">
        <h1 id="active-section-title" class="view-title">Dynamic Class Title</h1>
        <p id="active-subject-subtitle" class="view-subtitle">Dynamic Subject Subtitle</p>

        <div class="panel-block">
          <form id="grades-submission-form" onsubmit="event.preventDefault()">
            <table id="dynamic-students-table">
              <thead>
                <tr>
                  <th scope="col" style="width: 12%;">Student ID</th>
                  <th scope="col" style="width: 25%;">Student Name</th>
                  <th scope="col" style="text-align: center; width: 12%;">Prelim</th>
                  <th scope="col" style="text-align: center; width: 12%;">Midterm </th>
                  <th scope="col" style="text-align: center; width: 12%;">Finals </th>
                  <th scope="col" style="text-align: center; width: 15%;">Average</th>
                  <th scope="col" style="text-align: center; width: 12%;">Student GWA</th>
                  <th scope="col" style="text-align: center; width: 12%;">Remarks</th>
                </tr>
              </thead>
              <tbody id="student-table-body">
              </tbody>
            </table>

            <button type="submit" class="btn-save-all">
              <i class="fa-solid fa-floppy-disk"></i> Save Marks
            </button>

            <label for="trigger-base" class="btn-back">
              <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back
            </label>
          </form>
        </div>
      </section>
    </main>

    <script>
      document.addEventListener("DOMContentLoaded", function () {
        const sectionButtons = document.querySelectorAll(".section-row-btn");
        const studentTableBody = document.getElementById("student-table-body");
        const activeSectionTitle = document.getElementById("active-section-title");
        const activeSubjectSubtitle = document.getElementById("active-subject-subtitle");
        
        const searchInput = document.getElementById("search-sections-input");
        const filterYearSelect = document.getElementById("filter-year-select");
        
        // Global system selectors
        const globalSySelect = document.getElementById("global-sy-select");
        const globalSemSelect = document.getElementById("global-sem-select");

        const viewToggleTrigger = document.getElementById("trigger-section-active");
        const baseViewTrigger = document.getElementById("trigger-base");
        const sidebarNavList = document.getElementById("sidebar-nav-list");

        sidebarNavList.addEventListener("click", () => {
          baseViewTrigger.checked = true;
        });

        // 1. REAL-TIME MULTI-CRITERIA FILTER ENGINE
        function filterSections() {
          const searchValue = searchInput.value.toLowerCase().trim();
          const selectedYear = filterYearSelect.value;
          
          // Capture current global system period parameters
          const currentGlobalSy = globalSySelect.value;
          const currentGlobalSem = globalSemSelect.value;

          sectionButtons.forEach(button => {
            const sectionName = button.getAttribute("data-section-name").toLowerCase();
            const subjectName = button.getAttribute("data-subject").toLowerCase();
            const yearLevel = button.getAttribute("data-year");
            
            // Extract current target context attributes
            const itemSy = button.getAttribute("data-academic-year");
            const itemSem = button.getAttribute("data-semester");

            const matchesSearch = sectionName.includes(searchValue) || subjectName.includes(searchValue);
            const matchesYear = (selectedYear === "all") || (yearLevel === selectedYear);
            
            // Check global match criteria rules
            const matchesGlobalSy = (itemSy === currentGlobalSy);
            const matchesGlobalSem = (itemSem === currentGlobalSem);

            if (matchesSearch && matchesYear && matchesGlobalSy && matchesGlobalSem) {
              button.style.display = "flex";
            } else {
              button.style.display = "none";
            }
          });
        }

        // Attach event listener structural bindings
        searchInput.addEventListener("input", filterSections);
        filterYearSelect.addEventListener("change", filterSections);
        globalSySelect.addEventListener("change", filterSections);
        globalSemSelect.addEventListener("change", filterSections);

        // Execute filtration routine immediately upon initial startup
        filterSections();

        // Translates running numeric scores into university grade values
        function getGwaEquivalent(percentage) {
          if (percentage >= 97) return "1.00";
          if (percentage >= 94) return "1.25";
          if (percentage >= 91) return "1.50";
          if (percentage >= 88) return "1.75";
          if (percentage >= 85) return "2.00";
          if (percentage >= 82) return "2.25";
          if (percentage >= 79) return "2.50";
          if (percentage >= 76) return "2.75";
          if (percentage >= 75) return "3.00";
          return "5.00";
        }

        // Re-formats raw names to "Surname, First Mid" array layouts
        function formatToLastNameFirst(fullName) {
          const parts = fullName.trim().split(/\s+/);
          if (parts.length <= 1) return fullName; 
          
          const lastName = parts.pop(); 
          const restOfName = parts.join(" ");
          
          return `${lastName}, ${restOfName}`;
        }

        sectionButtons.forEach(button => {
          button.addEventListener("click", function () {
            const sectionName = this.getAttribute("data-section-name");
            const subjectName = this.getAttribute("data-subject");
            const rawStudentsData = this.getAttribute("data-students");

            if (!rawStudentsData) return;

            let studentsArray = JSON.parse(rawStudentsData);

            studentsArray = studentsArray.map(student => {
              return {
                id: student.id,
                name: formatToLastNameFirst(student.name)
              };
            });

            studentsArray.sort((a, b) => a.name.localeCompare(b.name));
            
            activeSectionTitle.textContent = sectionName;
            activeSubjectSubtitle.textContent = "Subject: " + subjectName;
            studentTableBody.innerHTML = "";

            studentsArray.forEach(student => {
              const row = document.createElement("tr");
              row.className = "student-row";

              row.innerHTML = `
                <td><strong>${student.id}</strong></td>
                <td>${student.name}</td>
                <td style="text-align: center;">
                  <input type="number" min="0" max="100" name="prelim_${student.id}" class="grade-input prelim-field" placeholder="">
                </td>
                <td style="text-align: center;">
                  <input type="number" min="0" max="100" name="midterm_${student.id}" class="grade-input midterm-field" placeholder="">
                </td>
                <td style="text-align: center;">
                  <input type="number" min="0" max="100" name="final_${student.id}" class="grade-input final-field" placeholder="">
                </td>
                <td style="text-align: center;">
                  <span class="calculated-final" id="avg-${student.id}">--</span>
                </td>
                <td style="text-align: center;">
                  <span class="gwa-badge" id="gwa-${student.id}">--</span>
                </td>
                <td style="text-align: center;">
                  <span class="status-badge badge-empty" id="badge-${student.id}">Pending</span>
                </td>
              `;
              studentTableBody.appendChild(row);
            });

            attachGradeCalculationListeners();
            viewToggleTrigger.checked = true;
          });
        });

        function attachGradeCalculationListeners() {
          const studentRows = studentTableBody.querySelectorAll(".student-row");

          studentRows.forEach(row => {
            const inputs = row.querySelectorAll(".grade-input");
            const prelimInput = row.querySelector(".prelim-field");
            const midtermInput = row.querySelector(".midterm-field");
            const finalInput = row.querySelector(".final-field");
            const averageDisplay = row.querySelector(".calculated-final");
            const gwaBadge = row.querySelector(".gwa-badge");
            const statusBadge = row.querySelector(".status-badge");

            inputs.forEach(input => {
              input.addEventListener("input", () => {
                const p = parseFloat(prelimInput.value);
                const m = parseFloat(midtermInput.value);
                const f = parseFloat(finalInput.value);

                if (!isNaN(p) && !isNaN(m) && !isNaN(f)) {
                  const finalAverage = (p + m + f) / 3;
                  averageDisplay.textContent = finalAverage.toFixed(2) + "%";

                  const computedGwa = getGwaEquivalent(finalAverage);
                  gwaBadge.textContent = computedGwa;

                  if (finalAverage >= 75) {
                    averageDisplay.style.color = "#16a34a";
                    statusBadge.textContent = "Passed";
                    statusBadge.className = "status-badge badge-pass";
                  } else {
                    averageDisplay.style.color = "#dc2626";
                    statusBadge.textContent = "Failed";
                    statusBadge.className = "status-badge badge-fail";
                  }
                } else {
                  averageDisplay.textContent = "--";
                  averageDisplay.style.color = "inherit";
                  gwaBadge.textContent = "--";
                  statusBadge.textContent = "Pending";
                  statusBadge.className = "status-badge badge-empty";
                }
              });
            });
          });
        }
      });
    </script>
  </body>
</html>