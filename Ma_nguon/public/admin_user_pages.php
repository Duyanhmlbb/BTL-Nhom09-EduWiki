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

$targetUserId = (int)($_GET['user_id'] ?? 0);
if ($targetUserId <= 0) {
    header('Location: /admin.php');
    exit;
}

// Lấy thông tin người dùng
$stmtUser = db()->prepare("SELECT id, username, fullname, role FROM users WHERE id = ?");
$stmtUser->execute([$targetUserId]);
$targetUser = $stmtUser->fetch(PDO::FETCH_ASSOC);

if (!$targetUser) {
    header('Location: /admin.php');
    exit;
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

// Lấy tất cả bài viết của user này
$allPages = $adminRepo->getAllPages(null, $targetUserId, 1000);

// Phân trang
$perPage = 5;
$total = count($allPages);
$totalPages = max(1, (int)ceil($total / $perPage));
$currentPage = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));
$offset = ($currentPage - 1) * $perPage;
$pagedPages = array_slice($allPages, $offset, $perPage);

$pageTitle = "Bài viết của " . htmlspecialchars($targetUser['username']) . " - Admin";
require_once __DIR__ . '/../templates/header.php';
?>

<!-- TIÊU ĐỀ -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
    <div>
        <div style="font-size: 13px; color: var(--text-muted); margin-bottom: 4px;">
            <a href="/admin.php" style="color: var(--wiki-blue);">⚙️ Bảng quản trị</a> &rsaquo; Bài viết của người dùng
        </div>
        <h2 style="margin: 0; font-size: 22px; font-weight: 700; display: flex; align-items: center; gap: 8px;">
            📄 Bài viết của: <span style="color: var(--wiki-blue);"><?= htmlspecialchars($targetUser['username']) ?></span>
            <?php if ($targetUser['fullname']): ?>
                <span style="font-size: 15px; color: var(--text-muted); font-weight: 400;">(<?= htmlspecialchars($targetUser['fullname']) ?>)</span>
            <?php endif; ?>
        </h2>
        <p style="margin: 4px 0 0; color: var(--text-muted); font-size: 14px;">
            Vai trò: <?= $targetUser['role'] === 'admin' ? '⚙️ Quản trị viên' : '👤 Thành viên' ?> &nbsp;|&nbsp;
            Tổng cộng: <strong><?= $total ?> bài viết</strong>
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

<div class="card">
    <div class="card-title">
        <span>📑 Danh sách bài viết (<?= $total ?> bài)</span>
        <?php if ($totalPages > 1): ?>
            <span style="font-size: 13px; color: var(--text-muted); font-weight: 400;">Trang <?= $currentPage ?>/<?= $totalPages ?></span>
        <?php endif; ?>
    </div>

    <?php if (empty($allPages)): ?>
        <div style="text-align: center; padding: 40px; color: var(--text-muted);">
            <div style="font-size: 36px; margin-bottom: 10px;">📭</div>
            Người dùng này chưa có bài viết nào trong hệ thống.
        </div>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                <thead>
                    <tr style="border-bottom: 2px solid var(--wiki-border-light); text-align: left; color: var(--text-muted); background: #fafafa;">
                        <th style="padding: 10px 8px; width: 50px;">STT</th>
                        <th style="padding: 10px 8px;">Tiêu đề bài viết</th>
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
                            <td style="padding: 10px 8px; font-weight: 500; max-width: 300px;">
                                <a href="/view.php?id=<?= $p['id'] ?>" target="_blank" style="color: var(--wiki-blue); text-decoration: none;">
                                    <?= htmlspecialchars($p['title'] !== '' ? $p['title'] : 'Không có tiêu đề') ?>
                                </a>
                                <div style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">
                                    📝 <?= $p['notes_count'] ?> ghi chú &nbsp;|&nbsp; 🖼️ <?= $p['assets_count'] ?> ảnh
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
                                <div style="display: flex; justify-content: flex-end; gap: 6px;">
                                    <a href="/view.php?id=<?= $p['id'] ?>" target="_blank" class="btn btn-outline btn-sm">👁️ Xem</a>
                                    <form method="POST" action="" style="margin: 0;"
                                          onsubmit="return eduConfirmSubmit(this, event, 'Xóa bài viết này khỏi hệ thống vĩnh viễn?<br><small style=\'color:#b91c1c;\'>Không thể hoàn tác.</small>', 'Xóa Bài Viết', 'Xác nhận xóa');">
                                        <input type="hidden" name="action" value="admin_delete_page">
                                        <input type="hidden" name="page_id" value="<?= $p['id'] ?>">
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
                <?php $baseUrl = '/admin_user_pages.php?user_id=' . $targetUserId . '&page='; ?>
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

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
