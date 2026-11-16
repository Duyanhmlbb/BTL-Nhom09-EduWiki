<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../src/FriendRepository.php';
require_once __DIR__ . '/../src/Auth.php';

if (!Auth::check()) {
    header('Location: /login.php');
    exit;
}

$currentUser = Auth::user();
$userId = (int)$currentUser['id'];
$friendRepo = new FriendRepository(db());

$message = $_GET['msg'] ?? null;
$error = null;

// Xử lý các hành động kết bạn
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'send_request') {
        $targetUserId = (int)($_POST['target_user_id'] ?? 0);
        if ($targetUserId > 0) {
            $ok = $friendRepo->sendFriendRequest($userId, $targetUserId);
            if ($ok) {
                header('Location: /friends.php?msg=' . urlencode('Đã gửi lời mời kết bạn thành công!'));
                exit;
            } else {
                $error = 'Không thể gửi lời mời (có thể lời mời đã tồn tại hoặc tài khoản không hợp lệ).';
            }
        }
    } elseif ($action === 'accept_request') {
        $requestId = (int)($_POST['request_id'] ?? 0);
        if ($requestId > 0) {
            $ok = $friendRepo->acceptFriendRequest($requestId, $userId);
            if ($ok) {
                header('Location: /friends.php?msg=' . urlencode('Đã chấp nhận lời mời kết bạn! Bây giờ hai bạn có thể chia sẻ tài liệu và thêm vào nhóm.'));
                exit;
            } else {
                $error = 'Lỗi chấp nhận lời mời.';
            }
        }
    } elseif ($action === 'remove_friendship') {
        $friendshipId = (int)($_POST['friendship_id'] ?? 0);
        if ($friendshipId > 0) {
            $friendRepo->removeFriendship($friendshipId, $userId);
            header('Location: /friends.php?msg=' . urlencode('Đã xóa quan hệ bạn bè / hủy lời mời kết bạn.'));
            exit;
        }
    }
}

// Tìm kiếm người dùng
$searchQuery = trim((string)($_GET['q'] ?? ''));
$searchResults = [];
if ($searchQuery !== '') {
    $searchResults = $friendRepo->searchUsers($userId, $searchQuery);
}

// Lấy danh sách bạn bè & lời mời
$friends = $friendRepo->getFriends($userId);
$pendingRequests = $friendRepo->getPendingRequests($userId);
$sentRequests = $friendRepo->getSentRequests($userId);

$pageTitle = 'Quản lý Bạn bè & Kết nối';
require_once __DIR__ . '/../templates/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
    <div>
        <h1 style="margin: 0; font-size: 24px; color: #1a73e8; display: flex; align-items: center; gap: 8px;">
            🤝 Kết nối & Quản lý Bạn bè
        </h1>
        <p style="margin: 4px 0 0; color: var(--text-muted); font-size: 14px;">
            Kết bạn với các thành viên khác để cùng chia sẻ tư liệu và tham gia hội nhóm.
        </p>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- TÌM KIẾM NGƯỜI DÙNG ĐỂ KẾT BẠN -->
<div class="card" style="border-left: 4px solid #1a73e8;">
    <h3 class="card-title" style="margin-bottom: 12px;">
        <span>🔍 Tìm kiếm thành viên để kết bạn</span>
    </h3>
    <form method="GET" action="" style="display: flex; gap: 10px;">
        <input type="text" name="q" class="form-control" placeholder="Nhập tên đăng nhập hoặc họ tên người dùng..." value="<?= htmlspecialchars($searchQuery) ?>" required>
        <button type="submit" class="btn btn-primary" style="white-space: nowrap;">
            Tìm kiếm
        </button>
        <?php if ($searchQuery !== ''): ?>
            <a href="/friends.php" class="btn btn-outline">Bỏ tìm</a>
        <?php endif; ?>
    </form>

    <?php if ($searchQuery !== ''): ?>
        <div style="margin-top: 16px;">
            <h4 style="font-size: 14px; color: #5f6368; margin-bottom: 10px;">
                Kết quả tìm kiếm cho: "<strong><?= htmlspecialchars($searchQuery) ?></strong>" (<?= count($searchResults) ?> kết quả)
            </h4>
            <?php if (empty($searchResults)): ?>
                <p style="color: #80868b; font-size: 13px;">Không tìm thấy người dùng nào phù hợp.</p>
            <?php else: ?>
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px;">
                    <?php foreach ($searchResults as $userItem): ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px; border: 1px solid #dadce0; border-radius: 8px; background: #fafafa;">
                            <div>
                                <strong style="display: block; font-size: 14px; color: #202124;">
                                    <?= htmlspecialchars($userItem['fullname'] ?: $userItem['username']) ?>
                                </strong>
                                <span style="font-size: 12px; color: #5f6368;">@<?= htmlspecialchars($userItem['username']) ?></span>
                            </div>
                            <div>
                                <?php if ($userItem['friendship_status'] === 'accepted'): ?>
                                    <span class="badge badge-public" style="font-size: 11px;">✅ Đã là bạn bè</span>
                                <?php elseif ($userItem['friendship_status'] === 'pending'): ?>
                                    <?php if ((int)$userItem['req_sender_id'] === $userId): ?>
                                        <span class="badge badge-version" style="font-size: 11px;">⏳ Đã gửi lời mời</span>
                                    <?php else: ?>
                                        <form method="POST" action="" style="margin: 0;">
                                            <input type="hidden" name="action" value="accept_request">
                                            <input type="hidden" name="request_id" value="<?= $userItem['friendship_id'] ?>">
                                            <button type="submit" class="btn btn-success btn-sm">Chấp nhận</button>
                                        </form>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <form method="POST" action="" style="margin: 0;">
                                        <input type="hidden" name="action" value="send_request">
                                        <input type="hidden" name="target_user_id" value="<?= $userItem['id'] ?>">
                                        <button type="submit" class="btn btn-primary btn-sm">+ Kết bạn</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<!-- LỜI MỜI KẾT BẠN ĐANG CHỜ DUYỆT (NẾU CÓ) -->
