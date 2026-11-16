<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../src/ArchiveRepository.php';
require_once __DIR__ . '/../src/GroupRepository.php';
require_once __DIR__ . '/../src/WebFetcher.php';
require_once __DIR__ . '/../src/Auth.php';

$currentUser = Auth::user();
$userId = $currentUser ? (int)$currentUser['id'] : null;

$repo = new ArchiveRepository(db(), $userId);
$userGroupsRaw = $userId !== null ? (new GroupRepository(db()))->getGroupsForUser($userId) : [];
$userGroups = array_filter($userGroupsRaw, function($grp) use ($currentUser) {
    if (($currentUser['role'] ?? '') === 'admin') return true;
    return in_array($grp['my_role'] ?? '', ['owner', 'admin', 'editor'], true);
});
$pageId = (int)($_GET['id'] ?? 0);
$versionParam = isset($_GET['version']) ? (int)$_GET['version'] : null;

$message = $_GET['msg'] ?? null;
$error = $_GET['error'] ?? null;

// =========================================================================
// XỬ LÝ TRA CỨU LIÊN KẾT NỘI BỘ (INTERNAL ARCHIVE LINK LOOKUP)
// =========================================================================
if (isset($_GET['lookup_url'])) {
    $lookupUrl = trim((string)$_GET['lookup_url']);
    if ($lookupUrl !== '') {
        $stmtFind = db()->prepare("SELECT id FROM saved_pages WHERE url = ? ORDER BY id DESC LIMIT 1");
        $stmtFind->execute([$lookupUrl]);
        $foundPageId = $stmtFind->fetchColumn();
        if ($foundPageId) {
            header('Location: /view.php?id=' . (int)$foundPageId);
            exit;
        }

        // Nếu chưa lưu trữ, hiển thị màn hình thông báo và cho phép 1-click lưu trữ
        $pageTitle = 'Tra cứu kho lưu trữ - Wiki Archiver';
        require_once __DIR__ . '/../templates/header.php';
        ?>
        <div style="max-width: 680px; margin: 40px auto; padding: 28px;" class="card">
            <div style="display: flex; align-items: center; gap: 14px; margin-bottom: 18px;">
                <div style="font-size: 36px;">🔍</div>
                <div>
                    <h2 style="margin: 0; font-size: 20px; font-weight: 700; color: var(--wiki-blue);">Tra Cứu Kho Lưu Trữ Ngoại Tuyến</h2>
                    <p style="margin: 4px 0 0; font-size: 13px; color: var(--text-muted);">Hệ thống lưu trữ và bảo tồn tư liệu web Wiki Archiver</p>
                </div>
            </div>
            
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px; margin-bottom: 18px; font-size: 13.5px; word-break: break-all;">
                <div style="font-size: 12px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 4px;">Liên kết tra cứu:</div>
                <a href="<?= htmlspecialchars($lookupUrl) ?>" target="_blank" rel="noopener noreferrer" style="color: var(--link-color); font-weight: 500;">
                    <?= htmlspecialchars($lookupUrl) ?> ↗
                </a>
            </div>

            <div style="background: #fffbeb; border: 1.5px solid #fde68a; border-radius: 6px; padding: 14px 18px; margin-bottom: 24px; font-size: 13.5px; color: #92400e;">
                ⚠️ <strong>Chưa có trong Kho lưu trữ:</strong> Bài viết liên kết này hiện chưa được sao lưu cục bộ trong hệ thống.
            </div>

            <?php if ($currentUser): ?>
                <form method="POST" action="/index.php">
                    <input type="hidden" name="action" value="save_page">
                    <input type="hidden" name="url" value="<?= htmlspecialchars($lookupUrl) ?>">
                    <input type="hidden" name="library_choice" value="1">
                    <button type="submit" class="btn btn-wiki" style="color: #fff !important; width: 100%; padding: 12px; font-size: 14.5px; font-weight: 700;">
                        📥 Tải & Lưu Trữ Ngoại Tuyến Trang Này Ngay Bây Giờ &rarr;
                    </button>
                </form>
            <?php else: ?>
                <a href="/login.php?msg=<?= urlencode('Vui lòng đăng nhập để lưu trữ tài liệu mới!') ?>" class="btn btn-wiki" style="display: block; text-align: center; color: #fff !important; padding: 12px; font-size: 14.5px; font-weight: 700;">
                    🔑 Đăng nhập để lưu bài viết này vào Thư viện
                </a>
            <?php endif; ?>

            <div style="text-align: center; margin-top: 18px;">
                <a href="javascript:history.back()" style="font-size: 13px; color: var(--text-muted);">&larr; Quay lại trang trước</a>
            </div>
        </div>
        <?php
        require_once __DIR__ . '/../templates/footer.php';
        exit;
    }
}

