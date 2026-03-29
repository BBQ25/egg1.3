<?php include __DIR__ . '/../../layouts/session.php'; ?>
<?php include __DIR__ . '/../../layouts/main.php'; ?>

<?php
$error = '';
$success = '';
$campuses = (isset($conn) && $conn instanceof mysqli) ? campus_list($conn, true) : [];
$defaultCampusId = (isset($conn) && $conn instanceof mysqli) ? campus_default_id($conn) : 0;
$selectedCampusId = $defaultCampusId;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    $fullname = isset($_POST['fullname']) ? trim($_POST['fullname']) : '';
    $email = isset($_POST['emailaddress']) ? trim($_POST['emailaddress']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $selectedCampusId = isset($_POST['campus_id']) ? (int) $_POST['campus_id'] : $defaultCampusId;
    if ($selectedCampusId <= 0) $selectedCampusId = $defaultCampusId;

    if (!csrf_validate($csrf)) {
        $error = 'Security check failed. Please refresh and try again.';
    } elseif ($fullname === '' || $email === '' || $password === '') {
        $error = 'All fields are required.';
    } elseif ($selectedCampusId <= 0) {
        $error = 'Campus selection is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
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
        // Check if email already exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE useremail = ?");
        if ($stmt) {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) {
                $error = 'Email already registered.';
            } else {
                // Hash password
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $role = 'student';
                $isActive = 0;

                // Insert student account as pending approval
                $insertStmt = $conn->prepare("INSERT INTO users (useremail, username, password, role, is_active, campus_id, is_superadmin) VALUES (?, ?, ?, ?, ?, ?, 0)");
                if ($insertStmt) {
                    $insertStmt->bind_param("ssssii", $email, $fullname, $hashed_password, $role, $isActive, $selectedCampusId);
                    if ($insertStmt->execute()) {
                        $success = 'Registration submitted. Please wait for admin approval.';
                    } else {
                        $error = 'Registration failed.';
                    }
                    $insertStmt->close();
                } else {
                    $error = 'Registration failed.';
                }
            }
            $stmt->close();
        } else {
            $error = 'Registration failed.';
        }
    }
}
?>

<head>
    <title>Register | Attex - Bootstrap 5 Admin & Dashboard Template</title>
    <?php include __DIR__ . '/../../layouts/title-meta.php'; ?>

    <?php include __DIR__ . '/../../layouts/head-css.php'; ?>
</head>

<body class="authentication-bg">

<?php include __DIR__ . '/../../layouts/background.php'; ?>

    <div class="account-pages pt-2 pt-sm-5 pb-4 pb-sm-5 position-relative">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-xxl-4 col-lg-5">
                    <div class="card">
                        <!-- Logo-->
                        <div class="card-header py-4 text-center bg-primary">
                            <a href="index.php">
                                <span><img src="assets/images/logo.png" alt="logo" height="30"></span>
                            </a>
                        </div>

                        <div class="card-body p-4">

                            <div class="text-center w-75 m-auto">
                                <h4 class="text-dark-50 text-center mt-0 fw-bold">Free Sign Up</h4>
                                <p class="text-muted mb-4">Don't have an account? Create your account, it takes less than a minute </p>
                            </div>

                            <?php if ($error): ?>
                                <div class="alert alert-danger" role="alert">
                                    <?php echo htmlspecialchars($error); ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($success): ?>
                                <div class="alert alert-success" role="alert">
                                    <?php echo htmlspecialchars($success); ?>
                                </div>
                            <?php endif; ?>

                            <form action="auth-register.php" method="post">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">

                                <div class="mb-3">
                                    <label for="fullname" class="form-label">Full Name</label>
                                    <input class="form-control" type="text" id="fullname" name="fullname" placeholder="Enter your name" required>
                                </div>

                                <div class="mb-3">
                                    <label for="emailaddress" class="form-label">Email address</label>
                                    <input class="form-control" type="email" id="emailaddress" name="emailaddress" required placeholder="Enter your email">
                                </div>

                                <div class="mb-3">
                                    <label for="campus_id" class="form-label">Campus</label>
                                    <select class="form-select" id="campus_id" name="campus_id" required>
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

                                <div class="mb-3">
                                    <label for="password" class="form-label">Password</label>
                                    <div class="input-group input-group-merge">
                                        <input type="password" id="password" name="password" class="form-control" placeholder="Enter your password">
                                        <div class="input-group-text" data-password="false">
                                            <span class="password-eye"></span>
                                        </div>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input" id="checkbox-signup">
                                        <label class="form-check-label" for="checkbox-signup">I accept <a href="#" class="text-muted">Terms and Conditions</a></label>
                                    </div>
                                </div>

                                <div class="mb-3 text-center">
                                    <button class="btn btn-primary" type="submit"> Sign Up </button>
                                </div>

                            </form>
                        </div> <!-- end card-body -->
                    </div>
                    <!-- end card -->

                    <div class="row mt-3">
                        <div class="col-12 text-center">
                            <p class="text-muted bg-body">Already have account? <a href="auth-login.php" class="text-muted ms-1 link-offset-3 text-decoration-underline"><b>Log In</b></a></p>
                        </div> <!-- end col-->
                    </div>
                    <!-- end row -->

                </div> <!-- end col -->
            </div>
            <!-- end row -->
        </div>
        <!-- end container -->
    </div>
    <!-- end page -->

    <footer class="footer footer-alt fw-medium">
        <span class="bg-body">
            2026 @ Ryhn Solutions
        </span>
    </footer>

    <?php include __DIR__ . '/../../layouts/footer-scripts.php'; ?>

    <!-- App js -->
    <script src="assets/js/app.min.js"></script>

</body>

</html>


