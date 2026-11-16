<?php
require __DIR__ . '/../config/db.php';
try {
    $pdo = db();
    $stmt = $pdo->query('SELECT current_database() AS db, current_user AS u');
    $v = $stmt->fetch(PDO::FETCH_ASSOC);
    echo 'CSDL=' . $v['db'] . ' | USER=' . $v['u'] . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'CHUA_SAN_SANG_CSDL: ' . $e->getMessage() . "\nChạy bash lab.sh db-config sau khi tạo CSDL lab.\n");
    exit(2);
}
