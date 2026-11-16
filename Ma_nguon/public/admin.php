<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/AdminRepository.php';

// Kiểm tra quyền Quản trị viên (Admin)
if (!Auth::check() || Auth::role() !== 'admin') {
    http_response_code(403);
    require_once __DIR__ . '/../templates/header.php';
    ?>
    <div style="max-width: 500px; margin: 60px auto; text-align: center;">
        <div class="card" style="padding: 40px 20px;">
            <div style="font-size: 48px; margin-bottom: 16px;">🚫</div>
            <h2 style="margin: 0 0 10px; color: var(--danger);">403 - Quyền truy cập bị từ chối</h2>
            <p style="color: var(--text-muted); font-size: 15px; margin-bottom: 24px;">
                Trang này chỉ dành riêng cho tài khoản Quản trị viên (Admin).
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

// Xác định current user có phải super admin không
$currentIsSuperAdmin = $adminRepo->isSuperAdmin(Auth::id());

// Xử lý dọn rác tệp media mồ côi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clean_orphan_media') {
    try {
        $cleanResult = $adminRepo->cleanOrphanMedia();
        $message = "Đã dọn dẹp thành công! Đã xóa {$cleanResult['deleted_files']} tệp rác mồ côi, giải phóng {$cleanResult['freed_formatted']} dung lượng ổ đĩa.";
    } catch (Throwable $e) {
        $error = "Lỗi dọn rác tệp: " . $e->getMessage();
    }
}

// Xử lý admin xóa người dùng
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'admin_delete_user') {
    $deleteUserId = (int)($_POST['user_id'] ?? 0);
    if ($deleteUserId === Auth::id()) {
        $error = "Bạn không thể tự xóa tài khoản Quản trị viên đang đăng nhập!";
    } elseif ($deleteUserId > 0) {
        try {
            if ($adminRepo->deleteUser($deleteUserId)) {
                $message = "Đã xóa tài khoản người dùng và toàn bộ dữ liệu lưu trữ liên quan thành công!";
            } else {
                $error = "Không tìm thấy người dùng để xóa.";
            }
        } catch (Throwable $e) {
            $error = "Lỗi khi xóa người dùng: " . $e->getMessage();
        }
    }
}

// Xử lý nâng cấp thành admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'promote_admin') {
    $targetUserId = (int)($_POST['user_id'] ?? 0);
    if ($targetUserId > 0) {
        try {
            if ($adminRepo->promoteToAdmin($targetUserId)) {
                $message = "Đã nâng cấp thành viên thành Quản trị viên thành công!";
            } else {
                $error = "Không tìm thấy người dùng để nâng cấp.";
            }
        } catch (Throwable $e) {
            $error = "Lỗi khi nâng cấp: " . $e->getMessage();
        }
    }
}

// Xử lý giáng cấp admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'demote_admin') {
    if (!$currentIsSuperAdmin) {
        $error = "Chỉ Super Admin mới có quyền giáng cấp Quản trị viên khác!";
    } else {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        if ($targetUserId > 0) {
            try {
                if ($adminRepo->demoteToMember($targetUserId)) {
                    $message = "Đã giáng cấp Quản trị viên xuống thành viên thành công!";
                } else {
                    $error = "Không tìm thấy người dùng để giáng cấp.";
                }
            } catch (Throwable $e) {
                $error = "Lỗi khi giáng cấp: " . $e->getMessage();
            }
        }
    }
}

// Lấy dữ liệu thống kê và danh sách người dùng
$stats = $adminRepo->getSystemStats();
$users = $adminRepo->getAllUsers();

// Phân trang nhóm
$groupsList = $stats['groups_list'] ?? [];
$groupsPerPage = 5;
$groupsTotal = count($groupsList);
$groupsTotalPages = max(1, (int)ceil($groupsTotal / $groupsPerPage));
$groupsPage = max(1, min($groupsTotalPages, (int)($_GET['groups_page'] ?? 1)));
$groupsOffset = ($groupsPage - 1) * $groupsPerPage;
$pagedGroups = array_slice($groupsList, $groupsOffset, $groupsPerPage);

