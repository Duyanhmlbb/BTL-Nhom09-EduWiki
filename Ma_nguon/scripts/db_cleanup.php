<?php
require_once __DIR__ . '/../config/db.php';
$db = db();
$deleted = $db->exec("DELETE FROM users WHERE username LIKE 'user_a_%' OR username LIKE 'user_b_%' OR username LIKE 'user_g_%' OR username LIKE 'user_drive_%'");
echo "Cleaned up $deleted test users.\n";