// =========================================================================
// XỬ LÝ 1: XUẤT BÀI VIẾT ĐỘC LẬP (EXPORT PORTABLE OFFLINE HTML / PDF / WORD)
// =========================================================================
if (isset($_GET['action']) && $_GET['action'] === 'export') {
    $exportFormat = strtolower((string)($_GET['format'] ?? 'html'));
    if (!in_array($exportFormat, ['html', 'pdf', 'docx', 'doc'], true)) {
        $exportFormat = 'html';
    }

    // Yêu cầu bắt buộc đăng nhập để tải bài về từ thư viện
    if (!$currentUser) {
        header('Location: /login.php?msg=' . urlencode('Vui lòng đăng nhập để tải bài viết về máy!') . '&redirect=' . urlencode('/view.php?id=' . $pageId . '&action=export&format=' . $exportFormat));
        exit;
    }

    $exportVersion = isset($_GET['version']) ? (int)$_GET['version'] : null;
    $exportData = $repo->getPageDetails($pageId, $exportVersion, $userId);

    if (!$exportData) {
        die('Không tìm thấy tư liệu hoặc tài liệu đã bị xóa.');
    }

    // Kiểm tra quyền tải tài liệu của người dùng
    $exportPerm = $repo->getUserPagePermission($pageId, $userId, $currentUser['role'] ?? 'member');
    if (empty($exportPerm['can_download'])) {
        die('Bạn không có quyền tải tài liệu này về máy (Quyền tải về của bạn đã bị khóa bởi người quản trị).');
    }

    $rawTitle = (string)$exportData['title'];
    $slugTitle = preg_replace('/[^a-zA-Z0-9_\x{00C0}-\x{024F}\x{1E00}-\x{1EFF}-]/u', '_', $rawTitle);
    $slugTitle = trim(preg_replace('/_+/', '_', $slugTitle), '_');
    if ($slugTitle === '') $slugTitle = 'Document';
    $baseFilename = 'WikiArchiver_' . mb_substr($slugTitle, 0, 60) . '_v' . $exportData['version_number'];

    $safeAsciiFilename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $baseFilename);
    $safeAsciiFilename = trim(preg_replace('/_+/', '_', $safeAsciiFilename), '_');
    if ($safeAsciiFilename === '') $safeAsciiFilename = 'WikiArchiver_Document';

    $tagsHtml = '';
    foreach ($exportData['tags'] as $t) {
        $tagsHtml .= '<span style="display:inline-block;background:#e8f0fe;color:#0f4c81;padding:2px 8px;border-radius:12px;font-size:12px;margin-right:6px;">#' . htmlspecialchars($t['name']) . '</span>';
    }

    $notesHtml = '';
    if (!empty($exportData['notes'])) {
        $notesHtml .= '<div style="margin-top:30px;padding:20px;background:#f8f9fa;border-left:4px solid #0f4c81;border-radius:6px;">';
        $notesHtml .= '<h3 style="margin-top:0;font-size:16px;">📝 Ghi chú tư liệu:</h3><ul style="margin:0;padding-left:20px;font-size:14px;color:#333;">';
        foreach ($exportData['notes'] as $n) {
            $notesHtml .= '<li style="margin-bottom:8px;"><strong>[' . date('d/m/Y H:i', strtotime($n['created_at'])) . ']</strong> ' . htmlspecialchars($n['content']) . '</li>';
        }
        $notesHtml .= '</ul></div>';
    }

    // =========================================================
    // NHÚNG ẢNH DẠNG BASE64 ĐỂ XEM ĐƯỢC OFFLINE KHÔNG CẦN SERVER
    // =========================================================
    $storageDir = dirname(__DIR__) . '/public/assets/saved_media/';
    $contentHtml = $exportData['content_html'];
    $assetCount = 0;
    $assetMissing = 0;

    // Hàm nhận diện MIME type chính xác từ nội dung nhị phân (tránh việc file WebP nhưng mang đuôi .png/.jpg)
    $detectMime = function(string $filePath, ?string $rawBytes = null): string {
        $header = $rawBytes ?? (file_exists($filePath) ? (string)@file_get_contents($filePath, false, null, 0, 32) : '');
        if (str_starts_with($header, "\xFF\xD8\xFF")) return 'image/jpeg';
        if (str_starts_with($header, "\x89PNG\r\n\x1a\n")) return 'image/png';
        if (str_starts_with($header, "GIF87a") || str_starts_with($header, "GIF89a")) return 'image/gif';
        if (str_starts_with($header, "RIFF") && str_contains(substr($header, 0, 16), "WEBP")) return 'image/webp';
        if (str_contains(substr($header, 0, 64), "<svg") || str_contains(substr($header, 0, 64), "<?xml")) return 'image/svg+xml';
        
        return 'image/jpeg';
    };

    // Duyệt qua tất cả các thẻ <img> trong HTML bài viết
    $contentHtml = preg_replace_callback(
        '/<img\b([^>]*)>/is',
        function (array $match) use ($storageDir, &$assetCount, &$assetMissing, $detectMime): string {
            $fullTag = $match[0];

            // 1. Loại bỏ hoàn toàn srcset và sizes để trình duyệt offline không cố tải từ Wikipedia/Internet
            $cleanTag = preg_replace('/\s+(?:srcset|sizes)=([\'"]).*?\1/is', '', $fullTag);
            // 2. Chuyển loading="lazy" thành loading="eager" để ảnh hiện ngay khi mở offline
            $cleanTag = preg_replace('/\bloading=([\'"])lazy\1/i', 'loading="eager"', $cleanTag);

            // 3. Tìm đường dẫn ảnh hiện tại (ưu tiên src thực sự, tránh nhầm data-original-src)
            $srcUrl = null;
            if (preg_match('/(?<![a-zA-Z0-9_-])src=([\'"])(.*?)\1/i', $cleanTag, $mSrc)) {
                $srcUrl = trim($mSrc[2]);
            }
            if (!$srcUrl && preg_match('/\bdata-src=([\'"])(.*?)\1/i', $cleanTag, $mDSrc)) {
                $srcUrl = trim($mDSrc[2]);
            }

            if ($srcUrl) {
                // Trường hợp A: Đường dẫn nội bộ /assets/saved_media/filename
                if (preg_match('/\/assets\/saved_media\/([^\/?"\']+)/i', $srcUrl, $mFile)) {
                    $fileName = basename($mFile[1]);
                    $filePath = $storageDir . $fileName;

                    if (file_exists($filePath) && filesize($filePath) > 0) {
                        $assetCount++;
                        $mime = $detectMime($filePath);
                        $raw = (string)file_get_contents($filePath);
                        $b64 = base64_encode($raw);
                        $dataUri = 'data:' . $mime . ';base64,' . $b64;

                        // Thay thế chính xác thuộc tính src bằng base64
                        if (preg_match('/(?<![a-zA-Z0-9_-])src=([\'"]).*?\1/i', $cleanTag)) {
                            $cleanTag = preg_replace('/(?<![a-zA-Z0-9_-])src=([\'"]).*?\1/i', 'src="' . $dataUri . '"', $cleanTag, 1);
                        } else {
                            $cleanTag = preg_replace('/<img\b/i', '<img src="' . $dataUri . '"', $cleanTag);
                        }
                        // Xóa các thuộc tính data-src, data-original, data-original-src thừa
                        $cleanTag = preg_replace('/\s+data-(?:src|original|original-src)=([\'"]).*?\1/is', '', $cleanTag);
                        return $cleanTag;
                    } else {
                        $assetMissing++;
                    }
                }
                // Trường hợp B: Đã là data URI rồi thì giữ nguyên
                elseif (str_starts_with($srcUrl, 'data:image/')) {
                    $assetCount++;
                    return $cleanTag;
                }
                // Trường hợp C: URL online còn sót lại (https://...) -> cố gắng fetch nhanh để nhúng base64
                elseif (preg_match('/^https?:\/\//i', $srcUrl)) {
                    $ctx = stream_context_create([
                        'http' => ['timeout' => 4, 'header' => "User-Agent: Mozilla/5.0\r\n"],
                        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false]
                    ]);
                    $onlineBytes = @file_get_contents($srcUrl, false, $ctx);
                    if ($onlineBytes !== false && strlen($onlineBytes) > 0) {
                        $assetCount++;
                        $mime = $detectMime('', $onlineBytes);
                        $dataUri = 'data:' . $mime . ';base64,' . base64_encode($onlineBytes);
                        $cleanTag = preg_replace('/(?<![a-zA-Z0-9_-])src=([\'"]).*?\1/i', 'src="' . $dataUri . '"', $cleanTag, 1);
                        $cleanTag = preg_replace('/\s+data-(?:src|original|original-src)=([\'"]).*?\1/is', '', $cleanTag);
                        return $cleanTag;
                    }
                }
            }

            return $cleanTag;
        },
        $contentHtml
    );

    $imageStats = $assetCount > 0
        ? "✅ {$assetCount} hình ảnh đã được nhúng trực tiếp dạng Base64 vào file (mở xem ngoại tuyến hoàn toàn không cần kết nối mạng)."
        : "⚠️ Không tìm thấy hình ảnh cục bộ nào để nhúng.";
    if ($assetMissing > 0) {
        $imageStats .= " ({$assetMissing} ảnh không tìm thấy tệp gốc trên máy chủ.)";
    }

    // Soạn thảo toàn bộ tài liệu HTML chuẩn bị xuất
    $fullHtmlDoc = '<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>' . htmlspecialchars($exportData['title']) . ' - Wiki Archiver</title>
    <style>
        @page {
            size: A4;
            margin: 20mm 15mm 20mm 15mm;
            @bottom-center {
                content: "Trang " counter(page) " / " counter(pages);
                font-size: 9pt;
                color: #718096;
            }
        }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; line-height: 1.8; color: #202122; max-width: 900px; margin: 40px auto; padding: 0 20px; }
        .meta-box { background: #f8f9fa; border: 1px solid #a2a9b1; padding: 16px 20px; border-radius: 6px; margin-bottom: 24px; font-size: 13px; color: #54595d; }
        .meta-box a { color: #0645ad; word-break: break-all; }
        h1 { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; font-size: 28px; border-bottom: 1px solid #a2a9b1; padding-bottom: 8px; margin-top: 0; }
        img { max-width: 100%; height: auto; border: 1px solid #c8ccd1; border-radius: 4px; display: block; margin: 16px auto; }
        pre, code { font-family: monospace; background: #f6f8fa; border-radius: 3px; }
        pre { padding: 12px; overflow-x: auto; }
        blockquote { border-left: 4px solid #0f4c81; padding-left: 14px; margin: 14px 0; color: #555; }
        table { border-collapse: collapse; width: 100%; margin: 16px 0; }
        th, td { border: 1px solid #a2a9b1; padding: 8px 12px; text-align: left; }
        th { background: #f8f9fa; }
        .archived-disabled-link, .article-body a:not([href^="#"]) { color: #0645ad; text-decoration: underline; cursor: default; pointer-events: none; }
    </style>
</head>
<body>
    <h1>' . htmlspecialchars($exportData['title']) . '</h1>
    <div class="meta-box">
        <p style="margin: 0 0 6px 0;"><strong>Wiki Archiver — Bản lưu trữ ngoại tuyến độc lập</strong></p>
        <p style="margin: 4px 0;"><strong>Liên kết nguồn:</strong> <a href="' . htmlspecialchars($exportData['url']) . '">' . htmlspecialchars($exportData['url']) . '</a></p>
        <p style="margin: 4px 0;"><strong>Thời điểm lưu trữ:</strong> ' . date('d/m/Y H:i:s', strtotime($exportData['fetched_at'])) . ' | <strong>Phiên bản:</strong> v' . $exportData['version_number'] . ' | <strong>Chuyên mục:</strong> ' . htmlspecialchars($exportData['library_name']) . '</p>
        <p style="margin: 4px 0;"><strong>Nhãn:</strong> ' . ($tagsHtml !== '' ? $tagsHtml : 'Không có') . '</p>
        <p style="margin: 4px 0; color: #137333;"><strong>Hình ảnh:</strong> ' . $imageStats . '</p>
    </div>
    <div class="article-body">
        ' . HtmlCleaner::disableLinks($contentHtml, (string)$exportData['url']) . '
    </div>
    ' . $notesHtml . '
</body>
</html>';

    // -------------------------------------------------------------
    // ĐỊNH DẠNG 1: XUẤT FILE PDF (DÙNG WEASYPRINT)
    // -------------------------------------------------------------
    if ($exportFormat === 'pdf') {
        $pdfFilename = $baseFilename . '.pdf';
        $tmpHtml = tempnam(sys_get_temp_dir(), 'ew_') . '.html';
        $tmpPdf = tempnam(sys_get_temp_dir(), 'ew_') . '.pdf';

        file_put_contents($tmpHtml, $fullHtmlDoc);

        $cmd = 'weasyprint ' . escapeshellarg($tmpHtml) . ' ' . escapeshellarg($tmpPdf) . ' 2>&1';
        exec($cmd, $out, $ret);

        if ($ret === 0 && file_exists($tmpPdf) && filesize($tmpPdf) > 0) {
            // Xóa sạch bộ đệm output để tuyệt đối không có ký tự/warning nào dính vào đầu file PDF
            while (ob_get_level()) {
                ob_end_clean();
            }
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $safeAsciiFilename . '.pdf"; filename*=UTF-8\'\'' . rawurlencode($pdfFilename));
            header('Content-Length: ' . filesize($tmpPdf));
            header('Cache-Control: private, must-revalidate, post-check=0, pre-check=0');
            header('Pragma: public');
            readfile($tmpPdf);
            @unlink($tmpHtml);
            @unlink($tmpPdf);
            exit;
        } else {
            @unlink($tmpHtml);
            @unlink($tmpPdf);
            while (ob_get_level()) {
                ob_end_clean();
            }
            // Fallback xuất HTML nếu không tạo được PDF
            header('Content-Type: text/html; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $safeAsciiFilename . '.html"; filename*=UTF-8\'\'' . rawurlencode($baseFilename . '.html'));
            header('Content-Length: ' . strlen($fullHtmlDoc));
            echo $fullHtmlDoc;
            exit;
        }
    }

    // -------------------------------------------------------------
    // ĐỊNH DẠNG 2: XUẤT FILE WORD DOCX (DÙNG PANDOC)
    // -------------------------------------------------------------
    if ($exportFormat === 'docx' || $exportFormat === 'doc') {
        $docxFilename = $baseFilename . '.docx';
        $tmpHtml = tempnam(sys_get_temp_dir(), 'ew_') . '.html';
        $tmpDocx = tempnam(sys_get_temp_dir(), 'ew_') . '.docx';

        file_put_contents($tmpHtml, $fullHtmlDoc);

        $cmd = 'pandoc ' . escapeshellarg($tmpHtml) . ' -o ' . escapeshellarg($tmpDocx) . ' 2>&1';
        exec($cmd, $out, $ret);

        if ($ret === 0 && file_exists($tmpDocx) && filesize($tmpDocx) > 0) {
            // Xóa sạch bộ đệm output để tuyệt đối không dính warning làm hỏng cấu trúc file DOCX (ZIP)
            while (ob_get_level()) {
                ob_end_clean();
            }
            header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
            header('Content-Disposition: attachment; filename="' . $safeAsciiFilename . '.docx"; filename*=UTF-8\'\'' . rawurlencode($docxFilename));
            header('Content-Length: ' . filesize($tmpDocx));
            header('Cache-Control: private, must-revalidate, post-check=0, pre-check=0');
            header('Pragma: public');
            readfile($tmpDocx);
            @unlink($tmpHtml);
            @unlink($tmpDocx);
            exit;
        } else {
            @unlink($tmpHtml);
            @unlink($tmpDocx);
            while (ob_get_level()) {
                ob_end_clean();
            }
            // Fallback xuất HTML nếu không tạo được DOCX
            header('Content-Type: text/html; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $safeAsciiFilename . '.html"; filename*=UTF-8\'\'' . rawurlencode($baseFilename . '.html'));
            header('Content-Length: ' . strlen($fullHtmlDoc));
            echo $fullHtmlDoc;
            exit;
        }
    }

    // -------------------------------------------------------------
    // ĐỊNH DẠNG 3: XUẤT FILE HTML NGOẠI TUYẾN (MẶC ĐỊNH)
    // -------------------------------------------------------------
    $htmlFilename = $baseFilename . '.html';
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: text/html; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $safeAsciiFilename . '.html"; filename*=UTF-8\'\'' . rawurlencode($htmlFilename));
    header('Content-Length: ' . strlen($fullHtmlDoc));
    echo $fullHtmlDoc;
    exit;
}

// =========================================================================
// XỬ LÝ 2: CẬP NHẬT PHIÊN BẢN MỚI TỪ WEB GỐC
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'fetch_new_version') {
    $existing = $repo->getPageDetails($pageId, null, $userId);
    if (!$existing) {
        $error = 'Không tìm thấy tài liệu để cập nhật.';
    } else {
        $targetUrl = $existing['url'];
        $fetchResult = WebFetcher::fetch($targetUrl);

        if (!$fetchResult['success']) {
            $error = 'Không thể cập nhật từ link gốc: ' . $fetchResult['error'] . ' (Hệ thống bảo toàn nguyên vẹn phiên bản hiện tại của bạn).';
        } else {
            try {
                $updatedPageId = $repo->savePage(
                    $existing['library_id'],
                    $fetchResult['final_url'],
                    $fetchResult['html'],
                    ['localize_images' => true],
                    $userId
                );

                $refreshed = $repo->getPageDetails($updatedPageId, null, $userId);
                $newVer = $refreshed['version_number'] ?? 1;

                header('Location: /view.php?id=' . $pageId . '&version=' . $newVer . '&msg=' . urlencode("Đã cập nhật thành công Phiên bản v{$newVer} từ trang gốc! Bản cũ và ghi chú vẫn được lưu giữ trọn vẹn."));
                exit;
            } catch (Throwable $e) {
                $error = 'Lỗi cập nhật phiên bản: ' . $e->getMessage();
            }
        }
    }
}

// Xử lý đưa lên Thư viện chung
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'publish_public') {
    if ($userId !== null && $repo->publishToPublic($pageId, $userId)) {
        header('Location: /view.php?id=' . $pageId . '&msg=' . urlencode('Đã đưa bài viết lên Thư viện chung thành công!'));
        exit;
    } else {
        $error = 'Bạn không có quyền đưa bài viết này lên Thư viện chung.';
    }
}

// Xử lý rút về kho riêng tư
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'unpublish_public') {
    if ($userId !== null && $repo->unpublishFromPublic($pageId, $userId)) {
        header('Location: /view.php?id=' . $pageId . '&msg=' . urlencode('Đã rút bài viết về lại Kho cá nhân riêng tư!'));
        exit;
    } else {
        $error = 'Bạn không có quyền chuyển bài viết này.';
    }
}

// Xử lý chia sẻ vào nhóm bạn bè
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'share_to_group') {
    $targetGroupId = (int)($_POST['group_id'] ?? 0);
    if ($userId !== null && $targetGroupId > 0 && $repo->shareToGroup($pageId, $targetGroupId, $userId)) {
        header('Location: /view.php?id=' . $pageId . '&msg=' . urlencode('Đã chia sẻ bài viết vào Thư viện nhóm thành công!'));
        exit;
    } else {
        $error = 'Không thể chia sẻ bài viết vào nhóm này.';
    }
}

// Xử lý xóa bài viết
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_page') {
    try {
        $userRole = $currentUser['role'] ?? 'member';
        $deleted = $repo->deletePage($pageId, $userId, $userRole);
        if ($deleted) {
            header('Location: /index.php?msg=' . urlencode('Đã xóa tư liệu khỏi hệ thống thành công!'));
            exit;
        } else {
            $error = 'Bạn không có quyền xóa tài liệu này.';
        }
    } catch (Throwable $e) {
        $error = 'Lỗi khi xóa tài liệu: ' . $e->getMessage();
    }
}

// Xử lý đánh giá sao (Rating 1 - 5 sao)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'rate_page') {
    if (!$currentUser) {
        header('Location: /login.php?msg=' . urlencode('Vui lòng đăng nhập để đánh giá bài viết!'));
        exit;
    }
    $stars = (int)($_POST['stars'] ?? 5);
    $repo->ratePage($pageId, $userId, $stars);
    header('Location: /view.php?id=' . $pageId . ($versionParam ? '&version=' . $versionParam : '') . '&msg=' . urlencode('Cảm ơn bạn đã gửi đánh giá ' . $stars . ' sao!'));
    exit;
}

// Xử lý báo cáo bài viết vi phạm / không tốt
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'report_page') {
    if (!$currentUser) {
        header('Location: /login.php?msg=' . urlencode('Vui lòng đăng nhập để gửi báo cáo!'));
        exit;
    }
    $reportReason = trim($_POST['report_reason'] ?? '');
    if ($reportReason === '') {
        header('Location: /view.php?id=' . $pageId . ($versionParam ? '&version=' . $versionParam : '') . '&error=' . urlencode('Vui lòng nhập lý do báo cáo bài viết!'));
        exit;
    }
    if ($repo->hasUserReportedPage($pageId, $userId)) {
        header('Location: /view.php?id=' . $pageId . ($versionParam ? '&version=' . $versionParam : '') . '&error=' . urlencode('Bạn đã gửi báo cáo cho bài viết này rồi. Mỗi tài khoản chỉ được gửi báo cáo 1 lần cho mỗi bài viết!'));
        exit;
    }
    $ok = $repo->reportPage($pageId, $userId, $reportReason);
    if ($ok) {
        header('Location: /view.php?id=' . $pageId . ($versionParam ? '&version=' . $versionParam : '') . '&msg=' . urlencode('Đã gửi báo cáo đến Quản trị viên thành công. Xin cảm ơn sự đóng góp của bạn!'));
    } else {
        header('Location: /view.php?id=' . $pageId . ($versionParam ? '&version=' . $versionParam : '') . '&error=' . urlencode('Không thể gửi báo cáo hoặc bạn đã gửi báo cáo bài viết này rồi.'));
    }
    exit;
}

// Xử lý gắn nhãn (Tags)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_tag') {
    $newTagName = trim($_POST['tag_name'] ?? '');
    if ($newTagName !== '') {
        $repo->addTagToPage($pageId, $newTagName);
        header('Location: /view.php?id=' . $pageId . ($versionParam ? '&version=' . $versionParam : ''));
        exit;
    }
}

