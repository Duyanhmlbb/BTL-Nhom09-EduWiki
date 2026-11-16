<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/AdminRepository.php';

// Chỉ admin mới được truy cập
if (!Auth::check() || Auth::role() !== 'admin') {
    http_response_code(403);
    require_once __DIR__ . '/../templates/header.php';
    ?>
    <div style="max-width: 500px; margin: 60px auto; text-align: center;">
        <div class="card" style="padding: 40px 20px;">
            <div style="font-size: 48px; margin-bottom: 16px;">🚫</div>
            <h2 style="margin: 0 0 10px; color: var(--danger);">403 - Quyền truy cập bị từ chối</h2>
            <p style="color: var(--text-muted); font-size: 15px; margin-bottom: 24px;">
                Trang này chỉ dành riêng cho tài khoản Quản trị viên.
            </p>
            <a href="/index.php" class="btn btn-wiki">&larr; Quay về Kho lưu trữ</a>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/../templates/footer.php';
    exit;
}

$adminRepo = new AdminRepository(db());
$message = null;
$error = null;

// Xử lý giải quyết báo cáo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resolve_report') {
    $reportId = (int)($_POST['report_id'] ?? 0);
    if ($reportId > 0) {
        db()->prepare("UPDATE page_reports SET status = 'resolved' WHERE id = ?")->execute([$reportId]);
        $message = "Đã đánh dấu báo cáo #{$reportId} là đã xử lý!";
    }
}

// Xử lý xóa bài viết
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'admin_delete_page') {
    $deletePageId = (int)($_POST['page_id'] ?? 0);
    if ($deletePageId > 0) {
        try {
            if ($adminRepo->deletePageAdmin($deletePageId)) {
                $message = "Đã xóa bài viết #{$deletePageId} thành công!";
            } else {
                $error = "Không tìm thấy bài viết để xóa.";
            }
        } catch (Throwable $e) {
            $error = "Lỗi khi xóa bài viết: " . $e->getMessage();
        }
    }
}



// Tìm kiếm & lọc
$searchKeyword = trim($_GET['q'] ?? '');
$filterUserId = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? (int)$_GET['user_id'] : null;

// Lấy tất cả bài viết theo filter (không giới hạn, paginate thủ công)
$allPages = $adminRepo->getAllPages(
    $searchKeyword !== '' ? $searchKeyword : null,
    $filterUserId,
    5000
);

// Phân trang
$perPage = 5;
$total = count($allPages);
$totalPages = max(1, (int)ceil($total / $perPage));
$currentPage = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));
$offset = ($currentPage - 1) * $perPage;
$pagedPages = array_slice($allPages, $offset, $perPage);

// Danh sách user để filter
$stmtUsers = db()->query("SELECT id, username, fullname FROM users ORDER BY username ASC");
$allUsers = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

// Lấy danh sách các báo cáo vi phạm đang chờ xử lý
require_once __DIR__ . '/../src/ArchiveRepository.php';
$archiveRepo = new ArchiveRepository(db());
$pendingReports = $archiveRepo->getPendingReports();

$pageTitle = "Kiểm duyệt Bài viết - Admin";
require_once __DIR__ . '/../templates/header.php';
?>

<!-- TIÊU ĐỀ -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
    <div>
        <div style="font-size: 13px; color: var(--text-muted); margin-bottom: 4px;">
            <a href="/admin.php" style="color: var(--wiki-blue);">⚙️ Bảng quản trị</a> &rsaquo; Kiểm duyệt bài viết
        </div>
        <h2 style="margin: 0; font-size: 22px; font-weight: 700;">
            📋 Quản lý &amp; Kiểm duyệt Bài viết
        </h2>
        <p style="margin: 4px 0 0; color: var(--text-muted); font-size: 14px;">
            Tìm kiếm, lọc và xóa bài viết trong toàn hệ thống với quyền quản trị viên.
        </p>
    </div>
    <a href="/admin.php" class="btn btn-outline btn-sm">&larr; Quay lại Bảng quản trị</a>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><strong>Thành công:</strong> <?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><strong>Lỗi:</strong> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- DANH SÁCH BÁO CÁO VI PHẠM TỪ NGƯỜI DÙNG (NẾU CÓ) -->
