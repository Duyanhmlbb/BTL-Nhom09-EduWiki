<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../src/GroupRepository.php';
require_once __DIR__ . '/../src/Auth.php';

if (!Auth::check()) {
    header('Location: /login.php');
    exit;
}

$currentUser = Auth::user();
$userId = (int)$currentUser['id'];
$groupRepo = new GroupRepository(db());

$message = $_GET['msg'] ?? null;
$error = null;

// Xử lý tạo nhóm mới
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_group') {
    $groupName = trim((string)($_POST['name'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));

    if ($groupName === '') {
        $error = 'Vui lòng nhập tên hội nhóm.';
    } else {
        try {
            $newGroupId = $groupRepo->createGroup($groupName, $description, $userId);
            header('Location: /group_detail.php?id=' . $newGroupId . '&msg=' . urlencode('Đã khởi tạo hội nhóm thành công!'));
            exit;
        } catch (Throwable $e) {
            $error = 'Lỗi tạo nhóm: ' . $e->getMessage();
        }
    }
}

// Lấy danh sách nhóm của user (có hỗ trợ lọc/tìm kiếm)
$searchQ = trim((string)($_GET['q'] ?? ''));
$myGroups = $groupRepo->getGroupsForUser($userId);

if ($searchQ !== '') {
    $myGroups = array_filter($myGroups, function($g) use ($searchQ) {
        $q = mb_strtolower($searchQ);
        return str_contains(mb_strtolower($g['name'] ?? ''), $q) 
            || str_contains(mb_strtolower($g['description'] ?? ''), $q);
    });
}

$pageTitle = 'Hội nhóm - Wiki Archiver';
require_once __DIR__ . '/../templates/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
    <div>
        <h1 style="margin: 0; font-size: 24px; color: #1a73e8; display: flex; align-items: center; gap: 8px;">
            👥 Hội nhóm Tài liệu
        </h1>
        <p style="margin: 4px 0 0; color: var(--text-muted); font-size: 14px;">
            Không gian làm việc nhóm: chia sẻ tài liệu học tập, mời bạn bè và phân quyền xem/chỉnh sửa/tải về.
        </p>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- FORM TẠO HỘI NHÓM MỚI -->
<div class="card" style="border-left: 4px solid #1a73e8;">
    <h3 class="card-title" style="margin-bottom: 12px;">
        <span>➕ Tạo hội nhóm mới</span>
    </h3>
    <form method="POST" action="">
        <input type="hidden" name="action" value="create_group">
        <div style="display: grid; grid-template-columns: 1.5fr 2fr auto; gap: 12px; align-items: end;">
            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label">Tên hội nhóm:</label>
                <input type="text" name="name" class="form-control" placeholder="VD: Nhóm Nghiên cứu AI, Team Dự án Lab 5..." required>
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label">Mô tả ngắn gọn:</label>
                <input type="text" name="description" class="form-control" placeholder="Mục đích chia sẻ và đối tượng của nhóm...">
            </div>
            <div>
                <button type="submit" class="btn btn-primary" style="height: 38px;">
                    + Tạo nhóm mới
                </button>
            </div>
        </div>
    </form>
</div>

<!-- DANH SÁCH CÁC NHÓM THAM GIA -->
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px;">
        <h3 class="card-title" style="margin: 0;">
            <span>📂 Các hội nhóm bạn đang tham gia (<?= count($myGroups) ?> nhóm)</span>
        </h3>
        <!-- FORM TÌM KIẾM HỘI NHÓM -->
        <form method="GET" action="" style="display: flex; gap: 8px; align-items: center;">
            <div style="position: relative;">
                <span style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 14px;">🔍</span>
                <input type="text" name="q" value="<?= htmlspecialchars($searchQ) ?>" class="form-control" placeholder="Tìm tên hoặc mô tả nhóm..." style="padding-left: 32px; width: 230px; height: 36px; font-size: 13.5px;">
            </div>
            <button type="submit" class="btn btn-primary btn-sm" style="height: 36px;">Tìm</button>
            <?php if ($searchQ !== ''): ?>
                <a href="/groups.php" class="btn btn-outline btn-sm" style="height: 36px; display: inline-flex; align-items: center;">✕ Xóa</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($searchQ !== ''): ?>
        <div style="margin-bottom: 14px; font-size: 13.5px; color: var(--text-muted);">
            Kết quả tìm kiếm cho: "<strong><?= htmlspecialchars($searchQ) ?></strong>"
        </div>
    <?php endif; ?>

    <?php if (empty($myGroups)): ?>
        <div style="text-align: center; padding: 40px 20px; color: #80868b;">
            <p style="font-size: 16px; margin-bottom: 8px;">Bạn chưa tham gia hội nhóm nào.</p>
            <p style="font-size: 13px;">Hãy tạo một nhóm mới bằng form ở trên hoặc nhờ bạn bè thêm bạn vào nhóm của họ!</p>
        </div>
    <?php else: ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 16px;">
            <?php foreach ($myGroups as $g): ?>
                <?php 
                    $roleLabel = match($g['my_role']) {
                        'owner' => '👑 Chủ nhóm',
                        'admin' => '⭐ Quản trị',
                        'editor' => '✏️ Chỉnh sửa',
                        default => '👁️ Người xem',
                    };
                    $badgeClass = match($g['my_role']) {
                        'owner' => 'badge-public',
                        'editor' => 'badge-version',
                        default => 'badge-private',
                    };
                ?>
                <div style="border: 1px solid #dadce0; border-radius: 8px; padding: 18px; background: #fff; display: flex; flex-direction: column; justify-content: space-between; transition: box-shadow 0.2s;">
                    <div>
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 8px;">
                            <h3 style="margin: 0; font-size: 17px;">
                                <a href="/group_detail.php?id=<?= $g['id'] ?>" style="color: #1a73e8; text-decoration: none;">
                                    <?= htmlspecialchars($g['name']) ?>
                                </a>
                            </h3>
                            <span class="badge <?= $badgeClass ?>" style="font-size: 11px;">
                                <?= $roleLabel ?>
                            </span>
                        </div>
                        <p style="font-size: 13px; color: #5f6368; margin: 0 0 14px; min-height: 38px;">
                            <?= htmlspecialchars($g['description'] ?: 'Chưa có mô tả chi tiết.') ?>
                        </p>
                    </div>

                    <div style="border-top: 1px solid #f1f3f4; padding-top: 12px; display: flex; justify-content: space-between; align-items: center; font-size: 12px; color: #5f6368;">
                        <div style="display: flex; gap: 10px;">
                            <span>👥 <strong><?= (int)$g['member_count'] ?></strong> TV</span>
                            <span>📁 <strong><?= (int)$g['library_count'] ?></strong> Thư viện</span>
                            <span>📄 <strong><?= (int)$g['page_count'] ?></strong> Bài</span>
                        </div>
                        <a href="/group_detail.php?id=<?= $g['id'] ?>" class="btn btn-outline btn-sm">
                            Vào nhóm &rarr;
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
