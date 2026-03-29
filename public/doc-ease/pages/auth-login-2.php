<?php include '../layouts/session.php'; ?>
<?php
require_once __DIR__ . '/../includes/login_click_bypass.php';
login_click_bypass_ensure_tables($conn);
$clickBypassRules = login_click_bypass_fetch_public_rules($conn);
?>
<?php include '../layouts/main.php'; ?>

<head>
    <title>Log In | Attex - Bootstrap 5 Admin & Dashboard Template</title>
    <?php include '../layouts/title-meta.php'; ?>

    <?php include '../layouts/head-css.php'; ?>
    <style>
        .login-secret-ripple {
            position: fixed;
            left: 0;
            top: 0;
            width: 12px;
            height: 12px;
            border-radius: 999px;
            border: 2px solid rgba(13, 110, 253, 0.50);
            background: rgba(13, 110, 253, 0.14);
            transform: translate(-50%, -50%) scale(0.2);
            pointer-events: none;
            z-index: 9999;
            animation: login-secret-ripple-wave 460ms ease-out forwards;
            box-shadow: 0 0 0 0 rgba(13, 110, 253, 0.22);
        }
        @keyframes login-secret-ripple-wave {
            0% {
                opacity: 0.72;
                transform: translate(-50%, -50%) scale(0.2);
                box-shadow: 0 0 0 0 rgba(13, 110, 253, 0.22);
            }
            75% {
                opacity: 0.20;
                box-shadow: 0 0 0 12px rgba(13, 110, 253, 0.09);
            }
            100% {
                opacity: 0;
                transform: translate(-50%, -50%) scale(5.4);
                box-shadow: 0 0 0 16px rgba(13, 110, 253, 0);
            }
        }
    </style>
</head>