// Xử lý thêm ghi chú học tập
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_note') {
    $noteContent = trim($_POST['note_content'] ?? '');
    if ($noteContent !== '') {
        $repo->addNote($pageId, $noteContent);
        header('Location: /view.php?id=' . $pageId . ($versionParam ? '&version=' . $versionParam : ''));
        exit;
    }
}

// Xử lý xóa ghi chú
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_note') {
    $noteIdToDelete = (int)($_POST['note_id'] ?? 0);
    if ($noteIdToDelete > 0) {
        $repo->deleteNote($noteIdToDelete);
        header('Location: /view.php?id=' . $pageId . ($versionParam ? '&version=' . $versionParam : ''));
        exit;
    }
}

$pageData = $repo->getPageDetails($pageId, $versionParam, $userId);
if (!$pageData) {
    $pageTitle = 'Bài viết không còn tồn tại - Wiki Archiver';
    require_once __DIR__ . '/../templates/header.php';
    ?>
    <div style="max-width: 560px; margin: 40px auto; text-align: center;">
        <div class="card" style="padding: 40px 24px; border-top: 4px solid #ef4444;">
            <div style="font-size: 52px; margin-bottom: 12px;">🗑️</div>
            <h3 style="font-size: 22px; font-weight: 700; color: #b91c1c; margin: 0 0 10px 0;">Bài viết không còn tồn tại</h3>
            <p style="color: var(--text-muted); font-size: 14.5px; line-height: 1.6; margin-bottom: 24px;">
                Bài viết này trước đó đã được lưu trong lịch sử của bạn, nhưng hiện tại đã bị xóa khỏi hệ thống bởi người đăng hoặc Quản trị viên.
            </p>
            <div style="display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;">
                <a href="/history.php" class="btn btn-outline">&larr; Quay lại Lịch sử đọc</a>
                <a href="/index.php" class="btn btn-wiki">🏛️ Về Thư viện chung</a>
            </div>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/../templates/footer.php';
    exit;
}

