<?php include __DIR__ . '/../../layouts/session.php'; ?>
<?php require_approved_student(); ?>
<?php
header('Location: ../student-dashboard.php');
exit;
?>
