<?php include '../layouts/session.php'; ?>
<?php
$logoutCsrf = function_exists('csrf_token') ? (string) csrf_token() : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Logging Out</title>
</head>
<body>
    <form id="logoutForwardForm" action="auth-logout.php" method="post">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($logoutCsrf, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="reason" value="logout">
        <noscript>
            <button type="submit">Continue Logout</button>
        </noscript>
    </form>
    <script>
    (function () {
        var f = document.getElementById('logoutForwardForm');
        if (!f) return;
        f.submit();
    })();
    </script>
</body>
</html>
