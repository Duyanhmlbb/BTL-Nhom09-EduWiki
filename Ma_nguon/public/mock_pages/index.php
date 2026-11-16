<?php
declare(strict_types=1);

$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1:8105');

$mockPages = [
    [
        'id' => 1,
        'file' => 'page01_oop_guide.html',
        'title' => 'Hướng dẫn Lập trình Hướng đối tượng OOP trong PHP',
        'category' => 'Bài chuẩn có cấu trúc',
        'badge' => 'Chuẩn',
        'badge_color' => '#10b981',
        'desc' => 'Bài viết kỹ thuật chuẩn có <article>, <h1>, mã code, danh mục và ảnh. Dùng kiểm tra bóc tách nội dung cơ bản.'
    ],
    [
        'id' => 2,
        'file' => 'page02_database_guide.html',
        'title' => 'Hướng dẫn sử dụng PostgreSQL và PDO trong PHP',
        'category' => 'Bài chuẩn có cấu trúc',
        'badge' => 'Chuẩn',
        'badge_color' => '#10b981',
        'desc' => 'Bài viết cơ sở dữ liệu có chứa code block PDO kết nối PostgreSQL và ảnh logo SVG.'
    ],
    [
        'id' => 3,
        'file' => 'page03_clean_code.html',
        'title' => 'Clean Code và Nghệ thuật Tái cấu trúc Mã nguồn trong PHP',
        'category' => 'Bài chuẩn có cấu trúc',
        'badge' => 'Chuẩn',
        'badge_color' => '#10b981',
        'desc' => 'Phân tích các nguyên tắc DRY, Single Responsibility và quy ước đặt tên.'
    ],
    [
        'id' => 4,
        'file' => 'page04_web_security.html',
        'title' => 'Bảo mật Ứng dụng Web: Phòng chống SQL Injection và CSRF',
        'category' => 'Bài chuẩn có cấu trúc',
        'badge' => 'Chuẩn',
        'badge_color' => '#10b981',
        'desc' => 'Hướng dẫn bảo mật, phòng chống tấn công chèn SQL và băm mật khẩu bcrypt.'
    ],
    [
        'id' => 5,
        'file' => 'page05_duplicate_title_part1.html',
        'title' => 'Kiến trúc Phát triển Web Hiện đại (Phần 1: Frontend)',
        'category' => 'Kiểm thử Tiêu đề Trùng',
        'badge' => 'Trùng Title (P1)',
        'badge_color' => '#f59e0b',
        'desc' => 'Tiêu đề giống hệt Bài 6 ("Kiến trúc Phát triển Web Hiện đại") nhưng nội dung về Frontend. Kiểm tra khả năng lưu trữ không bị ghi đè.'
    ],
    [
        'id' => 6,
        'file' => 'page06_duplicate_title_part2.html',
        'title' => 'Kiến trúc Phát triển Web Hiện đại (Phần 2: Backend)',
        'category' => 'Kiểm thử Tiêu đề Trùng',
        'badge' => 'Trùng Title (P2)',
        'badge_color' => '#f59e0b',
        'desc' => 'Tiêu đề giống hệt Bài 5 ("Kiến trúc Phát triển Web Hiện đại") nhưng nội dung về Backend. Kiểm tra độc lập phiên bản.'
    ],
    [
        'id' => 7,
        'file' => 'page07_offline_images.html',
        'title' => 'Tổng quan về Kiến trúc Điện toán Đám mây Cloud Computing',
        'category' => 'Kiểm thử Ảnh ngoại tuyến',
        'badge' => 'Đa Ảnh Offline',
        'badge_color' => '#3b82f6',
        'desc' => 'Chứa 3 hình ảnh minh họa trên web. Kiểm thử việc tải toàn bộ ảnh về máy chủ và nhúng Base64 để xem offline khi tắt mạng.'
    ],
    [
        'id' => 8,
        'file' => 'page08_youtube_embed.html',
        'title' => 'Tìm hiểu về Lịch sử và Văn hóa Phần mềm Mã nguồn mở',
        'category' => 'Kiểm thử Video nhúng',
        'badge' => 'YouTube Embed',
        'badge_color' => '#8b5cf6',
        'desc' => 'Chứa thẻ <iframe> nhúng video YouTube. Kiểm tra việc giữ lại nguồn an toàn được phép.'
    ],
    [
        'id' => 9,
        'file' => 'page09_xss_malicious_script.html',
        'title' => 'Trang thử nghiệm Mã độc XSS và Nội dung Không an toàn',
        'category' => 'Kiểm thử Bảo mật XSS',
        'badge' => 'Mã độc XSS',
        'badge_color' => '#ef4444',
        'desc' => 'Cố tình chèn thẻ <script>, iframe độc và thuộc tính onclick. Kiểm thử bộ lọc HtmlCleaner loại bỏ sạch mã độc khi lưu.'
    ],
    [
        'id' => 10,
        'file' => 'page10_error_404_mock.html',
        'title' => '404 Not Found - Mô phỏng trang không tồn tại',
        'category' => 'Kiểm thử Trang Lỗi',
        'badge' => 'Lỗi 404',
        'badge_color' => '#dc2626',
        'desc' => 'Mô phỏng trang 404. Hệ thống Wiki Archiver PHẢI từ chối lưu và báo lỗi theo đúng yêu cầu đề bài.'
    ],
    [
        'id' => 11,
        'file' => 'page11_short_notice.html',
        'title' => 'Thông báo bảo trì hệ thống (Trang quá ngắn)',
        'category' => 'Kiểm thử Trang Rác',
        'badge' => 'Trang Quá Ngắn',
        'badge_color' => '#d97706',
        'desc' => 'Trang chỉ có 1 câu ngắn. Hệ thống PHẢI từ chối lưu vì không có phần nội dung bài viết hợp lệ.'
    ],
    [
        'id' => 12,
        'file' => 'page12_deep_keyword.html',
        'title' => 'Cấu trúc Dữ liệu Nâng cao: Cây Tìm kiếm Nhị phân và Cây Đỏ Đen',
        'category' => 'Kiểm thử Tìm kiếm Sâu',
        'badge' => 'Từ khóa trong thân',
        'badge_color' => '#0284c7',
        'desc' => 'Chứa các từ khóa hiếm trong thân bài ("cây đỏ đen", "độ phức tạp logarit") để kiểm thử tính năng highlight từ khóa.'
    ],
    [
        'id' => 13,
        'file' => 'page13_version_original.html',
        'title' => 'Hướng dẫn Docker Cơ bản cho Lập trình viên PHP (Bản 1.0)',
        'category' => 'Kiểm thử Phiên bản',
        'badge' => 'Phiên bản 1.0',
        'badge_color' => '#059669',
        'desc' => 'Bản lưu ban đầu. Dùng làm bước 1 trong tình huống kiểm thử Cập nhật phiên bản mới.'
    ],
    [
        'id' => 14,
        'file' => 'page14_version_updated.html',
        'title' => 'Hướng dẫn Docker Cơ bản cho Lập trình viên PHP (Bản 2.0 Bổ sung)',
        'category' => 'Kiểm thử Phiên bản',
        'badge' => 'Phiên bản 2.0',
        'badge_color' => '#059669',
        'desc' => 'Bản cập nhật tiếp theo có thêm Docker Compose. Dùng kiểm tra việc lưu snapshot mới mà vẫn giữ snapshot cũ và ghi chú.'
    ],
    [
        'id' => 15,
        'file' => 'page15_long_academic.html',
        'title' => 'Nghiên cứu Mô hình Phát triển Linh hoạt Agile và Thực hành DevOps',
        'category' => 'Bài viết Học thuật Dài',
        'badge' => 'Đa đề mục',
        'badge_color' => '#475569',
        'desc' => 'Bài viết dài chia nhiều đề mục h2, h3, danh sách và hình minh họa để kiểm tra khả năng định dạng toàn vẹn.'
    ],
];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Danh mục 15 Trang HTML Mẫu — Đề tài 16 Wiki Archiver</title>
    <style>
        :root {
            --primary: #1a73e8;
            --primary-hover: #1557b0;
            --bg: #f8fafc;
            --card-bg: #ffffff;
            --border: #e2e8f0;
            --text-main: #1e293b;
            --text-muted: #64748b;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: var(--bg);
            color: var(--text-main);
            margin: 0;
            padding: 30px 20px;
            line-height: 1.5;
        }
        .container {
            max-width: 1100px;
            margin: 0 auto;
        }
        .header {
            background: #fff;
            padding: 24px 30px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            border: 1px solid var(--border);
            margin-bottom: 24px;
        }
        .header h1 {
            margin: 0 0 8px 0;
            font-size: 22px;
            color: #0f172a;
        }
        .header p {
            margin: 0;
            color: var(--text-muted);
            font-size: 14px;
        }
        .badge-nhom {
            display: inline-block;
            background: #e0f2fe;
            color: #0369a1;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        .nav-actions {
            margin-top: 16px;
            display: flex;
            gap: 12px;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            border: none;
            transition: all 0.15s;
        }
        .btn-primary {
            background: var(--primary);
            color: #fff;
        }
        .btn-primary:hover {
            background: var(--primary-hover);
        }
        .btn-outline {
            background: #fff;
            color: var(--text-main);
            border: 1px solid var(--border);
        }
        .btn-outline:hover {
            background: #f1f5f9;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(330px, 1fr));
            gap: 16px;
        }
        .card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 16px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            box-shadow: 0 1px 2px rgba(0,0,0,0.04);
            transition: transform 0.15s, box-shadow 0.15s;
        }
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.08);
        }
        .card-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .page-num {
            font-size: 12px;
            font-weight: 800;
            color: #94a3b8;
        }
        .tag-badge {
            font-size: 11px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 4px;
            color: #fff;
        }
        .card-title {
            font-size: 15px;
            font-weight: 700;
            margin: 0 0 8px 0;
            color: #0f172a;
            line-height: 1.4;
        }
        .card-desc {
            font-size: 13px;
            color: var(--text-muted);
            margin: 0 0 16px 0;
            flex-grow: 1;
        }
        .card-bottom {
            border-top: 1px solid #f1f5f9;
            padding-top: 12px;
            display: flex;
            gap: 8px;
        }
        .toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: #1e293b;
            color: #fff;
            padding: 10px 18px;
            border-radius: 8px;
            font-size: 13px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            display: none;
            z-index: 9999;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <span class="badge-nhom">NHÓM 9 — BTL PHÁT TRIỂN PHẦN MỀM MÃ NGUỒN MỞ</span>
        <h1>📁 Danh Mục 15 Trang HTML Mẫu Kiểm Thử (Mock Pages)</h1>
        <p>Phục vụ kiểm chứng và trình diễn bảo vệ Đề tài 16: Lưu nội dung trang Web để đọc lại và tìm kiếm (Wiki Archiver).</p>
        <p style="margin-top: 4px; font-size: 13px; color: #475569;">
            Thành viên: <strong>Phạm Duy Anh</strong>, <strong>Đỗ Nam Khánh</strong>, <strong>Nguyễn Trung Đức</strong>
        </p>
        <div class="nav-actions">
            <a href="/" class="btn btn-primary">&larr; Quay về ứng dụng Wiki Archiver</a>
            <button onclick="copyAllUrls()" class="btn btn-outline">📋 Sao chép danh sách URL</button>
        </div>
    </div>

    <div class="grid">
        <?php foreach ($mockPages as $p): ?>
            <?php $fullUrl = $baseUrl . '/mock_pages/' . $p['file']; ?>
            <div class="card">
                <div>
                    <div class="card-top">
                        <span class="page-num">#PAGE_<?= str_pad((string)$p['id'], 2, '0', STR_PAD_LEFT) ?></span>
                        <span class="tag-badge" style="background: <?= $p['badge_color'] ?>;">
                            <?= htmlspecialchars($p['badge']) ?>
                        </span>
                    </div>
                    <h3 class="card-title"><?= htmlspecialchars($p['title']) ?></h3>
                    <p class="card-desc"><?= htmlspecialchars($p['desc']) ?></p>
                </div>
                <div class="card-bottom">
                    <button class="btn btn-primary btn-sm" style="flex: 1;" onclick="copyUrl('<?= $fullUrl ?>')">
                        📋 Copy Link URL
                    </button>
                    <a href="/mock_pages/<?= $p['file'] ?>" target="_blank" class="btn btn-outline btn-sm" title="Mở xem trước trang web mẫu">
                        👁️ Xem thử
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div id="toast" class="toast">Đã sao chép link URL vào bộ nhớ tạm!</div>

<script>
function copyUrl(url) {
    navigator.clipboard.writeText(url).then(function() {
        const t = document.getElementById('toast');
        t.innerText = 'Đã sao chép link: ' + url;
        t.style.display = 'block';
        setTimeout(() => { t.style.display = 'none'; }, 2500);
    }).catch(function() {
        prompt('Vui lòng sao chép link URL dưới đây:', url);
    });
}

function copyAllUrls() {
    const urls = <?= json_encode(array_map(fn($p) => $baseUrl . '/mock_pages/' . $p['file'], $mockPages), JSON_UNESCAPED_SLASHES) ?>;
    navigator.clipboard.writeText(urls.join("\n")).then(function() {
        const t = document.getElementById('toast');
        t.innerText = 'Đã sao chép toàn bộ 15 link URL!';
        t.style.display = 'block';
        setTimeout(() => { t.style.display = 'none'; }, 2500);
    });
}
</script>

</body>
</html>