// Phân trang người dùng
$usersPerPage = 5;
$usersTotal = count($users);
$usersTotalPages = max(1, (int)ceil($usersTotal / $usersPerPage));
$usersPage = max(1, min($usersTotalPages, (int)($_GET['users_page'] ?? 1)));
$usersOffset = ($usersPage - 1) * $usersPerPage;
$pagedUsers = array_slice($users, $usersOffset, $usersPerPage);

// Đếm số lượng báo cáo vi phạm đang chờ xử lý
$pendingReportCount = 0;
try {
    $pendingReportCount = (int)db()->query("SELECT COUNT(*) FROM page_reports WHERE status = 'pending'")->fetchColumn();
} catch (Throwable $e) {}

$pageTitle = "Bảng Quản Trị - Wiki Archiver";
require_once __DIR__ . '/../templates/header.php';
?>

<!-- TIÊU ĐỀ BẢNG QUẢN TRỊ -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
    <div>
        <h2 style="margin: 0; font-size: 24px; font-weight: 700; display: flex; align-items: center; gap: 8px;">
            ⚙️ Bảng Điều Khiển Quản Trị Hệ Thống
        </h2>
        <p style="margin: 4px 0 0; color: var(--text-muted); font-size: 14px;">
            Thống kê tài nguyên, giám sát thành viên và kiểm duyệt nội dung lưu trữ
        </p>
    </div>
    <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
        <a href="/admin_moderation.php" class="btn btn-outline btn-sm" style="color: #1a73e8; border-color: #1a73e8; position: relative;">
            📋 Quản lý &amp; Kiểm duyệt bài viết
            <?php if ($pendingReportCount > 0): ?>
                <span style="background: #dc2626; color: #ffffff; font-size: 11px; font-weight: 700; padding: 2px 7px; border-radius: 10px; margin-left: 6px; box-shadow: 0 0 6px rgba(220, 38, 38, 0.4);">
                    <?= $pendingReportCount ?>
                </span>
            <?php endif; ?>
        </a>
        <form method="POST" action="" onsubmit="return eduConfirmSubmit(this, event, 'Quét và xóa tất cả các tệp hình ảnh mồ côi không còn bài viết nào sử dụng?<br><small style=\'color:#b91c1c;\'>Thao tác này sẽ giải phóng dung lượng lưu trữ.</small>', 'Dọn Rác Media', 'Tiến hành dọn rác', false);">
            <input type="hidden" name="action" value="clean_orphan_media">
            <button type="submit" class="btn btn-outline btn-sm" style="color: #b06000; border-color: #fef0cd; background: #fffdf5;">
                🧹 Quét &amp; Dọn rác Media
            </button>
        </form>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success">
        <strong>Thành công:</strong> <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger">
        <strong>Lỗi:</strong> <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<!-- 1. THỐNG KÊ TỔNG QUAN HỆ THỐNG -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-bottom: 20px;">
    <div class="card" style="margin: 0; padding: 16px; border-left: 4px solid var(--wiki-blue);">
        <div style="font-size: 11px; color: var(--text-muted); font-weight: 600; text-transform: uppercase;">Thành viên</div>
        <div style="font-size: 26px; font-weight: 800; color: var(--wiki-blue); margin: 4px 0 2px;">
            <?= $stats['total_users'] ?>
        </div>
        <div style="font-size: 12px; color: var(--text-muted);">Tài khoản hoạt động</div>
    </div>

    <div class="card" style="margin: 0; padding: 16px; border-left: 4px solid #1a73e8;">
        <div style="font-size: 11px; color: var(--text-muted); font-weight: 600; text-transform: uppercase;">Hội nhóm Drive</div>
        <div style="font-size: 26px; font-weight: 800; color: #1a73e8; margin: 4px 0 2px;">
            <?= $stats['total_groups'] ?? 0 ?>
        </div>
        <div style="font-size: 12px; color: var(--text-muted);"><?= $stats['total_group_members'] ?? 0 ?> lượt thành viên</div>
    </div>

    <div class="card" style="margin: 0; padding: 16px; border-left: 4px solid var(--success);">
        <div style="font-size: 11px; color: var(--text-muted); font-weight: 600; text-transform: uppercase;">Bài viết đã lưu</div>
        <div style="font-size: 26px; font-weight: 800; color: var(--success); margin: 4px 0 2px;">
            <?= $stats['total_pages'] ?>
        </div>
        <div style="font-size: 12px; color: var(--text-muted);"><?= $stats['total_notes'] ?> ghi chú đi kèm</div>
    </div>

    <div class="card" style="margin: 0; padding: 16px; border-left: 4px solid #b06000;">
        <div style="font-size: 11px; color: var(--text-muted); font-weight: 600; text-transform: uppercase;">Phân loại Thư viện</div>
        <div style="font-size: 13px; font-weight: 600; color: #202124; margin-top: 6px;">
            🌐 Công khai: <strong><?= $stats['public_libraries'] ?? 0 ?></strong><br>
            👥 Nhóm: <strong><?= $stats['group_libraries'] ?? 0 ?></strong><br>
            🔒 Riêng tư: <strong><?= $stats['private_libraries'] ?? 0 ?></strong>
        </div>
    </div>

    <div class="card" style="margin: 0; padding: 16px; border-left: 4px solid #7b1fa2;">
        <div style="font-size: 11px; color: var(--text-muted); font-weight: 600; text-transform: uppercase;">Dung lượng Media</div>
        <div style="font-size: 24px; font-weight: 800; color: #7b1fa2; margin: 4px 0 2px;">
            <?= $stats['media_size_formatted'] ?>
        </div>
        <div style="font-size: 12px; color: var(--text-muted);"><?= $stats['media_files'] ?> tệp ảnh ngoại tuyến</div>
    </div>
