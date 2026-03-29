<?php include __DIR__ . '/../../layouts/session.php'; ?>
<?php include __DIR__ . '/../../layouts/main.php'; ?>
<?php include_once __DIR__ . '/../../includes/reference.php'; ?>

<?php
$error = '';
$success = '';

ensure_reference_tables($conn);
ensure_users_password_policy_columns($conn);
$campuses = campus_list($conn, true);
$defaultCampusId = campus_default_id($conn);
$selectedCampusId = $defaultCampusId;

// Load subjects for enrollment selection
$subjects = [];
$subjectsRes = $conn->query("SELECT id, subject_code, subject_name, academic_year, semester FROM subjects WHERE status = 'active' ORDER BY subject_name");
if ($subjectsRes) {
    while ($row = $subjectsRes->fetch_assoc()) {
        $subjects[] = $row;
    }
}

// Reference-driven academic years/semesters (fallback to existing data if reference list is empty).
$academicYears = ref_list_active_names($conn, 'academic_years');
if (count($academicYears) === 0) {
    $ayRes = $conn->query("SELECT DISTINCT academic_year FROM subjects WHERE academic_year IS NOT NULL AND academic_year <> '' ORDER BY academic_year DESC");
    if ($ayRes) while ($r = $ayRes->fetch_assoc()) $academicYears[] = $r['academic_year'];
}

$semesters = ref_list_active_names($conn, 'semesters');
if (count($semesters) === 0) {
    $semRes = $conn->query("SELECT DISTINCT semester FROM subjects WHERE semester IS NOT NULL AND semester <> '' ORDER BY semester");
    if ($semRes) while ($r = $semRes->fetch_assoc()) $semesters[] = $r['semester'];
}

// Canonical class section suggestions (IF-style).
$classSectionSuggestions = [];
$seenClassSections = [];
$pushClassSection = static function (&$classSectionSuggestions, &$seenClassSections, $value) {
    $value = trim((string) $value);
    if ($value === '') return;
    if (isset($seenClassSections[$value])) return;
    $seenClassSections[$value] = true;
    $classSectionSuggestions[] = $value;
};