// Kiểm tra quyền xem và quyền tải bài viết
$pagePerm = $repo->getUserPagePermission($pageId, $userId, $currentUser['role'] ?? 'member');
if (empty($pagePerm['can_view'])) {
    $pageTitle = 'Truy cập bị từ chối - Wiki Archiver';
    require_once __DIR__ . '/../templates/header.php';
    echo '<div class="card" style="text-align: center; padding: 60px 20px; margin-top: 30px;">';
    echo '<div style="font-size: 48px; margin-bottom: 12px;">🔒</div>';
    echo '<h3 style="font-size: 22px; font-weight: 700;">403 - Quyền truy cập bị từ chối</h3>';
    echo '<p style="color: var(--text-muted); margin-bottom: 20px;">Bạn không có quyền xem tài liệu này trong hội nhóm.</p>';
    echo '<p><a href="/index.php" class="btn btn-wiki">&larr; Quay lại Trang chính</a></p>';
    echo '</div>';
    require_once __DIR__ . '/../templates/footer.php';
    exit;
}
$canDownloadThisPage = !empty($pagePerm['can_download']);
$canEditThisPage = !empty($pagePerm['can_edit']);

// Ghi nhận lịch sử đọc bài viết nếu người dùng đã đăng nhập
if ($userId !== null) {
    $repo->recordReadingHistory($userId, $pageId);
}