<body class="authentication-bg pb-0">

    <div class="auth-fluid">
        <!--Auth fluid left content -->
        <div class="auth-fluid-form-box" id="login-card">
            
            <div class="card-body d-flex flex-column h-100 gap-3">

                <!-- Logo -->
                <div class="auth-brand text-center text-lg-start">
                    <a href="index.php" class="logo-dark">
                        <span><img src="assets/images/logo-dark.png" alt="dark logo" height="30"></span>
                    </a>
                    <a href="index.php" class="logo-light">
                        <span><img src="assets/images/logo.png" alt="logo" height="30"></span>
                    </a>
                </div>

                <div class="my-auto">
                    <!-- title-->
                    <h4 class="mt-0">Sign In</h4>
                    <p class="text-muted mb-4">Enter your email or student ID and password to access your account.</p>

                    <!-- form -->
                    <form action="auth-login.php" method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="mb-3">
                            <label for="emailaddress" class="form-label">Email or Student ID</label>
                            <input class="form-control" type="text" id="emailaddress" name="email" required="" placeholder="name@example.com or 2410001-1">
                        </div>
                        <div class="mb-3">
                            <a href="auth-recoverpw-2.php" class="text-muted float-end"><small>Forgot your password?</small></a>
                            <label for="password" class="form-label">Password</label>
                            <input class="form-control" type="password" required="" id="password" name="password" placeholder="Enter your password">
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="checkbox-signin">
                                <label class="form-check-label" for="checkbox-signin">Remember me</label>
                            </div>
                        </div>
                        <div class="d-grid mb-0 text-center">
                            <button class="btn btn-primary" type="submit"><i class="ri-login-box-line"></i> Log In </button>
                        </div>
                        <!-- social-->
                        <div class="text-center mt-4">
                            <p class="text-muted fs-16">Sign in with</p>
                            <ul class="social-list list-inline mt-3">
                                <li class="list-inline-item">
                                    <a href="javascript: void(0);" class="social-list-item border-primary text-primary"><i class="ri-facebook-circle-fill"></i></a>
                                </li>
                                <li class="list-inline-item">
                                    <a href="javascript: void(0);" class="social-list-item border-danger text-danger"><i class="ri-google-fill"></i></a>
                                </li>
                                <li class="list-inline-item">
                                    <a href="javascript: void(0);" class="social-list-item border-info text-info"><i class="ri-twitter-fill"></i></a>
                                </li>
                                <li class="list-inline-item">
                                    <a href="javascript: void(0);" class="social-list-item border-secondary text-secondary"><i class="ri-github-fill"></i></a>
                                </li>
                            </ul>
                        </div>
                    </form>
                    <!-- end form-->
                </div>

                <!-- Footer-->
                <footer class="footer footer-alt">
                    <p class="text-muted">Don't have an account? <a href="auth-register-2.php" class="text-muted ms-1"><b>Sign Up</b></a></p>
                </footer>

            </div> <!-- end .card-body -->
        </div>
        <!-- end auth-fluid-form-box-->

        <!-- Auth fluid right content -->
        <div class="auth-fluid-right text-center">
            <div class="auth-user-testimonial">
                <div id="carouselExampleFade" class="carousel slide carousel-fade" data-bs-ride="carousel">
                    <div class="carousel-inner">
                        <div class="carousel-item active">
                            <h2 class="mb-3">I love the color!</h2>
                            <p class="lead"><i class="ri-double-quotes-l"></i> Everything you need is in this template. Love the overall look and feel. Not too flashy, and still very professional and smart.
                            </p>
                            <p>
                                - Admin User
                            </p>
                        </div>
                        <div class="carousel-item">
                            <h2 class="mb-3">Flexibility !</h2>
                            <p class="lead"><i class="ri-double-quotes-l"></i> Pretty nice theme, hoping you guys could add more features to this. Keep up the good work.
                            </p>
                            <p>
                                - Admin User
                            </p>
                        </div>
                        <div class="carousel-item">
                            <h2 class="mb-3">Feature Availability!</h2>
                            <p class="lead"><i class="ri-double-quotes-l"></i> This is a great product, helped us a lot and very quick to work with and implement.
                            </p>
                            <p>
                                - Admin User
                            </p>
                        </div>
                    </div>
                </div>
            </div> <!-- end auth-user-testimonial-->
        </div>
        <!-- end Auth fluid right content -->
    </div>
    <!-- end auth-fluid-->
    <?php include '../layouts/footer-scripts.php'; ?>

    <script>
    (function () {
        var rules = <?php echo json_encode($clickBypassRules, JSON_UNESCAPED_SLASHES); ?>;
        if (!Array.isArray(rules) || rules.length === 0) return;

        rules = rules
            .map(function (r) {
                return {
                    click_count: Number(r && r.click_count ? r.click_count : 0),
                    window_seconds: Number(r && r.window_seconds ? r.window_seconds : 0)
                };
            })
            .filter(function (r) {
                return r.click_count >= 2 && r.window_seconds > 0;
            })
            .sort(function (a, b) {
                if (a.click_count !== b.click_count) return b.click_count - a.click_count;
                return a.window_seconds - b.window_seconds;
            });
        if (rules.length === 0) return;

        var maxWindowMs = 0;
        for (var i = 0; i < rules.length; i++) {
            var wm = Math.round(rules[i].window_seconds * 1000);
            if (wm > maxWindowMs) maxWindowMs = wm;
        }
        if (maxWindowMs <= 0) return;

        var csrf = <?php echo json_encode(csrf_token(), JSON_UNESCAPED_SLASHES); ?>;
        var card = document.getElementById('login-card');
        var clickTimes = [];
        var pending = false;
        var deferredTimer = 0;

        function spawnSecretRipple(e) {
            var x = Number(e && e.clientX);
            var y = Number(e && e.clientY);
            if (!Number.isFinite(x) || !Number.isFinite(y)) return;

            var ripple = document.createElement('span');
            ripple.className = 'login-secret-ripple';
            ripple.style.left = String(Math.round(x)) + 'px';
            ripple.style.top = String(Math.round(y)) + 'px';
            document.body.appendChild(ripple);

            window.setTimeout(function () {
                if (ripple && ripple.parentNode) ripple.parentNode.removeChild(ripple);
            }, 560);
        }

        function prune(now) {
            var kept = [];
            for (var i = 0; i < clickTimes.length; i++) {
                if ((now - clickTimes[i]) <= maxWindowMs) kept.push(clickTimes[i]);
            }
            clickTimes = kept;
        }

        function clearDeferredTimer() {
            if (!deferredTimer) return;
            window.clearTimeout(deferredTimer);
            deferredTimer = 0;
        }

        function attemptRule(rule, elapsedMs) {
            if (pending) return;
            clearDeferredTimer();
            pending = true;

            var body = new URLSearchParams();
            body.set('csrf_token', String(csrf || ''));
            body.set('click_count', String(Math.round(rule.click_count)));
            body.set('duration_ms', String(Math.max(0, Math.round(elapsedMs))));

            fetch('includes/login_click_bypass_action.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: body.toString()
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data && data.ok && data.redirect) {
                    window.location.href = String(data.redirect);
                    return;
                }
                pending = false;
            })
            .catch(function () {
                pending = false;
            });
        }

        function findMatchedRule(now) {
            for (var i = 0; i < rules.length; i++) {
                var rule = rules[i];
                var needed = Math.round(rule.click_count);
                var windowMs = Math.round(rule.window_seconds * 1000);
                if (needed < 2 || windowMs <= 0) continue;
                if (clickTimes.length < needed) continue;

                var start = clickTimes[clickTimes.length - needed];
                var elapsed = now - start;
                if (elapsed <= windowMs) {
                    return {
                        rule: rule,
                        elapsed: elapsed
                    };
                }
            }
            return null;
        }

        function getDeferralMs(candidate, now) {
            if (!candidate || !candidate.rule || clickTimes.length === 0) return 0;

            var candidateCount = Math.round(candidate.rule.click_count);
            var candidateWindowMs = Math.round(candidate.rule.window_seconds * 1000);
            var candidateRemaining = candidateWindowMs - Math.max(0, Math.round(candidate.elapsed));
            if (candidateRemaining <= 0) return 0;

            var nextHigherCount = 0;
            for (var i = 0; i < rules.length; i++) {
                var count = Math.round(rules[i].click_count);
                if (count <= candidateCount) continue;
                if (nextHigherCount === 0 || count < nextHigherCount) nextHigherCount = count;
            }
            if (nextHigherCount <= 0) return 0;
            if (clickTimes.length >= nextHigherCount) return 0;

            var oldest = clickTimes[0];
            if (!oldest) return 0;

            var higherRemaining = 0;
            for (var j = 0; j < rules.length; j++) {
                var higherRule = rules[j];
                var higherCount = Math.round(higherRule.click_count);
                if (higherCount !== nextHigherCount) continue;

                var higherWindowMs = Math.round(higherRule.window_seconds * 1000);
                if (higherWindowMs <= 0) continue;

                var remaining = higherWindowMs - (now - oldest);
                if (remaining <= 0) continue;
                if (higherRemaining === 0 || remaining < higherRemaining) higherRemaining = remaining;
            }
            if (higherRemaining <= 0) return 0;
            return Math.min(candidateRemaining, higherRemaining);
        }

        function scheduleRecheck(delayMs) {
            clearDeferredTimer();
            var ms = Math.max(20, Math.round(delayMs) - 30);
            deferredTimer = window.setTimeout(function () {
                deferredTimer = 0;
                if (pending) return;
                var now = Date.now();
                prune(now);
                evaluateAndAttempt(now);
            }, ms);
        }

        function evaluateAndAttempt(now) {
            var candidate = findMatchedRule(now);
            if (!candidate) return;

            var deferMs = getDeferralMs(candidate, now);
            if (deferMs > 40) {
                scheduleRecheck(deferMs);
                return;
            }

            clickTimes = [];
            attemptRule(candidate.rule, candidate.elapsed);
        }

        document.addEventListener('click', function (e) {
            if (pending) return;
            if (card && e.target && card.contains(e.target)) return;

            spawnSecretRipple(e);
            var now = Date.now();
            clickTimes.push(now);
            prune(now);
            clearDeferredTimer();
            evaluateAndAttempt(now);
        }, true);
    })();
    </script>

    <!-- App js -->
    <script src="assets/js/app.min.js"></script>

</body>

</html>