if (function_exists('ref_sync_class_sections_from_records')) {
    ref_sync_class_sections_from_records($conn);
}
if (function_exists('ref_list_class_sections')) {
    $classSections = ref_list_class_sections($conn, true, true);
    foreach ($classSections as $row) {
        $sec = strtoupper(trim((string) ($row['code'] ?? '')));
        if ($sec === '') continue;
        $pushClassSection($classSectionSuggestions, $seenClassSections, $sec);
    }
}
sort($classSectionSuggestions);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    $studentNo = isset($_POST['student_no']) ? trim($_POST['student_no']) : '';
    $surname = isset($_POST['surname']) ? trim($_POST['surname']) : '';
    $firstName = isset($_POST['first_name']) ? trim($_POST['first_name']) : '';
    $middleName = isset($_POST['middle_name']) ? trim($_POST['middle_name']) : '';
    $sex = isset($_POST['sex']) ? trim($_POST['sex']) : 'M';
    $course = isset($_POST['course']) ? trim($_POST['course']) : '';
    $major = isset($_POST['major']) ? trim($_POST['major']) : '';
    $yearLevel = isset($_POST['year']) ? trim($_POST['year']) : '';
    $section = isset($_POST['section']) ? trim($_POST['section']) : '';
    $classSection = isset($_POST['class_section']) ? trim($_POST['class_section']) : '';
    $selectedCampusId = isset($_POST['campus_id']) ? (int) $_POST['campus_id'] : $defaultCampusId;
    if ($selectedCampusId <= 0) $selectedCampusId = $defaultCampusId;

    $email = isset($_POST['email']) ? trim($_POST['email']) : '';

    $enrollSubjectIds = isset($_POST['subject_ids']) && is_array($_POST['subject_ids']) ? $_POST['subject_ids'] : [];
    $academicYear = isset($_POST['academic_year']) ? trim($_POST['academic_year']) : '';
    $semester = isset($_POST['semester']) ? trim($_POST['semester']) : '';

    if (function_exists('ref_normalize_course_name')) {
        $course = ref_normalize_course_name($course);
    }
    if (function_exists('ref_normalize_year_level')) {
        $yearLevel = ref_normalize_year_level($yearLevel);
    }
    if (function_exists('ref_normalize_section_code')) {
        $section = ref_normalize_section_code($section);
    }
    if (function_exists('ref_section_lookup_hint')) {
        $classSection = ref_section_lookup_hint($classSection);
    }

    if (!csrf_validate($csrf)) {
        $error = 'Security check failed. Please refresh and try again.';
    } elseif ($studentNo === '' || $surname === '' || $firstName === '' || $course === '' || $yearLevel === '' || $email === '') {
        $error = 'Please fill in all required fields.';
    } elseif ($selectedCampusId <= 0) {
        $error = 'Campus selection is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!in_array($sex, ['M', 'F'], true)) {
        $error = 'Invalid sex value.';
    } elseif ($academicYear === '' || $semester === '' || $section === '' || $classSection === '') {
        $error = 'Academic Year, Semester, Profile Section, and Class Section are required for enrollment.';
    } else {
        $classSectionHint = function_exists('ref_section_lookup_hint') ? strtoupper(ref_section_lookup_hint($classSection)) : strtoupper($classSection);
        $looksAmbiguousSectionCode =
            preg_match('/^[A-Z]$/', $classSectionHint) === 1 ||
            preg_match('/^[1-4][A-Z]$/', $classSectionHint) === 1;
        if ($looksAmbiguousSectionCode) {
            $error = 'Class Section is ambiguous. Use a full code such as IF-2-B-6.';
        }
    }

    if ($error === '') {
        $campusValid = false;
        foreach ($campuses as $campus) {
            if ((int) ($campus['id'] ?? 0) === $selectedCampusId) {
                $campusValid = true;
                break;
            }
        }
        if (!$campusValid) {
            $error = 'Selected campus is invalid.';
        }
    }

    if ($error === '') {
        if (!preg_match('/^[A-Z]$/', strtoupper($section))) {
            $error = 'Profile Section must be a single section letter (A, B, C, ...).';
        }
    }

    if ($error === '') {
        if (count($enrollSubjectIds) === 0) {
            $error = 'Please select at least one subject to enroll in.';
        }
    }

    if ($error === '') {
        $section = strtoupper($section);
    }

    if ($error === '') {
        $classSection = strtoupper($classSection);
    }

    if ($error === '') {
        // Ensure email not already used
        $stmt = $conn->prepare("SELECT id FROM users WHERE useremail = ? LIMIT 1");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $error = 'This email is already registered. Please log in.';
        } else {
            $conn->begin_transaction();
            try {
                // Create pending student account
                $defaultPassword = $studentNo;
                $hashed = password_hash($defaultPassword, PASSWORD_DEFAULT);
                $role = 'student';
                $isActive = 0;
                $mustChangePassword = 1;
                $username = $studentNo;

                $ins = $conn->prepare("INSERT INTO users (useremail, username, password, role, is_active, must_change_password, campus_id, is_superadmin) VALUES (?, ?, ?, ?, ?, ?, ?, 0)");
                $ins->bind_param('ssssiii', $email, $username, $hashed, $role, $isActive, $mustChangePassword, $selectedCampusId);
                $ins->execute();
                $userId = (int) $conn->insert_id;

                // Create or update student record, link to user_id
                $studentId = null;
                $find = $conn->prepare("SELECT id, user_id FROM students WHERE StudentNo = ? LIMIT 1");
                $find->bind_param('s', $studentNo);
                $find->execute();
                $studentRes = $find->get_result();
                if ($studentRes && $studentRes->num_rows === 1) {
                    $row = $studentRes->fetch_assoc();
                    $studentId = (int) $row['id'];

                    $upd = $conn->prepare("UPDATE students SET user_id = ?, campus_id = ?, email = COALESCE(NULLIF(email,''), ?), Surname = ?, FirstName = ?, MiddleName = ?, Sex = ?, Course = ?, Major = ?, Year = ?, Section = ? WHERE id = ?");
                    $upd->bind_param('iisssssssssi', $userId, $selectedCampusId, $email, $surname, $firstName, $middleName, $sex, $course, $major, $yearLevel, $section, $studentId);
                    $upd->execute();
                } else {
                    $createdBy = 1; // admin/system owner in current DB
                    $insS = $conn->prepare("INSERT INTO students (user_id, campus_id, StudentNo, Surname, FirstName, MiddleName, Sex, Course, Major, Year, Section, email, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $insS->bind_param('iissssssssssi', $userId, $selectedCampusId, $studentNo, $surname, $firstName, $middleName, $sex, $course, $major, $yearLevel, $section, $email, $createdBy);
                    $insS->execute();
                    $studentId = (int) $conn->insert_id;
                }

                // Insert enrollments as Pending
                $enrollStmt = $conn->prepare("INSERT INTO enrollments (student_no, subject_id, academic_year, semester, section, status, created_by) VALUES (?, ?, ?, ?, ?, 'Pending', ?)");
                foreach ($enrollSubjectIds as $sid) {
                    $sidInt = (int) $sid;
                    if ($sidInt <= 0) continue;
                    $enrollStmt->bind_param('sissss', $studentNo, $sidInt, $academicYear, $semester, $classSection, $email);
                    try {
                        $enrollStmt->execute();
                    } catch (mysqli_sql_exception $e) {
                        // Ignore duplicates from UNIQUE(student_no, subject_id, academic_year, semester)
                        if ((int) $e->getCode() !== 1062) throw $e;
                    }
                }

                $conn->commit();
                $success = 'Enrollment submitted. Default username/password is your Student No. (Student ID). Your account is pending admin approval, and password change is required on first login.';
            } catch (Throwable $e) {
                $conn->rollback();
                $error = 'Enrollment failed: ' . $e->getMessage();
            }
        }
        $stmt->close();
    }
}
?>