// Lấy thông tin đánh giá sao của bài viết
$ratingStats = $repo->getPageRatingStats($pageId, $userId);

// Kiểm tra người dùng đã báo cáo bài viết này chưa (mỗi tài khoản tối đa 1 lần/bài)
$hasUserReported = ($userId !== null) ? $repo->hasUserReportedPage($pageId, $userId) : false;

// =========================================================================
// TỰ ĐỘNG SINH MỤC LỤC WIKIPEDIA (TABLE OF CONTENTS)
// Quét các thẻ <h2> và <h3> trong HTML bài viết để tạo mục lục tra cứu
// =========================================================================
$processedHtml = $pageData['content_html'];

// Đảm bảo toàn bộ thẻ <img> có thuộc tính src (xử lý các trang lazy-load truyện tranh, báo mạng chỉ có data-src)
$processedHtml = preg_replace_callback('/<img\b([^>]*)>/is', function($match) {
    $attrs = $match[1];
    // Xóa class lozad (lazy-loader của trang gốc) để ảnh hiển thị ngay
    $attrs = preg_replace('/\bclass=([\'"])([^\'"]*\b)lozad\b([^\'"]*)\1/i', 'class=$1$2$3$1', $attrs);
    $attrs = preg_replace('/\bclass=([\'"])\s*\1/', '', $attrs); // Xóa class="" rỗng
    // Đổi loading=lazy thành eager
    $attrs = preg_replace('/\bloading=([\'"])lazy\1/i', 'loading="eager"', $attrs);
    if (!preg_match('/(?<![a-zA-Z0-9_-])src=([\'"]).*?\1/i', $attrs)) {
        if (preg_match('/\b(?:data-src|data-original)=([\'"])(.*?)\1/i', $attrs, $mSrc)) {
            $srcVal = $mSrc[2];
            return '<img src="' . htmlspecialchars($srcVal, ENT_QUOTES) . '"' . $attrs . ' loading="eager">';
        }
    }
    return '<img' . $attrs . '>';
}, $processedHtml);

$tocItems = [];
$hIndex = 0;

$processedHtml = preg_replace_callback('/<h([23])\b([^>]*)>(.*?)<\/h\1>/is', function($matches) use (&$tocItems, &$hIndex) {
    $level = (int)$matches[1];
    $attrs = $matches[2];
    $headingText = trim(strip_tags($matches[3]));
    $hIndex++;
    $anchorId = 'section-' . $hIndex;

    $tocItems[] = [
        'level' => $level,
        'text' => $headingText,
        'id' => $anchorId,
    ];

    return "<h{$level}{$attrs} id=\"{$anchorId}\">" . $matches[3] . "</h{$level}>";
}, $processedHtml);

$pageTitle = htmlspecialchars($pageData['title']) . ' - Wiki Archiver';
require_once __DIR__ . '/../templates/header.php';
?>