<?php if (!empty($pendingReports)): ?>
    <div class="card" style="margin-bottom: 24px; border-left: 4px solid #dc2626; background: #fffdfd;">
        <div class="card-title" style="color: #b91c1c;">
            <span>🚩 Báo cáo vi phạm từ người dùng đang chờ xử lý (<?= count($pendingReports) ?> báo cáo)</span>
        </div>
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                <thead>
                    <tr style="border-bottom: 2px solid var(--wiki-border-light); text-align: left; color: var(--text-muted); background: #fafafa;">
                        <th style="padding: 10px 12px;">Bài viết bị báo cáo</th>
                        <th style="padding: 10px 12px;">Người gửi báo cáo</th>
                        <th style="padding: 10px 12px;">Lý do báo cáo</th>
                        <th style="padding: 10px 12px;">Thời điểm</th>
                        <th style="padding: 10px 12px; text-align: right;">Hành động</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pendingReports as $rep): ?>
                        <tr style="border-bottom: 1px solid var(--wiki-border-light);">
                            <td style="padding: 10px 12px;">
                                <a href="/view.php?id=<?= $rep['page_id'] ?>" target="_blank" style="font-weight: 600; color: var(--link-color);">
                                    <?= htmlspecialchars($rep['page_title']) ?>
                                </a>
                                <div style="font-size: 11.5px; color: var(--text-muted);">Thư viện: <?= htmlspecialchars($rep['library_name']) ?></div>
                            </td>
                            <td style="padding: 10px 12px; font-weight: 600;">@<?= htmlspecialchars($rep['reporter_name']) ?></td>
                            <td style="padding: 10px 12px; color: #b91c1c;">
                                <?php
                                $rawReason = trim($rep['reason']);
                                $shortReason = mb_strlen($rawReason, 'UTF-8') > 20 ? mb_substr($rawReason, 0, 20, 'UTF-8') . '...' : $rawReason;
                                ?>
                                <span style="cursor: pointer; text-decoration: underline dotted; font-weight: 500;" 
                                      onclick="showReportDetail(<?= htmlspecialchars(json_encode($rep['page_title'])) ?>, <?= htmlspecialchars(json_encode($rep['reporter_name'])) ?>, <?= htmlspecialchars(json_encode($rawReason)) ?>, '<?= date('d/m/Y H:i', strtotime($rep['created_at'])) ?>')" 
                                      title="Bấm vào để xem toàn bộ lý do">
                                    <?= htmlspecialchars($shortReason) ?>
                                </span>
                            </td>
                            <td style="padding: 10px 12px; color: var(--text-muted); font-size: 12px;"><?= date('d/m/Y H:i', strtotime($rep['created_at'])) ?></td>
                            <td style="padding: 10px 12px; text-align: right;">
                                <div style="display: flex; gap: 6px; justify-content: flex-end;">
                                    <form method="POST" action="" onsubmit="return eduConfirmSubmit(this, event, 'Xóa bài viết này khỏi hệ thống vĩnh viễn?<br><small style=\'color:#b91c1c;\'>Không thể hoàn tác.</small>', 'Xóa Bài Vi Phạm', 'Xác nhận xóa vĩnh viễn');">
                                        <input type="hidden" name="action" value="admin_delete_page">
                                        <input type="hidden" name="page_id" value="<?= $rep['page_id'] ?>">
                                        <button type="submit" class="btn btn-outline btn-sm" style="color: var(--danger); border-color: #fecaca; background: #fff5f5;">
                                            🗑️ Xóa bài
                                        </button>
                                    </form>
                                    <form method="POST" action="">
                                        <input type="hidden" name="action" value="resolve_report">
                                        <input type="hidden" name="report_id" value="<?= $rep['id'] ?>">
                                        <button type="submit" class="btn btn-outline btn-sm" style="color: var(--success);">
                                            ✓ Bỏ qua
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- THANH TÌM KIẾM & LỌC -->
<div class="card" style="margin-bottom: 20px; padding: 16px 20px;">
    <form method="GET" action="" style="display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end;">
        <div style="flex: 2; min-width: 200px;">
            <label class="form-label" style="margin-bottom: 4px;">🔍 Tìm kiếm theo tiêu đề / URL</label>
            <input type="text" name="q" value="<?= htmlspecialchars($searchKeyword) ?>"
                   class="form-control" placeholder="Nhập từ khóa tìm kiếm...">
        </div>
        <div style="flex: 1; min-width: 160px;">
            <label class="form-label" style="margin-bottom: 4px;">👤 Lọc theo người dùng</label>
            <select name="user_id" class="form-select">
                <option value="">Tất cả người dùng</option>
                <?php foreach ($allUsers as $u): ?>
                    <option value="<?= $u['id'] ?>" <?= $filterUserId === $u['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($u['username']) ?>
                        <?php if ($u['fullname']): ?>(<?= htmlspecialchars($u['fullname']) ?>)<?php endif; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="display: flex; gap: 8px;">
            <button type="submit" class="btn btn-wiki">🔍 Tìm kiếm</button>
            <?php if ($searchKeyword !== '' || $filterUserId): ?>
                <a href="/admin_moderation.php" class="btn btn-outline">✕ Xóa bộ lọc</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- DANH SÁCH BÀI VIẾT -->
<div class="card">
    <div class="card-title">
        <span>
            📑 Danh sách bài viết
            <?php if ($searchKeyword !== '' || $filterUserId): ?>
                <span style="font-size: 13px; font-weight: 400; color: var(--text-muted);">
                    — kết quả lọc:
                    <?php if ($searchKeyword !== ''): ?>
                        từ khóa "<em><?= htmlspecialchars($searchKeyword) ?></em>"
                    <?php endif; ?>
                    <?php if ($filterUserId): ?>
                        <?php
                        $filterUserName = '';
                        foreach ($allUsers as $u) {
                            if ($u['id'] === $filterUserId) { $filterUserName = $u['username']; break; }
                        }
                        ?>
                        của người dùng <em><?= htmlspecialchars($filterUserName) ?></em>
                    <?php endif; ?>
                </span>
            <?php endif; ?>
            (<?= $total ?> bài)
        </span>
        <?php if ($totalPages > 1): ?>
            <span style="font-size: 13px; color: var(--text-muted); font-weight: 400;">Trang <?= $currentPage ?>/<?= $totalPages ?></span>
        <?php endif; ?>
    </div>

    <?php if (empty($allPages)): ?>
        <div style="text-align: center; padding: 40px; color: var(--text-muted);">
            <div style="font-size: 36px; margin-bottom: 10px;">📭</div>
            Không tìm thấy bài viết nào phù hợp với bộ lọc.
        </div>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                <thead>
                    <tr style="border-bottom: 2px solid var(--wiki-border-light); text-align: left; color: var(--text-muted); background: #fafafa;">
                        <th style="padding: 10px 8px; width: 50px;">STT</th>
                        <th style="padding: 10px 8px;">Tiêu đề bài viết</th>
                        <th style="padding: 10px 8px;">Tác giả</th>
                        <th style="padding: 10px 8px;">Thư viện</th>
                        <th style="padding: 10px 8px;">Nguồn</th>
                        <th style="padding: 10px 8px;">Ngày lưu</th>
                        <th style="padding: 10px 8px; text-align: right;">Hành động</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pagedPages as $index => $p): ?>
                        <tr style="border-bottom: 1px solid var(--wiki-border-light);">
                            <td style="padding: 10px 8px;">
                                <span class="badge badge-tag" style="font-weight: 700; font-size: 12px;">
                                    #<?= str_pad((string)($offset + $index + 1), 2, '0', STR_PAD_LEFT) ?>
                                </span>
                            </td>
                            <td style="padding: 10px 8px; font-weight: 500; max-width: 280px;">
                                <a href="/view.php?id=<?= $p['id'] ?>" target="_blank"
                                   style="color: var(--wiki-blue); text-decoration: none;">
                                    <?= htmlspecialchars($p['title'] !== '' ? $p['title'] : 'Không có tiêu đề') ?>
                                </a>
                                <div style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">
                                    📝 <?= $p['notes_count'] ?> ghi chú &nbsp;|&nbsp; 🖼️ <?= $p['assets_count'] ?> ảnh
                                </div>
                            </td>
                            <td style="padding: 10px 8px;">
                                <strong><?= htmlspecialchars($p['author_username'] ?? 'Hệ thống') ?></strong>
                                <?php if ($p['author_fullname']): ?>
                                    <div style="font-size: 12px; color: var(--text-muted);">
                                        <?= htmlspecialchars($p['author_fullname']) ?>
                                    </div>
                                <?php endif; ?>
                                <div style="margin-top: 3px;">
                                    <a href="/admin_user_pages.php?user_id=<?= $p['user_id'] ?>"
                                       class="badge badge-tag" style="font-size: 11px; cursor: pointer;">
                                        📄 Xem tất cả bài
                                    </a>
                                </div>
                            </td>
                            <td style="padding: 10px 8px;">
                                <span class="badge badge-tag"><?= htmlspecialchars($p['library_name'] ?? 'Mặc định') ?></span>
                            </td>
                            <td style="padding: 10px 8px; font-size: 13px;">
                                <a href="<?= htmlspecialchars($p['url']) ?>" target="_blank" rel="noopener noreferrer"
                                   style="color: var(--text-muted);">
                                    <?= htmlspecialchars(parse_url($p['url'], PHP_URL_HOST) ?? $p['url']) ?>
                                </a>
                            </td>
                            <td style="padding: 10px 8px; color: var(--text-muted); font-size: 13px;">
                                <?= date('d/m/Y H:i', strtotime($p['fetched_at'] ?? $p['created_at'])) ?>
                            </td>
                            <td style="padding: 10px 8px; text-align: right;">
                                <div style="display: flex; justify-content: flex-end; gap: 6px; flex-wrap: wrap;">
                                    <a href="/view.php?id=<?= $p['id'] ?>" target="_blank" class="btn btn-outline btn-sm">👁️ Xem</a>
                                    <form method="POST" action="" style="margin: 0;"
                                          onsubmit="return eduConfirmSubmit(this, event, 'Xóa bài viết này khỏi hệ thống vĩnh viễn?<br><small style=\'color:#b91c1c;\'>Không thể hoàn tác.</small>', 'Xóa Bài Viết', 'Xác nhận xóa');">
                                        <input type="hidden" name="action" value="admin_delete_page">
                                        <input type="hidden" name="page_id" value="<?= $p['id'] ?>">
                                        <?php if ($searchKeyword !== ''): ?>
                                            <input type="hidden" name="q_redirect" value="<?= htmlspecialchars($searchKeyword) ?>">
                                        <?php endif; ?>
                                        <button type="submit" class="btn btn-sm"
                                                style="background:#fce8e6;color:#c5221f;border:1px solid #fad2cf;">🗑️ Xóa</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="edu-pagination">
                <?php
                $baseUrl = '/admin_moderation.php?' .
                    ($searchKeyword !== '' ? 'q=' . urlencode($searchKeyword) . '&' : '') .
                    ($filterUserId ? 'user_id=' . $filterUserId . '&' : '') .
                    'page=';
                ?>
                <a href="<?= $baseUrl ?>1" class="edu-pagination-item <?= $currentPage === 1 ? 'disabled' : '' ?>">&laquo;</a>
                <?php if ($currentPage > 1): ?>
                    <a href="<?= $baseUrl . ($currentPage - 1) ?>" class="edu-pagination-item"><?= $currentPage - 1 ?></a>
                <?php endif; ?>
                <span class="edu-pagination-item active"><?= $currentPage ?></span>
                <?php if ($currentPage < $totalPages): ?>
                    <a href="<?= $baseUrl . ($currentPage + 1) ?>" class="edu-pagination-item"><?= $currentPage + 1 ?></a>
                <?php endif; ?>
                <a href="<?= $baseUrl . $totalPages ?>" class="edu-pagination-item <?= $currentPage === $totalPages ? 'disabled' : '' ?>">&raquo;</a>
                <span class="edu-pagination-info">
                    Hiển thị <?= $offset + 1 ?>–<?= min($offset + $perPage, $total) ?> / <?= $total ?> bài viết
                </span>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- MODAL XEM CHI TIẾT LÝ DO BÁO CÁO -->