<?php if (!empty($pendingRequests)): ?>
    <div class="card" style="border-left: 4px solid #ea8600; background: #fefcf8;">
        <h3 class="card-title" style="color: #b06000; margin-bottom: 12px;">
            <span>📬 Lời mời kết bạn đang chờ bạn đồng ý (<?= count($pendingRequests) ?>)</span>
        </h3>
        <div style="display: flex; flex-direction: column; gap: 8px;">
            <?php foreach ($pendingRequests as $req): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 14px; background: #fff; border: 1px solid #f9e2b8; border-radius: 6px;">
                    <div>
                        <strong style="color: #202124;"><?= htmlspecialchars($req['fullname'] ?: $req['username']) ?></strong>
                        <span style="font-size: 12px; color: #5f6368; margin-left: 6px;">(@<?= htmlspecialchars($req['username']) ?>)</span>
                        <div style="font-size: 11px; color: #80868b;">Gửi lúc: <?= date('d/m/Y H:i', strtotime($req['created_at'])) ?></div>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <form method="POST" action="" style="margin: 0;">
                            <input type="hidden" name="action" value="accept_request">
                            <input type="hidden" name="request_id" value="<?= $req['request_id'] ?>">
                            <button type="submit" class="btn btn-success btn-sm">Chấp nhận</button>
                        </form>
                        <form method="POST" action="" style="margin: 0;" onsubmit="return eduConfirmSubmit(this, event, 'Từ chối lời mời kết bạn này?', 'Từ Chối Lời Mời', 'Từ chối');">
                            <input type="hidden" name="action" value="remove_friendship">
                            <input type="hidden" name="friendship_id" value="<?= $req['request_id'] ?>">
                            <button type="submit" class="btn btn-outline btn-sm">Từ chối</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<!-- DANH SÁCH BẠN BÈ HIỆN TẠI -->
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
        <h3 class="card-title" style="margin: 0;">
            <span>👥 Danh sách bạn bè chính thức (<?= count($friends) ?> người bạn)</span>
        </h3>
        <span style="font-size: 12px; color: #5f6368;">
            Bạn bè có thể được mời trực tiếp vào các Hội nhóm Wiki Archiver.
        </span>
    </div>

    <?php if (empty($friends)): ?>
        <div style="text-align: center; padding: 40px 20px; color: #80868b;">
            <p style="font-size: 16px; margin-bottom: 6px;">Bạn chưa có người bạn nào trong danh sách.</p>
            <p style="font-size: 13px;">Hãy dùng ô tìm kiếm ở trên để kết nối với các thành viên khác trong hệ thống!</p>
        </div>
    <?php else: ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 14px;">
            <?php foreach ($friends as $f): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 14px; border: 1px solid #dadce0; border-radius: 8px; background: #fff;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="width: 40px; height: 40px; border-radius: 50%; background: #e8f0fe; color: #1a73e8; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 16px;">
                            <?= mb_strtoupper(mb_substr($f['fullname'] ?: $f['username'], 0, 1)) ?>
                        </div>
                        <div>
                            <strong style="display: block; font-size: 14px; color: #202124;">
                                <?= htmlspecialchars($f['fullname'] ?: $f['username']) ?>
                            </strong>
                            <span style="font-size: 12px; color: #5f6368;">@<?= htmlspecialchars($f['username']) ?></span>
                            <div style="font-size: 11px; color: #80868b;">Bạn bè từ: <?= date('d/m/Y', strtotime($f['friends_since'])) ?></div>
                        </div>
                    </div>
                    <div>
                        <?php $fName = htmlspecialchars($f['fullname'] ?: $f['username']); ?>
                        <form method="POST" action="" onsubmit="return eduConfirmSubmit(this, event, 'Hủy kết bạn với <?= $fName ?>?', 'Hủy Kết Bạn', 'Xác nhận hủy');" style="margin: 0;">
                            <input type="hidden" name="action" value="remove_friendship">
                            <input type="hidden" name="friendship_id" value="<?= $f['friendship_id'] ?>">
                            <button type="submit" class="btn btn-outline btn-sm" style="color: #d93025;" title="Hủy kết bạn">
                                Xóa bạn
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>