<!-- THANH ĐIỀU HƯỚNG BREADCRUMB & THAO TÁC HỌC THUẬT -->
<div style="margin-top: 14px; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
    <div style="font-size: 13.5px; color: var(--text-muted); display: flex; align-items: center; gap: 6px;">
        <a href="/index.php" style="color: var(--link-color);">🏛️ Trang chính</a>
        <span>/</span>
        <a href="/index.php?library_id=<?= $pageData['library_id'] ?>" style="color: var(--link-color);">
            <?= htmlspecialchars($pageData['library_name']) ?>
        </a>
        <span>/</span>
        <span style="color: var(--text-main); font-weight: 600;">Bản lưu v<?= $pageData['version_number'] ?></span>
    </div>

    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
        <?php 
        $authorId = (int)($pageData['user_id'] ?? $pageData['author_id'] ?? 0);
        $isAuthor = $userId !== null && ($authorId === $userId || ($currentUser['role'] ?? '') === 'admin');
        
        // Kiểm tra xem bài hiện tại có đang nằm ở Kho cá nhân riêng tư của chính tác giả hay không
        $isInOwnPrivateLib = (
            !empty($pageData['library_user_id']) && 
            (int)$pageData['library_user_id'] === $authorId && 
            empty($pageData['library_group_id']) && 
            ($pageData['access_level'] ?? '') === 'private'
        );
        ?>

        <?php if ($isAuthor): ?>
            <!-- ĐƯA LÊN THƯ VIỆN CHUNG HOẶC RÚT VỀ RIÊNG TƯ -->
            <?php if ($isInOwnPrivateLib): ?>
                <form method="POST" action="" onsubmit="return eduConfirmSubmit(this, event, 'Đưa bài viết này lên Thư viện chung để mọi người cùng đọc?', 'Đưa Lên Thư Viện Chung', 'Xác nhận', false);" style="margin: 0;">
                    <input type="hidden" name="action" value="publish_public">
                    <button type="submit" class="btn btn-outline btn-sm" style="color: var(--wiki-blue);" title="Đưa lên Thư viện chung">
                        🌐 Lên Thư viện chung
                    </button>
                </form>
            <?php else: ?>
                <form method="POST" action="" onsubmit="return eduConfirmSubmit(this, event, 'Rút bài viết này về lại Kho cá nhân riêng tư?<br><small style=\'color:#92400e;\'>Bài viết sẽ không còn hiển thị ở Thư viện chung hoặc nhóm nữa.</small>', 'Rút Về Riêng Tư', 'Xác nhận rút về', false);" style="margin: 0;">
                    <input type="hidden" name="action" value="unpublish_public">
                    <button type="submit" class="btn btn-outline btn-sm" title="Rút về kho cá nhân riêng tư">
                        🔒 Rút về riêng tư
                    </button>
                </form>
            <?php endif; ?>

            <!-- CHIA SẺ VÀO NHÓM BẠN BÈ -->
            <?php if (!empty($userGroups)): ?>
                <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('viewShareModal').style.display = 'flex';">
                    👥 Vào nhóm
                </button>
            <?php endif; ?>
        <?php endif; ?>

        <!-- NÚT 1: CẬP NHẬT PHIÊN BẢN MỚI TỪ WEB GỐC -->
        <form method="POST" action="" onsubmit="return eduConfirmSubmit(this, event, 'Hệ thống sẽ kết nối đến liên kết gốc để tải về phiên bản mới nhất.<br>Bản cũ và ghi chú vẫn được lưu giữ trọn vẹn. Bạn có muốn tiếp tục?', 'Cập Nhật Phiên Bản Mới', 'Tiến hành cập nhật', false);" style="margin: 0;">
            <input type="hidden" name="action" value="fetch_new_version">
            <button type="submit" class="btn btn-outline btn-sm" title="Kiểm tra và lấy phiên bản mới nhất từ trang web gốc">
                🔄 Cập nhật bản mới
            </button>
        </form>

        <!-- NÚT 2: CÁC TÙY CHỌN TẢI VỀ NGOẠI TUYẾN (HTML, PDF, DOCX) -->
        <?php 
        $staticArchivePath = '/uploads/archive/page_' . $pageId . '_v' . $pageData['version_number'] . '.html';
        $fullStaticPath = dirname(__DIR__) . '/public' . $staticArchivePath;
        if (!file_exists($fullStaticPath)) {
            $repo->generateStaticArchiveFile($pageId, (int)$pageData['version_number'], (string)$pageData['title'], (string)$pageData['url'], (string)$pageData['content_html']);
        }
        ?>
        <?php if ($currentUser): ?>
            <?php if ($canDownloadThisPage): ?>
                <div style="display: inline-flex; align-items: center; border-radius: var(--radius-sm); box-shadow: var(--shadow-sm); overflow: hidden; border: 1px solid var(--wiki-border);">
                    <a href="/view.php?id=<?= $pageId ?>&version=<?= $pageData['version_number'] ?>&action=export&format=html" 
                       class="btn btn-wiki btn-sm" style="border-radius: 0; color: #fff !important; padding: 6px 12px; font-weight: 600;" 
                       title="Tải trang HTML độc lập (kèm toàn bộ ảnh nhúng)">
                        📥 HTML
                    </a>
                    <a href="/view.php?id=<?= $pageId ?>&version=<?= $pageData['version_number'] ?>&action=export&format=pdf" 
                       class="btn btn-sm" style="border-radius: 0; background: #dc2626; color: #fff !important; padding: 6px 11px; font-weight: 600; border: none; border-left: 1px solid rgba(255,255,255,0.3);" 
                       title="Tải tài liệu PDF (.pdf)">
                        📄 PDF
                    </a>
                    <a href="/view.php?id=<?= $pageId ?>&version=<?= $pageData['version_number'] ?>&action=export&format=docx" 
                       class="btn btn-sm" style="border-radius: 0; background: #2563eb; color: #fff !important; padding: 6px 11px; font-weight: 600; border: none; border-left: 1px solid rgba(255,255,255,0.3);" 
                       title="Tải văn bản Word (.docx)">
                        📝 Word (.docx)
                    </a>
                </div>
            <?php else: ?>
                <span class="badge" style="background: #fce8e6; color: #c5221f; border: 1px solid #fad2cf; padding: 6px 12px; font-size: 13px;" title="Tài liệu bị khóa quyền tải về trong nhóm">
                    🔒 Khóa quyền tải về
                </span>
            <?php endif; ?>
        <?php else: ?>
            <a href="/login.php?msg=<?= urlencode('Vui lòng đăng nhập để tải bài viết về máy!') ?>&redirect=<?= urlencode('/view.php?id=' . $pageId . '&action=export') ?>" class="btn btn-outline btn-sm" style="color: var(--wiki-blue);" title="Cần đăng nhập để tải bài về">
                🔒 Đăng nhập để tải (HTML / PDF / Word)
            </a>
        <?php endif; ?>

        <!-- BỘ CHUYỂN ĐỔI PHIÊN BẢN -->
        <?php if (count($pageData['versions']) > 1): ?>
            <div style="display: flex; align-items: center; gap: 6px;">
                <span style="font-size: 12.5px; font-weight: 700; color: var(--text-muted);">Phiên bản:</span>
                <select class="form-select" style="width: auto; padding: 4px 8px; font-size: 12.5px;" onchange="location = this.value;">
                    <?php foreach ($pageData['versions'] as $v): ?>
                        <option value="/view.php?id=<?= $pageId ?>&version=<?= $v['version_number'] ?>" <?= $v['version_number'] === $pageData['version_number'] ? 'selected' : '' ?>>
                            v<?= $v['version_number'] ?> (<?= date('d/m/Y H:i', strtotime($v['fetched_at'])) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>

        <!-- NÚT XÓA BÀI LƯU (NẾU CÓ QUYỀN) -->
        <?php if ($isAuthor): ?>
            <form method="POST" action="" onsubmit="return eduConfirmSubmit(this, event, 'Bạn có chắc chắn muốn xóa tài liệu này khỏi hệ thống?<br><small style=\'color:#b91c1c;\'>Thao tác này <strong>không thể hoàn tác</strong>.</small>', 'Xóa Tài Liệu', 'Xác nhận xóa vĩnh viễn');" style="margin: 0;">
                <input type="hidden" name="action" value="delete_page">
                <button type="submit" class="btn btn-outline btn-sm" style="color: var(--danger); border-color: #fecaca; background: #fff5f5;">
                    🗑️ Xóa
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- MODAL CHIA SẺ VÀO NHÓM -->
<?php if (!empty($userGroups)): ?>
<div id="viewShareModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 2000; align-items: center; justify-content: center;">
    <div style="background: #ffffff; border-radius: var(--radius-md); max-width: 460px; width: 90%; padding: 24px; box-shadow: var(--shadow-md);">
        <h3 style="margin-top: 0; font-size: 19px; font-weight: 700; color: var(--wiki-blue);">
            👥 Đưa bài viết vào Thư viện Nhóm bạn bè
        </h3>
        <p style="font-size: 13.5px; color: var(--text-muted); margin-bottom: 16px;">
            Đưa "<?= htmlspecialchars($pageData['title']) ?>" vào nhóm bạn bè để cùng học tập và tải về.
        </p>

        <form method="POST" action="">
            <input type="hidden" name="action" value="share_to_group">
            <div class="form-group" style="margin-bottom: 16px;">
                <label class="form-label" style="font-weight: 600;">Chọn Nhóm:</label>
                <select name="group_id" class="form-select" required style="height: 40px;">
                    <?php foreach ($userGroups as $grp): ?>
                        <option value="<?= $grp['id'] ?>">
                            <?= htmlspecialchars($grp['name']) ?> (<?= (int)$grp['member_count'] ?> thành viên)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 8px;">
                <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('viewShareModal').style.display = 'none';">Hủy</button>
                <button type="submit" class="btn btn-wiki btn-sm">Xác nhận đưa vào nhóm</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- BÀI BÁO HỌC THUẬT WIKIPEDIA (ACADEMIC ARTICLE VIEW) -->
<article class="wiki-article">
    <!-- TIÊU ĐỀ BÀI VIẾT HỌC THUẬT -->
    <h1 class="wiki-heading-1">
        <?= htmlspecialchars($pageData['title']) ?>
    </h1>
    <p class="wiki-subtitle">
        Tư liệu được bảo toàn trên <strong>Wiki Archiver</strong> &bull; Lưu trữ an toàn & đọc ngoại tuyến
    </p>

    <!-- HỘP THÔNG TIN TÀI LIỆU WIKIPEDIA (INFOBOX BÊN PHẢI) -->
    <aside class="wiki-infobox">
        <div class="wiki-infobox-header">📚 Thông Tin Lưu Trữ Tư Liệu</div>
        
        <div class="wiki-infobox-row">
            <span class="wiki-infobox-label">Chuyên mục:</span>
            <span class="wiki-infobox-val">
                <span class="badge badge-academic"><?= htmlspecialchars($pageData['library_name']) ?></span>
            </span>
        </div>

        <div class="wiki-infobox-row">
            <span class="wiki-infobox-label">Liên kết gốc:</span>
            <span class="wiki-infobox-val">
                <?php if (str_starts_with($pageData['url'], 'offline://') || str_starts_with($pageData['url'], 'manual://')): ?>
                    <span style="color: var(--text-muted); font-size: 12px; font-style: italic;">📄 Nhập HTML thủ công</span>
                <?php else: ?>
                    <a href="<?= htmlspecialchars($pageData['url']) ?>" target="_blank" rel="noopener noreferrer" title="<?= htmlspecialchars($pageData['url']) ?>">
                        <?= htmlspecialchars(parse_url($pageData['url'], PHP_URL_HOST) ?: 'Mở liên kết') ?> ↗
                    </a>
                <?php endif; ?>
            </span>
        </div>

        <div class="wiki-infobox-row">
            <span class="wiki-infobox-label">Thời điểm lưu:</span>
            <span class="wiki-infobox-val"><?= date('d/m/Y H:i', strtotime($pageData['fetched_at'])) ?></span>
        </div>

        <div class="wiki-infobox-row">
            <span class="wiki-infobox-label">Phiên bản:</span>
            <span class="wiki-infobox-val">
                <span class="badge badge-amber">v<?= (int)$pageData['version_number'] ?></span>
            </span>
        </div>

        <div class="wiki-infobox-row">
            <span class="wiki-infobox-label">Hình ảnh:</span>
            <span class="wiki-infobox-val">
                <span class="badge" style="background: #e8f5e9; color: #1b5e20;">✅ <?= count($pageData['assets']) ?> tệp</span>
            </span>
        </div>

        <!-- ĐÁNH GIÁ CỦA NGƯỜI DÙNG (1 - 5 SAO) -->
        <div class="wiki-infobox-row" style="flex-direction: column; align-items: flex-start; gap: 4px; padding-top: 8px; border-top: 1px dashed #e2e8f0;">
            <div style="display: flex; justify-content: space-between; width: 100%; align-items: center;">
                <span class="wiki-infobox-label">Đánh giá:</span>
                <span style="font-size: 13px; font-weight: 700; color: #d97706;">
                    ★ <?= $ratingStats['avg_stars'] > 0 ? number_format($ratingStats['avg_stars'], 1) : 'Chưa có' ?> 
                    <span style="font-size: 11px; font-weight: normal; color: var(--text-muted);">(<?= $ratingStats['total_ratings'] ?> lượt)</span>
                </span>
            </div>
            
            <?php if ($currentUser): ?>
                <form method="POST" action="" style="display: flex; gap: 3px; margin: 4px 0 0; width: 100%; justify-content: center;">
                    <input type="hidden" name="action" value="rate_page">
                    <?php for ($s = 1; $s <= 5; $s++): ?>
                        <button type="submit" name="stars" value="<?= $s ?>" 
                                style="background: none; border: none; cursor: pointer; font-size: 18px; line-height: 1; padding: 2px; color: <?= ($ratingStats['user_rating'] !== null && $s <= $ratingStats['user_rating']) ? '#f59e0b' : '#cbd5e1' ?>;"
                                title="Chấm <?= $s ?> sao">
                            ★
                        </button>
                    <?php endfor; ?>
                </form>
                <?php if ($ratingStats['user_rating'] !== null): ?>
                    <div style="font-size: 11px; color: #166534; text-align: center; width: 100%;">
                        ✓ Bạn đã đánh giá <?= $ratingStats['user_rating'] ?> sao
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- BÁO CÁO BÀI VIẾT TỚI ADMIN (REPORT) -->
        <div style="margin-top: 8px; padding-top: 8px; border-top: 1px dashed #e2e8f0; text-align: center;">
            <?php if ($hasUserReported): ?>
                <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 4px; padding: 6px 8px; font-size: 11.5px; color: #991b1b; text-align: center; line-height: 1.4;">
                    <span style="font-weight: 700;">🚩 Đã gửi báo cáo bài viết</span><br>
                    <span style="font-size: 10.5px; color: #b91c1c;">(Mỗi tài khoản gửi tối đa 1 lần &bull; BQT đang xử lý)</span>
                </div>
            <?php else: ?>
                <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('reportModal').style.display = 'flex';" 
                        style="width: 100%; font-size: 11.5px; color: #b91c1c; border-color: #fecaca; background: #fef2f2; padding: 4px 8px; cursor: pointer; border-radius: 4px;">
                    🚩 Báo cáo bài viết không tốt
                </button>
            <?php endif; ?>
        </div>

        <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #e2e8f0; display: flex; flex-direction: column; gap: 6px;">
            <div style="font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Tải về máy:</div>
            <?php if ($canDownloadThisPage): ?>
                <div style="display: flex; gap: 4px;">
                    <a href="/view.php?id=<?= $pageId ?>&version=<?= $pageData['version_number'] ?>&action=export&format=html" 
                       class="btn btn-wiki btn-sm" style="flex: 1; text-align: center; color: #fff !important; font-size: 11px; padding: 5px 2px;" title="Tải HTML kèm ảnh">
                        🌐 HTML
                    </a>
                    <a href="/view.php?id=<?= $pageId ?>&version=<?= $pageData['version_number'] ?>&action=export&format=pdf" 
                       class="btn btn-sm" style="flex: 1; text-align: center; background: #dc2626; color: #fff !important; font-size: 11px; padding: 5px 2px; border: none;" title="Tải PDF">
                        📄 PDF
                    </a>
                    <a href="/view.php?id=<?= $pageId ?>&version=<?= $pageData['version_number'] ?>&action=export&format=docx" 
                       class="btn btn-sm" style="flex: 1; text-align: center; background: #2563eb; color: #fff !important; font-size: 11px; padding: 5px 2px; border: none;" title="Tải Word (.docx)">
                        📝 Word
                    </a>
                </div>
            <?php else: ?>
                <div style="font-size: 11.5px; color: #c5221f; background: #fce8e6; border: 1px solid #fad2cf; padding: 4px 6px; border-radius: 4px; text-align: center;">
                    🔒 Khóa quyền tải về
                </div>
            <?php endif; ?>
        </div>
    </aside>

    <!-- THÔNG BÁO BẢO TOÀN NGOẠI TUYẾN AN TOÀN -->
    <div style="margin-bottom: 20px; padding: 10px 14px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: var(--radius-sm); font-size: 13px; color: #166534; display: flex; align-items: center; gap: 8px;">
        <span style="font-size: 16px;">🛡️</span>
        <div>
            <strong>Bản lưu trữ độc lập an toàn:</strong> Toàn bộ nội dung văn bản và <strong><?= count($pageData['assets']) ?> tệp hình ảnh/sơ đồ</strong> đã được tải về máy chủ cục bộ. Bạn và học sinh có thể đọc lại đầy đủ tài liệu ngay cả khi liên kết gốc bị xóa hoặc trang web gốc ngừng hoạt động.
        </div>
    </div>

    <!-- MỤC LỤC TỰ ĐỘNG WIKIPEDIA (CHỈ HIỂN THỊ KHI CÓ TỪ 2 ĐỀ MỤC TRỞ LÊN) -->
    <?php if (count($tocItems) >= 2): ?>
        <div class="wiki-toc">
            <div class="wiki-toc-title">
                <span>📑 Mục lục tài liệu</span>
                <button type="button" onclick="var ul = document.getElementById('toc-list'); ul.style.display = ul.style.display === 'none' ? 'block' : 'none';" style="background: none; border: none; color: var(--link-color); font-size: 11.5px; cursor: pointer;">
                    [ẩn/hiện]
                </button>
            </div>
            <ul id="toc-list">
                <?php foreach ($tocItems as $idx => $toc): ?>
                    <li style="<?= $toc['level'] === 3 ? 'margin-left: 14px; list-style-type: circle;' : '' ?>">
                        <a href="#<?= $toc['id'] ?>" style="color: var(--link-color);">
                            <?= htmlspecialchars($toc['text']) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- NỘI DUNG VĂN BẢN ĐÃ LÀM SẠCH VÀ NỘI BỘ HÓA HÌNH ẢNH -->
    <div class="wiki-body" style="margin-top: 24px;">
        <?= $processedHtml ?>
    </div>

    <!-- PHẦN CHÂN BÀI VIẾT: GẮN NHÃN & GHI CHÚ TƯ LIỆU -->
    <div style="margin-top: 40px; padding-top: 20px; border-top: 1px solid var(--wiki-border-light); font-size: 13px;">
        <h3 style="font-size: 18px; font-weight: 700; margin: 0 0 10px 0; color: var(--text-main);">
            🏷️ Thể loại & Nhãn tư liệu:
        </h3>
        <div style="display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-bottom: 16px;">
            <?php if (empty($pageData['tags'])): ?>
                <span style="color: var(--text-muted); font-style: italic;">Chưa gắn nhãn nào.</span>
            <?php else: ?>
                <?php foreach ($pageData['tags'] as $tag): ?>
                    <a href="/index.php?tag=<?= urlencode($tag['name']) ?>" class="badge badge-academic">
                        #<?= htmlspecialchars($tag['name']) ?>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if ($currentUser): ?>
            <!-- FORM THÊM NHÃN NHANH -->
            <form method="POST" action="" style="display: flex; gap: 6px; max-width: 320px; margin-bottom: 24px;">
                <input type="hidden" name="action" value="add_tag">
                <input type="text" name="tag_name" class="form-control" placeholder="Gắn thêm nhãn (VD: cong-nghe, tin-tuc)..." style="font-size: 12.5px; padding: 5px 10px;" required>
                <button type="submit" class="btn btn-outline btn-sm">+ Gắn</button>
            </form>
        <?php endif; ?>

        <!-- KHUNG GHI CHÚ GIẢNG DẠY & HỌC TẬP -->
        <h3 style="font-size: 18px; font-weight: 700; margin: 0 0 10px 0; color: var(--text-main);">
            📝 Ghi Chú Giảng Dạy & Tóm Tắt Bài Học:
        </h3>
        <?php if (empty($pageData['notes'])): ?>
            <p style="color: var(--text-muted); font-size: 13px; font-style: italic; margin-bottom: 12px;">
                Chưa có ghi chú nào cho tài liệu này.
            </p>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 8px; margin-bottom: 16px;">
                <?php foreach ($pageData['notes'] as $n): ?>
                    <div style="background: #f8f9fa; border-left: 3px solid var(--wiki-blue); padding: 10px 14px; border-radius: 4px; display: flex; justify-content: space-between; align-items: start;">
                        <div>
                            <div style="font-size: 11.5px; color: var(--text-muted); margin-bottom: 3px;">
                                📅 <?= date('d/m/Y H:i', strtotime($n['created_at'])) ?>
                            </div>
                            <div style="font-size: 13.5px; color: var(--text-main); line-height: 1.5;">
                                <?= nl2br(htmlspecialchars($n['content'])) ?>
                            </div>
                        </div>
                        <?php if ($currentUser): ?>
                            <form method="POST" action="" onsubmit="return eduConfirmSubmit(this, event, 'Bạn có chắc muốn xóa ghi chú này?', 'Xóa Ghi Chú', 'Xóa');" style="margin: 0;">
                                <input type="hidden" name="action" value="delete_note">
                                <input type="hidden" name="note_id" value="<?= $n['id'] ?>">
                                <button type="submit" style="background: none; border: none; color: var(--danger); cursor: pointer; font-size: 12px;" title="Xóa ghi chú">✕</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($currentUser): ?>
            <form method="POST" action="" style="margin-top: 8px;">
                <input type="hidden" name="action" value="add_note">
                <div style="display: flex; gap: 8px; align-items: start;">
                    <textarea name="note_content" class="form-control" rows="2" placeholder="Thêm ghi chú bài giảng, lưu ý cho học sinh/sinh viên..." style="font-size: 13px;" required></textarea>
                    <button type="submit" class="btn btn-wiki btn-sm" style="color: #fff !important; white-space: nowrap; height: 38px;">
                        + Lưu ghi chú
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</article>

<!-- MODAL BÁO CÁO BÀI VIẾT TỚI ADMIN (REPORT MODAL) -->
<div id="reportModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 2000; align-items: center; justify-content: center;">
    <div style="background: #ffffff; border-radius: var(--radius-md); max-width: 480px; width: 90%; padding: 24px; box-shadow: var(--shadow-md);">
        <h3 style="margin-top: 0; font-size: 19px; font-weight: 700; color: #b91c1c; display: flex; align-items: center; gap: 8px;">
            <span>🚩</span> Báo Cáo Bài Viết Tới Quản Trị Viên
        </h3>
        <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 14px;">
            Nếu bài viết có nội dung sai lệch, vi phạm bản quyền hoặc chất lượng kém, vui lòng thông báo để BQT kiểm tra và gỡ bỏ.
        </p>

        <?php if ($hasUserReported): ?>
            <div style="background: #fffbeb; border: 1px solid #fef3c7; border-radius: 6px; padding: 12px 14px; margin-bottom: 16px; font-size: 13.5px; color: #92400e;">
                ⚠️ <strong>Bạn đã gửi báo cáo cho bài viết này trước đó.</strong><br>
                Mỗi tài khoản chỉ được gửi báo cáo 1 lần cho mỗi bài viết. Ban quản trị đang xem xét xử lý.
            </div>
            <div style="display: flex; justify-content: flex-end;">
                <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('reportModal').style.display = 'none';">Đóng</button>
            </div>
        <?php elseif ($currentUser): ?>
            <form method="POST" action="">
                <input type="hidden" name="action" value="report_page">
                <div class="form-group" style="margin-bottom: 14px;">
                    <label class="form-label" style="font-weight: 600;">Lý do báo cáo:</label>
                    <textarea name="report_reason" class="form-control" rows="3" placeholder="Mô tả chi tiết vấn đề bạn nhận thấy ở bài viết này..." required></textarea>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 8px;">
                    <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('reportModal').style.display = 'none';">Hủy</button>
                    <button type="submit" class="btn btn-sm" style="background: #dc2626; color: #ffffff; border: none; padding: 5px 14px; cursor: pointer;">Gửi báo cáo</button>
                </div>
            </form>
        <?php else: ?>
            <p style="color: var(--danger); font-size: 13.5px;">Bạn cần đăng nhập để gửi báo cáo bài viết.</p>
            <div style="display: flex; justify-content: flex-end; gap: 8px;">
                <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('reportModal').style.display = 'none';">Đóng</button>
                <a href="/login.php?redirect=<?= urlencode('/view.php?id=' . $pageId) ?>" class="btn btn-wiki btn-sm">Đăng nhập ngay</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>

