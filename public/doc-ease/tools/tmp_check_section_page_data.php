<?php
require __DIR__ . '/../config/db.php';
$tables = ['sections','students','section_subjects'];
foreach ($tables as $t) {
  $r = $conn->query("SELECT COUNT(*) AS c FROM {$t}");
  $c = $r ? (int)($r->fetch_assoc()['c'] ?? 0) : -1;
  echo $t . ': ' . $c . "\n";
}
$r = $conn->query("SELECT id, name, status, created_at FROM sections ORDER BY created_at DESC LIMIT 20");
echo "\nsections sample:\n";
while ($r && $row = $r->fetch_assoc()) {
  echo '  #' . (int)$row['id'] . ' | ' . (string)$row['name'] . ' | ' . (string)$row['status'] . ' | ' . (string)$row['created_at'] . "\n";
}