</div>

<!-- TOP TÊN MIỀN -->
<?php if (!empty($stats['top_domains'])): ?>
    <div class="card" style="margin-bottom: 24px; padding: 14px 20px;">
        <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
            <span style="font-size: 13px; font-weight: 600; color: var(--text-muted);">🌐 Top nguồn lưu phổ biến:</span>
            <?php foreach ($stats['top_domains'] as $domain => $count): ?>
                <span class="badge badge-tag" style="font-size: 13px;">
                    <?= htmlspecialchars($domain) ?> <strong style="margin-left: 4px;">(<?= $count ?>)</strong>
                </span>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<!-- DANH SÁCH HỘI NHÓM (CÓ PHÂN TRANG) -->
<?php if (!empty($groupsList)): ?>
    <div class="card" style="margin-bottom: 24px;">
        <div class="card-title">
            <span>👥 Danh sách Hội nhóm (<?= $groupsTotal ?> nhóm)</span>
            <?php if ($groupsTotalPages > 1): ?>
                <span style="font-size: 13px; color: var(--text-muted); font-weight: 400;">
                    Trang <?= $groupsPage ?>/<?= $groupsTotalPages ?>
                </span>
            <?php endif; ?>
        </div>
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                <thead>
                    <tr style="border-bottom: 2px solid var(--wiki-border-light); text-align: left; color: var(--text-muted); background: #fafafa;">
                        <th style="padding: 10px 12px;">Mã</th>
                        <th style="padding: 10px 12px;">Tên Hội nhóm</th>
                        <th style="padding: 10px 12px;">Chủ nhóm</th>
                        <th style="padding: 10px 12px;">Thành viên</th>
                        <th style="padding: 10px 12px;">Thư viện nhóm</th>
                        <th style="padding: 10px 12px;">Tài liệu đã lưu</th>
                        <th style="padding: 10px 12px;">Ngày tạo</th>
                        <th style="padding: 10px 12px; text-align: right;">Truy cập</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pagedGroups as $gItem): ?>
                        <tr style="border-bottom: 1px solid var(--wiki-border-light);">
                            <td style="padding: 10px 12px; font-weight: 700; color: var(--wiki-blue);">#G<?= $gItem['id'] ?></td>
                            <td style="padding: 10px 12px; font-weight: 600;"><?= htmlspecialchars($gItem['name']) ?></td>
                            <td style="padding: 10px 12px;">@<?= htmlspecialchars($gItem['owner_name']) ?></td>
                            <td style="padding: 10px 12px;"><span class="badge badge-academic">👥 <?= (int)$gItem['member_count'] ?> TV</span></td>
                            <td style="padding: 10px 12px;"><span class="badge badge-tag">📁 <?= (int)$gItem['library_count'] ?> Thư viện</span></td>
                            <td style="padding: 10px 12px;"><span class="badge badge-offline">📄 <?= (int)$gItem['page_count'] ?> Bài</span></td>
                            <td style="padding: 10px 12px; color: var(--text-muted);"><?= date('d/m/Y H:i', strtotime($gItem['created_at'])) ?></td>
                            <td style="padding: 10px 12px; text-align: right;">
                                <a href="/group_detail.php?id=<?= $gItem['id'] ?>" class="btn btn-outline btn-sm">Xem nhóm &rarr;</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($groupsTotalPages > 1): ?>
            <nav class="edu-pagination">
                <?php
                $groupsBaseUrl = '/admin.php?users_page=' . $usersPage . '&groups_page=';
                ?>
                <a href="<?= $groupsBaseUrl ?>1" class="edu-pagination-item <?= $groupsPage === 1 ? 'disabled' : '' ?>">&laquo;</a>
                <?php if ($groupsPage > 1): ?>
                    <a href="<?= $groupsBaseUrl . ($groupsPage - 1) ?>" class="edu-pagination-item"><?= $groupsPage - 1 ?></a>
                <?php endif; ?>
                <span class="edu-pagination-item active"><?= $groupsPage ?></span>
                <?php if ($groupsPage < $groupsTotalPages): ?>
                    <a href="<?= $groupsBaseUrl . ($groupsPage + 1) ?>" class="edu-pagination-item"><?= $groupsPage + 1 ?></a>
                <?php endif; ?>
                <a href="<?= $groupsBaseUrl . $groupsTotalPages ?>" class="edu-pagination-item <?= $groupsPage === $groupsTotalPages ? 'disabled' : '' ?>">&raquo;</a>
                <span class="edu-pagination-info">Hiển thị <?= $groupsOffset + 1 ?>–<?= min($groupsOffset + $groupsPerPage, $groupsTotal) ?> / <?= $groupsTotal ?> nhóm</span>
            </nav>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- DANH SÁCH NGƯỜI DÙNG (CÓ PHÂN TRANG) -->
