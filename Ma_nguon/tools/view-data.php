<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

try {
    $db = db();
    echo "\n========================================================================\n";
    echo " 📊 KIỂM TRA DỮ LIỆU ĐANG LƯU TRỮ TRONG HỆ THỐNG (VS CODE CHECK)\n";
    echo "========================================================================\n\n";

    // 1. Thống kê tổng quan
    $pagesCount = $db->query("SELECT count(*) FROM saved_pages")->fetchColumn();
    $snapsCount = $db->query("SELECT count(*) FROM snapshots")->fetchColumn();
    $assetsCount = $db->query("SELECT count(*) FROM assets")->fetchColumn();
    $libsCount = $db->query("SELECT count(*) FROM libraries")->fetchColumn();
    $usersCount = $db->query("SELECT count(*) FROM users")->fetchColumn();

    echo "▶ TỔNG QUAN HỆ THỐNG:\n";
    echo "   - Số bài viết đã lưu:   {$pagesCount} bài\n";
    echo "   - Số bản chụp snapshot: {$snapsCount} bản\n";
    echo "   - Số tệp ảnh ngoại tuyến: {$assetsCount} ảnh\n";
    echo "   - Số thư viện:          {$libsCount} thư viện\n";
    echo "   - Số tài khoản user:    {$usersCount} thành viên\n\n";

    // 2. Chi tiết từng bài viết đã lưu
    echo "▶ CHI TIẾT BÀI VIẾT ĐÃ LƯU (BẢNG saved_pages & snapshots):\n";
    echo str_pad("ID", 5) . str_pad("TIÊU ĐỀ", 40) . str_pad("THƯ VIỆN", 20) . str_pad("PHIÊN BẢN", 12) . "ẢNH OFFLINE\n";
    echo str_repeat("-", 85) . "\n";

    $stmt = $db->query("
        SELECT p.id, p.title, l.name as lib_name, 
               (SELECT count(*) FROM snapshots s WHERE s.saved_page_id = p.id) as total_versions,
               (SELECT max(version_number) FROM snapshots s WHERE s.saved_page_id = p.id) as latest_version,
               (SELECT count(*) FROM assets a WHERE a.saved_page_id = p.id) as total_assets
        FROM saved_pages p
        JOIN libraries l ON l.id = p.library_id
        ORDER BY p.id DESC
    ");

    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $id = (string)$r['id'];
        $title = mb_substr($r['title'], 0, 36) . (mb_strlen($r['title']) > 36 ? '...' : '');
        $lib = mb_substr($r['lib_name'], 0, 18);
        $ver = "v" . ($r['latest_version'] ?? '1') . " (" . $r['total_versions'] . " bản)";
        $assets = $r['total_assets'] . " tệp ảnh";

        echo str_pad($id, 5) . str_pad($title, 40) . str_pad($lib, 20) . str_pad($ver, 12) . $assets . "\n";
    }

    echo "\n========================================================================\n";
    echo " 📁 VỊ TRÍ CÁC TỆP ĐÃ LƯU TRÊN Ổ CỨNG (Xem ở cây thư mục VS Code bên trái):\n";
    echo "   1. Ảnh ngoại tuyến:   public/assets/saved_media/\n";
    echo "   2. File tĩnh offline: public/uploads/archive/\n";
    echo "========================================================================\n\n";

} catch (Throwable $e) {
    echo "LỖI KẾT NỐI: " . $e->getMessage() . "\n";
}
