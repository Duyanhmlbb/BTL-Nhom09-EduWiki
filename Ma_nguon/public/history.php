<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../src/ArchiveRepository.php';
require_once __DIR__ . '/../src/Auth.php';

$currentUser = Auth::user();
if (!$currentUser) {
    header('Location: /login.php?msg=' . urlencode('Vui lòng đăng nhập để xem lịch sử đọc bài!'));
    exit;
}

$db = db();
$repo = new ArchiveRepository($db);
$userId = (int)$currentUser['id'];

// Xóa lịch sử đọc bài
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clear_history') {
    $db->prepare("DELETE FROM reading_history WHERE user_id = ?")->execute([$userId]);
    header('Location: /history.php?msg=' . urlencode('Đã xóa toàn bộ lịch sử đọc bài!'));
    exit;
}

$history = $repo->getReadingHistory($userId, 50);

$pageTitle = 'Lịch Sử Đọc Bài - Wiki Archiver';
require_once __DIR__ . '/../templates/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-top: 20px; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
    <div>
        <h1 style="margin: 0; font-size: 24px; color: var(--wiki-blue); display: flex; align-items: center; gap: 8px;">
            <span>🕒</span> Lịch Sử Truy Cập Bài Viết
        </h1>
        <p style="margin: 4px 0 0; color: var(--text-muted); font-size: 14px;">
            Danh sách các tài liệu bạn đã đọc gần đây để tra cứu lại nhanh chóng, không mất công tìm kiếm.
        </p>
    </div>

    <?php if (!empty($history)): ?>
        <form method="POST" action="" onsubmit="return eduConfirmSubmit(this, event, 'Bạn có chắc chắn muốn xóa toàn bộ lịch sử đọc bài?<br><small style=\'color:#b91c1c;\'>Lịch sử sẽ bị xóa vĩnh viễn.</small>', 'Xóa Toàn Bộ Lịch Sử', 'Xác nhận xóa');">
            <input type="hidden" name="action" value="clear_history">
            <button type="submit" class="btn btn-outline btn-sm" style="color: var(--danger); border-color: #fecaca; background: #fff5f5;">
                🗑️ Xóa toàn bộ lịch sử
            </button>
        </form>
    <?php endif; ?>
</div>

<?php if (isset($_GET['msg'])): ?>
    <div class="alert alert-success"><?= htmlspecialchars($_GET['msg']) ?></div>
<?php endif; ?>

<div class="card" style="padding: 0; overflow: hidden;">
    <?php if (empty($history)): ?>
        <div style="text-align: center; padding: 50px 20px; color: var(--text-muted);">
            <div style="font-size: 40px; margin-bottom: 10px;">📖</div>
            <h4 style="margin: 0 0 6px 0; color: var(--text-main);">Bạn chưa có lịch sử đọc bài nào</h4>
            <p style="font-size: 13.5px; margin: 0 0 16px 0;">Khi bạn đọc bất kỳ bài viết nào trong Thư viện chung hoặc Kho cá nhân, hệ thống sẽ tự động lưu lại ở đây.</p>
            <a href="/index.php" class="btn btn-wiki btn-sm">Khám phá bài viết ngay</a>
        </div>
    <?php else: ?>
        <div style="display: flex; flex-direction: column;">
            <?php foreach ($history as $idx => $item): ?>
                <?php $isAvail = !empty($item['is_available']) && !empty($item['page_id']); ?>
                <div style="padding: 16px 20px; border-bottom: 1px solid var(--wiki-border-light); display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; <?= $idx % 2 === 1 ? 'background: #fbfcfe;' : '' ?>">
                    <div style="flex: 1; min-width: 260px;">
                        <h4 style="margin: 0 0 6px 0; font-size: 16px;">
                            <?php if ($isAvail): ?>
                                <a href="/view.php?id=<?= $item['page_id'] ?>" style="color: var(--link-color); text-decoration: none;">
                                    <?= htmlspecialchars($item['title']) ?>
                                </a>
                            <?php else: ?>
                                <a href="/view.php?id=<?= (int)$item['page_id'] ?>&deleted=1" style="color: #64748b; text-decoration: none;">
                                    <span style="text-decoration: line-through;"><?= htmlspecialchars($item['title']) ?></span>
                                    <span class="badge" style="background: #fee2e2; color: #b91c1c; font-size: 11px; margin-left: 6px; font-weight: normal; text-decoration: none;">Bài viết đã bị xóa</span>
                                </a>
                            <?php endif; ?>
                        </h4>
                        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap; font-size: 12.5px; color: var(--text-muted);">
                            <span class="badge" style="background: #e8f0fe; color: #0f4c81; font-weight: 600;">
                                <?= htmlspecialchars($item['library_name']) ?>
                            </span>
                            <span>Đọc lần cuối: <strong><?= date('d/m/Y H:i', strtotime($item['last_read_at'])) ?></strong></span>
                            <span>&bull; Đã xem: <strong><?= (int)$item['read_count'] ?> lượt</strong></span>
                        </div>
                    </div>

                    <div style="display: flex; gap: 8px; align-items: center;">
                        <?php if ($isAvail): ?>
                            <a href="/view.php?id=<?= $item['page_id'] ?>" class="btn btn-wiki btn-sm" style="color: #ffffff !important;">
                                📖 Đọc lại
                            </a>
                        <?php else: ?>
                            <a href="/view.php?id=<?= (int)$item['page_id'] ?>&deleted=1" class="btn btn-outline btn-sm" style="color: #64748b; background: #f8fafc;">
                                ⚠️ Xem trạng thái
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
