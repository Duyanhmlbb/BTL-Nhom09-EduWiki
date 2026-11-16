<?php
declare(strict_types=1);

// Thiết lập múi giờ Việt Nam (Asia/Ho_Chi_Minh - GMT+7) chuẩn cho toàn hệ thống
date_default_timezone_set('Asia/Ho_Chi_Minh');

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $configPath = __DIR__ . '/../.local/db.json';
        if (is_file($configPath)) {
            $config = json_decode((string) file_get_contents($configPath), true);
            if (!is_array($config)) {
                throw new RuntimeException('DATABASE_CONFIG_INVALID');
            }
            foreach (['PGHOST', 'PGPORT', 'PGDATABASE', 'PGUSER', 'PGPASSWORD'] as $key) {
                if (!isset($config[$key]) || !is_string($config[$key])) {
                    throw new RuntimeException('DATABASE_CONFIG_INVALID');
                }
                putenv($key . '=' . $config[$key]);
            }
        }

        $settings = [];
        foreach (['PGHOST', 'PGPORT', 'PGDATABASE', 'PGUSER', 'PGPASSWORD'] as $key) {
            $value = getenv($key);
            if ($value === false || $value === '') {
                throw new RuntimeException('DATABASE_CONFIG_MISSING');
            }
            $settings[$key] = $value;
        }

        $dsn = "pgsql:host={$settings['PGHOST']};port={$settings['PGPORT']};dbname={$settings['PGDATABASE']}";
        $pdo = new PDO($dsn, $settings['PGUSER'], $settings['PGPASSWORD'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // Đảm bảo session PostgreSQL cũng sử dụng chuẩn múi giờ Việt Nam (GMT+7)
        $pdo->exec("SET timezone TO 'Asia/Ho_Chi_Minh'");
    }
    return $pdo;
}