<head>
    <title>Student Self-Enrollment | E-Record</title>
    <?php include __DIR__ . '/../../layouts/title-meta.php'; ?>
    <?php include __DIR__ . '/../../layouts/head-css.php'; ?>
</head>

<body class="authentication-bg position-relative">

<?php include __DIR__ . '/../../layouts/background.php'; ?>

    <div class="account-pages pt-2 pt-sm-5 pb-4 pb-sm-5 position-relative">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-xxl-8 col-lg-10">
                    <div class="card">
                        <div class="card-header py-4 text-center bg-primary">
                            <a href="auth-login.php">
                                <span><img src="assets/images/logo.png" alt="logo" height="30"></span>
                            </a>
                        </div>

                        <div class="card-body p-4">
                            <div class="text-center w-75 m-auto">
                                <h4 class="text-dark-50 text-center pb-0 fw-bold">Student Self-Enrollment</h4>
                                <p class="text-muted mb-4">Submit your student details and enrollment. Default username/password is your Student No. An admin must approve your access.</p>
                            </div>

                            <?php if ($error): ?>
                                <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($error); ?></div>
                            <?php endif; ?>
                            <?php if ($success): ?>
                                <div class="alert alert-success" role="alert"><?php echo htmlspecialchars($success); ?></div>
                            <?php endif; ?>

                            <form method="post" action="auth-student-enroll.php">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Student No. <span class="text-danger">*</span></label>
                                        <input class="form-control" name="student_no" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Surname <span class="text-danger">*</span></label>
                                        <input class="form-control" name="surname" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">First Name <span class="text-danger">*</span></label>
                                        <input class="form-control" name="first_name" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Middle Name</label>
                                        <input class="form-control" name="middle_name">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Sex</label>
                                        <select class="form-select" name="sex">
                                            <option value="M">M</option>
                                            <option value="F">F</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Course <span class="text-danger">*</span></label>
                                        <input class="form-control" name="course" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Major</label>
                                        <input class="form-control" name="major">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Year <span class="text-danger">*</span></label>
                                        <input class="form-control" name="year" required placeholder="e.g. 1st Year">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Profile Section <span class="text-danger">*</span></label>
                                        <input class="form-control" name="section" required placeholder="e.g. A">
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label">Email <span class="text-danger">*</span></label>
                                        <input class="form-control" type="email" name="email" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Campus <span class="text-danger">*</span></label>
                                        <select class="form-select" name="campus_id" required>
                                            <?php foreach ($campuses as $campus): ?>
                                                <?php $cid = (int) ($campus['id'] ?? 0); ?>
                                                <option value="<?php echo $cid; ?>" <?php echo $cid === (int) $selectedCampusId ? 'selected' : ''; ?>>
                                                    <?php
                                                    $campusName = trim((string) ($campus['campus_name'] ?? 'Campus'));
                                                    $campusCode = trim((string) ($campus['campus_code'] ?? ''));
                                                    echo htmlspecialchars($campusCode !== '' ? ($campusName . ' (' . $campusCode . ')') : $campusName);
                                                    ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <div class="alert alert-info mb-0" role="alert">
                                            Default credentials: <strong>Username = Student No.</strong> and <strong>Password = Student No.</strong><br>
                                            On first successful login, password change is required.
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <div class="alert alert-light border mb-0 py-2 px-3 small">
                                            Enrollment hierarchy: 1 Academic Year -> 2 Semester -> 3 Class Section -> 4 Subjects -> Submit
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label">1) Academic Year <span class="text-danger">*</span></label>
                                        <select class="form-select" name="academic_year" id="enrollAcademicYearSelect" required>
                                            <option value="">Select</option>
                                            <?php foreach ($academicYears as $ay): ?>
                                                <option value="<?php echo htmlspecialchars($ay); ?>"><?php echo htmlspecialchars($ay); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="text-muted">If blank, add Academic Years in Reference (Admin).</small>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">2) Semester <span class="text-danger">*</span></label>
                                        <select class="form-select" name="semester" id="enrollSemesterSelect" required>
                                            <option value="">Select</option>
                                            <?php foreach ($semesters as $sem): ?>
                                                <option value="<?php echo htmlspecialchars($sem); ?>"><?php echo htmlspecialchars($sem); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-md-12">
                                        <label class="form-label">3) Class Section <span class="text-danger">*</span></label>
                                        <input class="form-control" name="class_section" id="enrollClassSectionInput" required placeholder="e.g. IF-2-B-6" list="class-section-options">
                                        <datalist id="class-section-options">
                                            <?php foreach ($classSectionSuggestions as $sec): ?>
                                                <option value="<?php echo htmlspecialchars($sec); ?>"></option>
                                            <?php endforeach; ?>
                                        </datalist>
                                        <small class="text-muted">Use full class section code (not only A/B/1A).</small>
                                    </div>

                                    <div class="col-12">
                                        <label class="form-label">4) Select Subjects <span class="text-danger">*</span></label>
                                        <div class="row" id="enrollSubjectsGrid">
                                            <?php foreach ($subjects as $sub): ?>
                                                <?php
                                                $subjectAy = trim((string) ($sub['academic_year'] ?? ''));
                                                $subjectSem = trim((string) ($sub['semester'] ?? ''));
                                                ?>
                                                <div class="col-md-6 enroll-subject-item" data-academic-year="<?php echo htmlspecialchars($subjectAy, ENT_QUOTES); ?>" data-semester="<?php echo htmlspecialchars($subjectSem, ENT_QUOTES); ?>">
                                                    <div class="form-check">
                                                        <input class="form-check-input enroll-subject-checkbox" type="checkbox" name="subject_ids[]" value="<?php echo (int)$sub['id']; ?>" id="sub-<?php echo (int)$sub['id']; ?>">
                                                        <label class="form-check-label" for="sub-<?php echo (int)$sub['id']; ?>">
                                                            <?php echo htmlspecialchars($sub['subject_name'] . ' (' . $sub['subject_code'] . ')'); ?>
                                                        </label>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                            <div class="col-12 text-muted small" id="enrollSubjectsEmpty" style="display:none;">No subjects found for selected academic year/semester.</div>
                                        </div>
                                    </div>

                                    <div class="col-12 text-center mt-3">
                                        <button class="btn btn-primary" type="submit" id="enrollSubmitBtn">Submit Enrollment</button>
                                    </div>
                                </div>
                            </form>

                            <div class="text-center mt-3">
                                <p class="text-muted mb-0">Already have an account? <a href="auth-login.php" class="text-muted ms-1 link-offset-3 text-decoration-underline"><b>Log In</b></a></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/../../layouts/footer-scripts.php'; ?>
    <script src="assets/js/app.min.js"></script>
    <script>
        (function () {
            var aySelect = document.getElementById('enrollAcademicYearSelect');
            var semSelect = document.getElementById('enrollSemesterSelect');
            var classSectionInput = document.getElementById('enrollClassSectionInput');
            var subjectItems = Array.from(document.querySelectorAll('.enroll-subject-item'));
            var subjectGrid = document.getElementById('enrollSubjectsGrid');
            var emptyState = document.getElementById('enrollSubjectsEmpty');
            var submitBtn = document.getElementById('enrollSubmitBtn');

            function normalize(value) {
                return String(value || '').trim().toLowerCase();
            }

            function updateEnrollSubmitState() {
                var hasAy = normalize(aySelect ? aySelect.value : '') !== '';
                var hasSem = normalize(semSelect ? semSelect.value : '') !== '';
                var hasSection = normalize(classSectionInput ? classSectionInput.value : '') !== '';
                var hasSubjects = subjectItems.some(function (item) {
                    var checkbox = item.querySelector('.enroll-subject-checkbox');
                    return !!(checkbox && !checkbox.disabled && checkbox.checked);
                });
                if (submitBtn) submitBtn.disabled = !(hasAy && hasSem && hasSection && hasSubjects);
            }

            function refreshSubjectChoices() {
                var ay = normalize(aySelect ? aySelect.value : '');
                var sem = normalize(semSelect ? semSelect.value : '');
                var hasTerm = ay !== '' && sem !== '';
                var visibleCount = 0;

                subjectItems.forEach(function (item) {
                    var subjectAy = normalize(item.getAttribute('data-academic-year'));
                    var subjectSem = normalize(item.getAttribute('data-semester'));
                    var ayMatch = subjectAy === '' || subjectAy === ay;
                    var semMatch = subjectSem === '' || subjectSem === sem;
                    var show = ayMatch && semMatch;
                    if (!hasTerm) show = false;

                    item.style.display = show ? '' : 'none';
                    var checkbox = item.querySelector('.enroll-subject-checkbox');
                    if (checkbox) {
                        checkbox.disabled = !show;
                        if (!show) checkbox.checked = false;
                    }
                    if (show) visibleCount++;
                });

                if (classSectionInput) {
                    classSectionInput.disabled = !hasTerm;
                    if (!hasTerm) classSectionInput.value = '';
                }
                if (subjectGrid) subjectGrid.classList.toggle('opacity-50', !hasTerm);
                if (emptyState) emptyState.style.display = visibleCount === 0 ? '' : 'none';
                updateEnrollSubmitState();
            }

            if (aySelect) aySelect.addEventListener('change', refreshSubjectChoices);
            if (semSelect) semSelect.addEventListener('change', refreshSubjectChoices);
            if (classSectionInput) classSectionInput.addEventListener('input', updateEnrollSubmitState);
            subjectItems.forEach(function (item) {
                var checkbox = item.querySelector('.enroll-subject-checkbox');
                if (checkbox) checkbox.addEventListener('change', updateEnrollSubmitState);
            });
            refreshSubjectChoices();
        })();
    </script>
</body>
</html>