<div class="card" style="margin-bottom: 24px;">
    <div class="card-title">
        <span>👥 Danh sách Người dùng (<?= $usersTotal ?> thành viên)</span>
        <?php if ($usersTotalPages > 1): ?>
            <span style="font-size: 13px; color: var(--text-muted); font-weight: 400;">
                Trang <?= $usersPage ?>/<?= $usersTotalPages ?>
            </span>
        <?php endif; ?>
    </div>

    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
            <thead>
                <tr style="border-bottom: 2px solid var(--wiki-border-light); text-align: left; color: var(--text-muted);">
                    <th style="padding: 10px 8px; width: 50px;">STT</th>
                    <th style="padding: 10px 8px;">Tên đăng nhập</th>
                    <th style="padding: 10px 8px;">Họ và tên</th>
                    <th style="padding: 10px 8px;">Vai trò</th>
                    <th style="padding: 10px 8px;">Số thư viện</th>
                    <th style="padding: 10px 8px;">Số bài lưu</th>
                    <th style="padding: 10px 8px;">Ngày tham gia</th>
                    <th style="padding: 10px 8px; text-align: center; width: 260px;">Hành động</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pagedUsers as $index => $u): ?>
                    <?php $isTargetSuperAdmin = ($u['username'] === 'admin'); ?>
                    <tr style="border-bottom: 1px solid var(--wiki-border-light);">
                        <td style="padding: 10px 8px;">
                            <span class="badge badge-tag" style="font-weight: 700; font-size: 12px;">
                                #<?= str_pad((string)($usersOffset + $index + 1), 2, '0', STR_PAD_LEFT) ?>
                            </span>
                        </td>
                        <td style="padding: 10px 8px; font-weight: 600;">
                            <?= htmlspecialchars($u['username']) ?>
                            <?php if ($isTargetSuperAdmin): ?>
                                <span class="badge badge-amber" style="margin-left: 4px; font-size: 10px;">Super Admin</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 10px 8px;"><?= htmlspecialchars($u['fullname'] !== '' ? $u['fullname'] : '—') ?></td>
                        <td style="padding: 10px 8px;">
                            <?php if ($u['role'] === 'admin'): ?>
                                <span class="badge badge-academic">⚙️ Quản trị viên</span>
                            <?php else: ?>
                                <span class="badge badge-tag">👤 Thành viên</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 10px 8px;"><?= $u['library_count'] ?> thư viện</td>
                        <td style="padding: 10px 8px; font-weight: 600;"><?= $u['page_count'] ?> bài</td>
                        <td style="padding: 10px 8px; color: var(--text-muted); font-size: 13px;">
                            <?= date('d/m/Y H:i', strtotime($u['created_at'])) ?>
                        </td>
                        <td style="padding: 6px 8px; text-align: center;">
                            <?php $uName = htmlspecialchars($u['username']); ?>
                            <div style="display: grid; grid-template-columns: repeat(3, 76px); gap: 6px; align-items: center; justify-content: center;">
                                <!-- Cột 1: Lọc bài -->
                                <a href="/admin_user_pages.php?user_id=<?= $u['id'] ?>"
                                   class="btn btn-outline btn-sm" title="Xem bài viết của người dùng này"
                                   style="height: 28px; width: 100%; box-sizing: border-box; padding: 0 4px; display: inline-flex; align-items: center; justify-content: center; font-size: 11.5px; border-radius: 4px; white-space: nowrap; border-color: #d1d5db; color: #374151; background: #ffffff;">
                                    📄 Lọc bài
                                </a>

                                <!-- Cột 2: Nâng cấp / Giáng cấp -->
                                <?php if ($u['role'] !== 'admin' && $u['id'] !== Auth::id()): ?>
                                    <form method="POST" action="" style="margin: 0; width: 100%;"
                                          onsubmit="return eduConfirmSubmit(this, event, 'Nâng cấp <strong><?= $uName ?></strong> thành Quản trị viên?', 'Nâng Cấp Quyền', 'Xác nhận nâng cấp', false);">
                                        <input type="hidden" name="action" value="promote_admin">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="btn btn-sm"
                                                style="height: 28px; width: 100%; box-sizing: border-box; padding: 0 4px; display: inline-flex; align-items: center; justify-content: center; font-size: 11.5px; background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; border-radius: 4px; white-space: nowrap; cursor: pointer;"
                                                title="Nâng cấp thành Admin">
                                            ⬆️ Nâng cấp
                                        </button>
                                    </form>
                                <?php elseif ($currentIsSuperAdmin && $u['role'] === 'admin' && !$isTargetSuperAdmin && $u['id'] !== Auth::id()): ?>
                                    <form method="POST" action="" style="margin: 0; width: 100%;"
                                          onsubmit="return eduConfirmSubmit(this, event, 'Giáng cấp <strong><?= $uName ?></strong> xuống thành viên thường?', 'Giáng Cấp Quyền', 'Xác nhận giáng cấp');">
                                        <input type="hidden" name="action" value="demote_admin">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="btn btn-sm"
                                                style="height: 28px; width: 100%; box-sizing: border-box; padding: 0 4px; display: inline-flex; align-items: center; justify-content: center; font-size: 11.5px; background: #fffbeb; color: #9a3412; border: 1px solid #fed7aa; border-radius: 4px; white-space: nowrap; cursor: pointer;"
                                                title="Giáng cấp xuống thành viên">
                                            ⬇️ Giáng cấp
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <div style="height: 28px; width: 100%; box-sizing: border-box; display: inline-flex; align-items: center; justify-content: center; color: #cbd5e1; font-size: 12px; background: #f8fafc; border: 1px dashed #e2e8f0; border-radius: 4px;">—</div>
                                <?php endif; ?>

                                <!-- Cột 3: Xóa -->
                                <?php if ($u['id'] !== Auth::id() && !$isTargetSuperAdmin): ?>
                                    <form method="POST" action="" style="margin: 0; width: 100%;"
                                          onsubmit="return eduConfirmSubmit(this, event, 'Xóa tài khoản <strong><?= $uName ?></strong>?<br><small style=&quot;color:#b91c1c;&quot;>Toàn bộ thư viện và bài viết của người này sẽ bị xóa hoàn toàn vĩnh viễn.</small>', 'Xóa Tài Khoản', 'Xác nhận xóa');">
                                        <input type="hidden" name="action" value="admin_delete_user">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="btn btn-sm"
                                                style="height: 28px; width: 100%; box-sizing: border-box; padding: 0 4px; display: inline-flex; align-items: center; justify-content: center; font-size: 11.5px; background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; border-radius: 4px; white-space: nowrap; cursor: pointer;"
                                                title="Xóa tài khoản">
                                            🗑️ Xóa
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <div style="height: 28px; width: 100%; box-sizing: border-box; display: inline-flex; align-items: center; justify-content: center; color: #cbd5e1; font-size: 12px; background: #f8fafc; border: 1px dashed #e2e8f0; border-radius: 4px;">—</div>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($usersTotalPages > 1): ?>
        <nav class="edu-pagination">
            <?php
            $usersBaseUrl = '/admin.php?groups_page=' . $groupsPage . '&users_page=';
            ?>
            <a href="<?= $usersBaseUrl ?>1" class="edu-pagination-item <?= $usersPage === 1 ? 'disabled' : '' ?>">&laquo;</a>
            <?php if ($usersPage > 1): ?>
                <a href="<?= $usersBaseUrl . ($usersPage - 1) ?>" class="edu-pagination-item"><?= $usersPage - 1 ?></a>
            <?php endif; ?>
            <span class="edu-pagination-item active"><?= $usersPage ?></span>
            <?php if ($usersPage < $usersTotalPages): ?>
                <a href="<?= $usersBaseUrl . ($usersPage + 1) ?>" class="edu-pagination-item"><?= $usersPage + 1 ?></a>
            <?php endif; ?>
            <a href="<?= $usersBaseUrl . $usersTotalPages ?>" class="edu-pagination-item <?= $usersPage === $usersTotalPages ? 'disabled' : '' ?>">&raquo;</a>
            <span class="edu-pagination-info">Hiển thị <?= $usersOffset + 1 ?>–<?= min($usersOffset + $usersPerPage, $usersTotal) ?> / <?= $usersTotal ?> thành viên</span>
        </nav>
    <?php endif; ?>
