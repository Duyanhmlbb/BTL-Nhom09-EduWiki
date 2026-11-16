<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Ho_Chi_Minh');

require_once __DIR__ . '/../src/Auth.php';
$currentUser = Auth::user();
$currentScript = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Wiki Archiver - Hệ Thống Lưu Trữ & Tra Cứu Tư Liệu Web') ?></title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <style>
        :root {
            /* TÔNG MÀU HỌC THUẬT WIKIPEDIA / ACADEMIC BLUE & WARM AMBER */
            --wiki-blue: #0f4c81;
            --wiki-blue-hover: #0a3357;
            --wiki-blue-light: #e8f0fe;
            --wiki-amber: #d97706;
            --wiki-amber-light: #fef3c7;
            --wiki-border: #a2a9b1;
            --wiki-border-light: #c8ccd1;
            --wiki-bg: #f8f9fa;
            --card-bg: #ffffff;
            --text-main: #202122;
            --text-muted: #54595d;
            --link-color: #0645ad;
            --link-hover: #0b0080;
            --success: #137333;
            --danger: #b32424;
            --radius-sm: 4px;
            --radius-md: 8px;
            --radius-lg: 12px;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.06);
            --shadow-md: 0 4px 12px rgba(0,0,0,0.08);
        }

        * {
            box-sizing: border-box;
        }

        body, h1, h2, h3, h4, h5, h6, input, button, select, textarea {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", sans-serif;
        }

        html {
            height: 100%;
        }

        body {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            background-color: var(--wiki-bg);
            color: var(--text-main);
            margin: 0;
            padding: 0;
            line-height: 1.65;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            text-rendering: optimizeLegibility;
        }

        a {
            color: var(--link-color);
            text-decoration: none;
        }
        a:hover {
            text-decoration: underline;
        }

        /* KHUNG CHÍNH (CONTAINER) */
        .edu-container {
            flex: 1 0 auto;
            width: 100%;
            max-width: 1240px;
            margin: 0 auto;
            padding: 0 20px 40px;
        }

        /* TOPBAR WIKIPEDIA ACADEMIC */
        .edu-topbar {
            background: #ffffff;
            border-bottom: 1px solid var(--wiki-border-light);
            box-shadow: var(--shadow-sm);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .edu-topbar-inner {
            max-width: 1240px;
            margin: 0 auto;
            height: 64px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 20px;
            gap: 16px;
        }

        /* LOGO WIKIPEDIA STYLE */
        .edu-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            color: var(--text-main);
            flex-shrink: 0;
        }
        .edu-brand:hover {
            text-decoration: none;
        }

        .edu-brand-logo {
            width: 38px;
            height: 38px;
            border-radius: 8px;
            background: linear-gradient(135deg, #0f4c81 0%, #1e3a8a 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 20px;
            box-shadow: 0 2px 6px rgba(15, 76, 129, 0.3);
        }

        .edu-brand-text {
            display: flex;
            flex-direction: column;
        }
        .edu-brand-title {
            font-size: 19px;
            font-weight: 800;
            letter-spacing: -0.3px;
            color: var(--wiki-blue);
            line-height: 1.15;
            font-family: inherit;
        }
        .edu-brand-subtitle {
            font-size: 11px;
            color: var(--text-muted);
            letter-spacing: 0.2px;
            text-transform: uppercase;
        }

        /* THANH TÌM KIẾM TRUNG TÂM (ACADEMIC SEARCH BAR) */
        .edu-search-wrap {
            flex: 1;
            max-width: 580px;
        }
        .edu-search-form {
            display: flex;
            align-items: center;
            background: #f1f3f5;
            border: 1px solid var(--wiki-border-light);
            border-radius: 24px;
            padding: 6px 14px;
            gap: 8px;
            transition: all 0.2s ease;
        }
        .edu-search-form:focus-within {
            background: #ffffff;
            border-color: var(--wiki-blue);
            box-shadow: 0 0 0 3px rgba(15, 76, 129, 0.15);
        }
        .edu-search-input {
            border: none;
            background: transparent;
            outline: none;
            width: 100%;
            font-size: 14px;
            color: var(--text-main);
        }

        /* MENU ĐIỀU HƯỚNG TOPBAR */
        .edu-nav {
            display: flex;
            align-items: center;
            gap: 6px;
            list-style: none;
            margin: 0;
            padding: 0;
            flex-shrink: 0;
        }
        .edu-nav-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 12px;
            border-radius: var(--radius-sm);
            color: var(--text-main);
            font-weight: 600;
            font-size: 13.5px;
            text-decoration: none;
            transition: all 0.15s ease;
        }
        .edu-nav-link:hover {
            background: #eaecf0;
            color: var(--wiki-blue);
            text-decoration: none;
        }
        .edu-nav-link.active {
            background: var(--wiki-blue-light);
            color: var(--wiki-blue);
            font-weight: 700;
        }

        /* NÚT THAO TÁC / BUTTONS */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 8px 16px;
            font-size: 13.5px;
            font-weight: 600;
            border-radius: var(--radius-sm);
            border: 1px solid transparent;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.18s ease;
            gap: 6px;
            line-height: 1.3;
        }
        .btn:hover {
            text-decoration: none;
        }

        .btn-wiki {
            background: var(--wiki-blue);
            color: #ffffff;
            border-color: var(--wiki-blue);
        }
        .btn-wiki:hover {
            background: var(--wiki-blue-hover);
            color: #ffffff;
        }

        .btn-amber {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: #ffffff;
            border: none;
            box-shadow: 0 2px 6px rgba(217, 119, 6, 0.25);
        }
        .btn-amber:hover {
            background: linear-gradient(135deg, #d97706 0%, #b45309 100%);
            color: #ffffff;
        }

        .btn-outline {
            background: #ffffff;
            border: 1px solid var(--wiki-border);
            color: var(--text-main);
        }
        .btn-outline:hover {
            background: #f8f9fa;
            border-color: var(--wiki-blue);
            color: var(--wiki-blue);
        }

        .btn-sm {
            padding: 5px 11px;
            font-size: 12px;
        }

        /* CARD VÀ KHỐI NỘI DUNG */
        .card {
            background: var(--card-bg);
            border: 1px solid var(--wiki-border-light);
            border-radius: var(--radius-md);
            padding: 22px;
            margin-bottom: 20px;
            box-shadow: var(--shadow-sm);
        }
        .card-title {
            margin: 0 0 16px 0;
            font-size: 17px;
            font-weight: 700;
            color: var(--text-main);
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-family: inherit;
            border-bottom: 1px solid #eaecf0;
            padding-bottom: 8px;
        }

        /* FORM ELEMENTS */
        .form-group { margin-bottom: 14px; }
        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 6px;
            color: var(--text-main);
        }
        .form-control, .form-select {
            width: 100%;
            padding: 8px 12px;
            font-size: 14px;
            border: 1px solid var(--wiki-border-light);
            border-radius: var(--radius-sm);
            box-sizing: border-box;
            background: #ffffff;
            color: var(--text-main);
            transition: all 0.2s;
        }
        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: var(--wiki-blue);
            box-shadow: 0 0 0 3px rgba(15, 76, 129, 0.15);
        }

        /* HUY HIỆU (BADGES) */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 8px;
            font-size: 11.5px;
            font-weight: 600;
            border-radius: 4px;
            text-decoration: none;
            line-height: 1.4;
        }
        .badge-academic { background: var(--wiki-blue-light); color: var(--wiki-blue); border: 1px solid #cce0ff; }
        .badge-offline { background: #e6f4ea; color: #137333; border: 1px solid #ceead6; }
        .badge-amber { background: var(--wiki-amber-light); color: #92400e; border: 1px solid #fde68a; }
        .badge-tag { background: #f1f3f5; color: var(--text-muted); border: 1px solid #dadce0; }

        /* ALERTS */
        .alert {
            padding: 12px 16px;
            border-radius: var(--radius-sm);
            font-size: 13.5px;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-success { background: #e6f4ea; color: #137333; border: 1px solid #ceead6; }
        .alert-danger { background: #fce8e6; color: #c5221f; border: 1px solid #fad2cf; }
        .alert-info { background: #e8f0fe; color: #1a73e8; border: 1px solid #d2e3fc; }

        /* WIKIPEDIA ARTICLE READER STYLES */
        .wiki-article {
            background: #ffffff;
            border: 1px solid var(--wiki-border-light);
            border-radius: var(--radius-md);
            padding: 32px 40px;
            margin-bottom: 24px;
            box-shadow: var(--shadow-sm);
        }

        .wiki-heading-1 {
            font-family: inherit;
            font-size: 28px;
            font-weight: 700;
            margin: 0 0 6px 0;
            color: #000000;
            border-bottom: 1px solid var(--wiki-border);
            padding-bottom: 8px;
            line-height: 1.25;
        }

        .wiki-subtitle {
            font-size: 13px;
            color: var(--text-muted);
            margin: 0 0 18px 0;
            font-style: italic;
        }

        /* INFOBOX WIKIPEDIA BÊN PHẢI */
        .wiki-infobox {
            float: right;
            clear: right;
            margin: 0 0 16px 20px;
            width: 320px;
            background: #f8f9fa;
            border: 1px solid var(--wiki-border);
            padding: 12px 14px;
            font-size: 12.5px;
            border-radius: var(--radius-sm);
        }
        .wiki-infobox-header {
            font-weight: bold;
            text-align: center;
            background: var(--wiki-blue-light);
            color: var(--wiki-blue);
            padding: 6px 10px;
            margin: -12px -14px 10px -14px;
            font-size: 13.5px;
            border-bottom: 1px solid #cce0ff;
        }
        .wiki-infobox-row {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            border-bottom: 1px dashed #e2e8f0;
            gap: 8px;
        }
        .wiki-infobox-label {
            color: var(--text-muted);
            font-weight: 600;
        }
        .wiki-infobox-val {
            text-align: right;
            word-break: break-all;
        }

        /* MỤC LỤC TỰ ĐỘNG (TABLE OF CONTENTS) */
        .wiki-toc {
            background: #f8f9fa;
            border: 1px solid var(--wiki-border);
            padding: 12px 18px;
            display: inline-block;
            min-width: 240px;
            max-width: 100%;
            margin: 16px 0 24px 0;
            border-radius: var(--radius-sm);
            font-size: 13px;
        }
        .wiki-toc-title {
            font-weight: bold;
            text-align: center;
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: var(--text-main);
        }
        .wiki-toc ul {
            margin: 0;
            padding-left: 20px;
            list-style-type: decimal;
        }
        .wiki-toc li {
            margin-bottom: 4px;
        }

        /* NỘI DUNG VĂN BẢN ĐỌC LẠI */
        .wiki-body {
            font-size: 16px;
            line-height: 1.8;
            color: #202122;
        }
        .wiki-body h2 {
            font-family: inherit;
            font-size: 22px;
            font-weight: 700;
            border-bottom: 1px solid var(--wiki-border);
            padding-bottom: 4px;
            margin-top: 32px;
            margin-bottom: 14px;
            color: #000000;
        }
        .wiki-body h3 {
            font-size: 18px;
            font-weight: bold;
            margin-top: 24px;
            margin-bottom: 10px;
            color: #202122;
        }
        .wiki-body p {
            margin: 0 0 16px 0;
            text-align: justify;
        }
        .wiki-body img {
            max-width: 100%;
            height: auto;
            border: 1px solid var(--wiki-border-light);
            padding: 4px;
            background: #ffffff;
            margin: 16px auto;
            display: block;
            border-radius: var(--radius-sm);
        }
        .wiki-body blockquote {
            border-left: 4px solid var(--wiki-blue);
            margin: 18px 0;
            padding: 8px 18px;
            background: var(--wiki-bg);
            color: #4a5568;
            font-style: italic;
        }
        .wiki-body pre, .wiki-body code {
            font-family: Consolas, "Courier New", monospace;
            background: #f6f8fa;
            border-radius: 4px;
            font-size: 13.5px;
        }
        .wiki-body code {
            padding: 2px 6px;
            border: 1px solid #e1e4e8;
        }
        .wiki-body pre {
            padding: 14px;
            overflow-x: auto;
            border: 1px solid #e1e4e8;
            line-height: 1.5;
        }

        /* THẺ BÀI VIẾT TỔNG QUAN (ARTICLE SUMMARY CARD) */
        .wiki-card-item {
            border: 1px solid var(--wiki-border-light);
            border-radius: var(--radius-sm);
            padding: 16px;
            background: #ffffff;
            margin-bottom: 12px;
            transition: all 0.18s ease;
        }
        .wiki-card-item:hover {
            box-shadow: var(--shadow-sm);
            border-color: var(--wiki-blue);
            transform: translateY(-1px);
        }

        /* TÔ SÁNG TỪ KHÓA TÌM KIẾM */
        .kw-highlight {
            background-color: #fff3bf;
            color: #000000;
            padding: 1px 4px;
            border-radius: 2px;
            font-weight: 700;
        }

        /* LIÊN KẾT NGOẠI TUYẾN CHỈ XEM (DISABLED ARCHIVE LINKS) */
        .archived-disabled-link {
            color: #0645ad;
            text-decoration: underline;
            cursor: default;
            pointer-events: none;
            user-select: text;
        }
        /* Đảm bảo toàn bộ link trong thân bài viết lưu trữ không thể bấm nhảy ra ngoài */
        .wiki-body a:not([href^="#"]) {
            pointer-events: none !important;
            cursor: default !important;
            color: #0645ad !important;
            text-decoration: underline !important;
        }
        /* Giữ nguyên mục lục có thể bấm cuộn đến các phần trong bài */
        .wiki-toc a, .wiki-body a[href^="#"] {
            pointer-events: auto !important;
            cursor: pointer !important;
        }

        /* PHÂN TRANG (PAGINATION) */
        .edu-pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 6px;
            margin-top: 24px;
            padding-top: 18px;
            border-top: 1px solid var(--wiki-border-light);
            flex-wrap: wrap;
        }
        .edu-pagination-item {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 36px;
            height: 36px;
            padding: 0 12px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--wiki-border-light);
            background: #ffffff;
            color: var(--text-main);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.15s ease;
        }
        .edu-pagination-item:hover:not(.disabled):not(.active) {
            background: var(--wiki-blue-light);
            border-color: var(--wiki-blue);
            color: var(--wiki-blue);
            text-decoration: none;
        }
        .edu-pagination-item.active {
            background: var(--wiki-blue);
            border-color: var(--wiki-blue);
            color: #ffffff !important;
            font-weight: 700;
        }
        .edu-pagination-item.disabled {
            background: #f8f9fa;
            border-color: #e9ecef;
            color: #adb5bd;
            cursor: not-allowed;
            pointer-events: none;
        }
        .edu-pagination-info {
            font-size: 13px;
            color: var(--text-muted);
            margin: 0 8px;
        }

        @keyframes toastSlideIn {
            from {
                opacity: 0;
                transform: translateX(40px) translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateX(0) translateY(0);
            }
        }

        @media (max-width: 800px) {
            .wiki-infobox {
                float: none;
                width: 100%;
                margin: 0 0 20px 0;
            }
            .edu-search-wrap {
                display: none;
            }
            .wiki-article {
                padding: 20px;
            }
        }
    </style>
</head>
<body>

<!-- TOPBAR HỆ THỐNG -->
<header class="edu-topbar">
    <div class="edu-topbar-inner">
        <!-- LOGO & BRAND -->
        <a href="/index.php" class="edu-brand" title="Về Trang chủ Wiki Archiver">
            <div class="edu-brand-logo">📁</div>
            <div class="edu-brand-text">
                <span class="edu-brand-title">Wiki Archiver</span>
            </div>
        </a>

        <!-- MENU ĐIỀU HƯỚNG CHÍNH -->
        <ul class="edu-nav">
            <li>
                <a href="/index.php" class="edu-nav-link <?= in_array($currentScript, ['index.php', 'home.php'], true) ? 'active' : '' ?>">
                    🏛️ Trang chính (Thư viện chung)
                </a>
            </li>
            <?php if ($currentUser): ?>
                <li>
                    <a href="/libraries.php" class="edu-nav-link <?= $currentScript === 'libraries.php' ? 'active' : '' ?>" title="Kho tài liệu cá nhân">
                        📁 Kho của tôi
                    </a>
                </li>
                <li>
                    <a href="/groups.php" class="edu-nav-link <?= in_array($currentScript, ['groups.php', 'group_detail.php'], true) ? 'active' : '' ?>">
                        👥 Hội nhóm
                    </a>
                </li>
                <li>
                    <a href="/friends.php" class="edu-nav-link <?= $currentScript === 'friends.php' ? 'active' : '' ?>">
                        🤝 Bạn bè
                    </a>
                </li>
                <li>
                    <a href="/history.php" class="edu-nav-link <?= $currentScript === 'history.php' ? 'active' : '' ?>" title="Lịch sử bài viết đã đọc">
                        🕒 Lịch sử
                    </a>
                </li>
                <?php if (($currentUser['role'] ?? '') === 'admin'): ?>
                <li>
                    <a href="/admin.php" class="edu-nav-link <?= $currentScript === 'admin.php' ? 'active' : '' ?>" title="Bảng quản trị hệ thống">
                        ⚙️ Bảng quản trị
                    </a>
                </li>
                <?php endif; ?>
                <li>
                    <a href="/index.php?open_save=1" onclick="if(typeof openSaveModal === 'function'){ openSaveModal(); return false; }" class="btn btn-wiki btn-sm" style="color: #ffffff !important; gap: 4px;">
                        ➕ Lưu bài viết
                    </a>
                </li>
                <li>
                    <a href="/logout.php" class="btn btn-outline btn-sm" style="color: #b32424;">
                        Đăng xuất (<?= htmlspecialchars($currentUser['username']) ?>)
                    </a>
                </li>
            <?php else: ?>
                <li>
                    <a href="/login.php?msg=<?= urlencode('Vui lòng đăng nhập để lưu bài viết!') ?>" class="btn btn-wiki btn-sm" style="color: #ffffff !important; gap: 4px;">
                        ➕ Lưu bài viết
                    </a>
                </li>
                <li>
                    <a href="/login.php" class="btn btn-outline btn-sm">Đăng nhập</a>
                </li>
                <li>
                    <a href="/register.php" class="btn btn-wiki btn-sm">Đăng ký</a>
                </li>
            <?php endif; ?>
        </ul>
    </div>
</header>

<div class="edu-container">
