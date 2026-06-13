# Task: Fix/Improve Global Filter (School Year + Semester)

## Steps (progress)
1. Inspect current term/global-filter handling across modules. ✅
2. Standardize a single global-term source of truth (sanitize, prefer GET > session, fallback defaults). ✅
3. Propagate global filter via links/forms (ensure term persists across navigation).
4. Ensure every read/list query filters by selected school_year + semester.
5. Ensure every CRUD write includes school_year + semester, and every subsequent read matches.
6. Improve Dashboard stats + Recent Assignments to be term-filtered consistently.
7. Validate consistency: teacher/student/module/report views show identical filtered results for same term.
8. Run quick smoke tests (switch terms, navigate, create/update/delete, verify isolation).