</div>

<!-- LINK ĐI ĐẾN TRANG KIỂM DUYỆT BÀI VIẾT -->
<div class="card" style="margin-bottom: 24px; padding: 20px;">
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
        <div>
            <div style="font-size: 16px; font-weight: 700; margin-bottom: 4px; display: flex; align-items: center; gap: 8px;">
                <span>📑 Quản lý &amp; Kiểm duyệt Bài viết</span>
                <?php if ($pendingReportCount > 0): ?>
                    <span style="background: #dc2626; color: #ffffff; font-size: 12px; font-weight: 700; padding: 2px 8px; border-radius: 12px;">
                        <?= $pendingReportCount ?> bài viết bị báo cáo mới
                    </span>
                <?php endif; ?>
            </div>
            <div style="font-size: 13px; color: var(--text-muted);">
                Xem, tìm kiếm và xóa toàn bộ bài viết trong hệ thống với quyền quản trị viên.
            </div>
        </div>
        <a href="/admin_moderation.php" class="btn btn-wiki" style="display: inline-flex; align-items: center; gap: 6px;">
            <span>📋 Mở trang kiểm duyệt &rarr;</span>
            <?php if ($pendingReportCount > 0): ?>
                <span style="background: #ffffff; color: #b91c1c; font-size: 11px; font-weight: 800; padding: 1px 6px; border-radius: 10px;">
                    <?= $pendingReportCount ?>
                </span>
            <?php endif; ?>
        </a>
    </div>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