<div id="reportDetailModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 2100; align-items: center; justify-content: center;">
    <div style="background: #ffffff; border-radius: var(--radius-md); max-width: 500px; width: 90%; padding: 24px; box-shadow: var(--shadow-md);">
        <h3 style="margin-top: 0; font-size: 18px; font-weight: 700; color: #b91c1c; display: flex; align-items: center; gap: 8px;">
            <span>🚩</span> Chi Tiết Báo Cáo Vi Phạm
        </h3>
        <div style="margin-bottom: 12px; font-size: 13.5px;">
            <strong>Bài viết:</strong> <span id="modalPageTitle" style="color: var(--wiki-blue);"></span>
        </div>
        <div style="margin-bottom: 12px; font-size: 13.5px;">
            <strong>Người báo cáo:</strong> <span id="modalReporter"></span> &bull; <span id="modalTime" style="color: var(--text-muted); font-size: 12px;"></span>
        </div>
        <div style="margin-bottom: 16px;">
            <label style="font-weight: 700; font-size: 13px; display: block; margin-bottom: 6px;">Nội dung lý do báo cáo đầy đủ:</label>
            <div id="modalReason" style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 4px; padding: 12px; font-size: 13.5px; color: #991b1b; line-height: 1.6; word-break: break-word; white-space: pre-wrap;"></div>
        </div>
        <div style="display: flex; justify-content: flex-end;">
            <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('reportDetailModal').style.display = 'none';">Đóng</button>
        </div>
    </div>
</div>

<script>
function showReportDetail(title, reporter, reason, time) {
    document.getElementById('modalPageTitle').textContent = title;
    document.getElementById('modalReporter').textContent = '@' + reporter;
    document.getElementById('modalTime').textContent = time;
    document.getElementById('modalReason').textContent = reason;
    document.getElementById('reportDetailModal').style.display = 'flex';
}
window.addEventListener('click', function(e) {
    var m = document.getElementById('reportDetailModal');
    if (e.target === m) m.style.display = 'none';
});
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
