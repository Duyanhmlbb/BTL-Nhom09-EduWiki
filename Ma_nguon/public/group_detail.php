<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../src/GroupRepository.php';
require_once __DIR__ . '/../src/FriendRepository.php';
require_once __DIR__ . '/../src/ArchiveRepository.php';
require_once __DIR__ . '/../src/WebFetcher.php';
require_once __DIR__ . '/../src/Auth.php';

if (!Auth::check()) {
    header('Location: /login.php');
    exit;
}

$currentUser = Auth::user();
$userId = (int)$currentUser['id'];
$isGlobalAdmin = ($currentUser['role'] === 'admin');

$groupId = (int)($_GET['id'] ?? 0);
$groupRepo = new GroupRepository(db());
$friendRepo = new FriendRepository(db());
$archiveRepo = new ArchiveRepository(db(), $userId);

$group = $groupRepo->getGroup($groupId, $userId);
if (!$group) {
    die('Không tìm thấy hội nhóm hoặc nhóm đã bị xóa.');
}

// Kiểm tra quyền vào nhóm: phải là thành viên hoặc Global Admin
$myRole = $group['my_role'];
if (!$myRole && !$isGlobalAdmin) {
    die('Bạn không phải là thành viên của hội nhóm này.');
}

$isOwner = ($myRole === 'owner' || $isGlobalAdmin);
$isGroupAdmin = in_array($myRole, ['owner', 'admin'], true) || $isGlobalAdmin;
$isEditor = in_array($myRole, ['owner', 'admin', 'editor'], true) || $isGlobalAdmin;
$isViewer = ($myRole === 'viewer' && !$isGlobalAdmin);
$canDownload = (bool)($group['my_can_download'] ?? false) || $isEditor;

$message = $_GET['msg'] ?? null;
$error = null;

// XỬ LÝ POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Thêm thành viên vào nhóm (Quản trị viên, Chủ nhóm, hoặc Editor)
    if ($action === 'add_member') {
        if (!$isEditor) {
            $error = 'Bạn không có quyền thêm thành viên vào nhóm.';
        } else {
            $friendId = (int)($_POST['friend_id'] ?? 0);
            $role = (string)($_POST['role'] ?? 'viewer');
            $allowDl = isset($_POST['can_download']);

            // Nếu người thực hiện là Admin thường hoặc Editor, không được tự phong Admin
            if (!$isOwner && $role === 'admin') {
                $role = 'editor';
            }
            // Editor chỉ được cấp viewer hoặc editor
            if (!$isGroupAdmin && $role === 'admin') {
                $role = 'editor';
            }

            if ($friendId <= 0) {
                $error = 'Vui lòng chọn bạn bè để thêm vào nhóm.';
            } else {
                $groupRepo->addMember($groupId, $friendId, $role, $allowDl);
                header("Location: /group_detail.php?id={$groupId}&msg=" . urlencode('Đã thêm thành viên vào nhóm thành công!'));
                exit;
            }
        }
    }

    // 2. Cập nhật quyền thành viên (Quản trị viên hoặc Chủ nhóm)
    elseif ($action === 'update_member_perm') {
        if (!$isGroupAdmin) {
            $error = 'Bạn không có quyền phân quyền thành viên trong nhóm.';
        } else {
            $targetUserId = (int)($_POST['target_user_id'] ?? 0);
            $role = (string)($_POST['role'] ?? 'viewer');
            $allowDl = isset($_POST['can_download']);

            // Admin thường không thể tự phong Admin cho người khác
            if (!$isOwner && $role === 'admin') {
                $role = 'editor';
            }

            $ok = $groupRepo->updateMemberPermission($groupId, $targetUserId, $role, $allowDl, $userId);
            if ($ok) {
                header("Location: /group_detail.php?id={$groupId}&msg=" . urlencode('Đã cập nhật phân quyền thành viên thành công!'));
                exit;
            } else {
                $error = 'Không thể cập nhật quyền (bạn không đủ thẩm quyền hoặc không thể sửa quyền của Chủ nhóm/Quản trị viên khác).';
            }
        }
    }

    // 3. Xóa thành viên khỏi nhóm (Quản trị viên, Chủ nhóm hoặc tự rời)
    elseif ($action === 'remove_member') {
        $targetUserId = (int)($_POST['target_user_id'] ?? 0);
        $isSelf = ($targetUserId === $userId);

        if (!$isGroupAdmin && !$isSelf) {
            $error = 'Bạn không có quyền xóa thành viên khỏi nhóm. Quyền này dành cho Quản trị viên và Chủ nhóm.';
        } else {
            $ok = $groupRepo->removeMember($groupId, $targetUserId, $userId);
            if ($ok) {
                if ($isSelf) {
                    header("Location: /groups.php?msg=" . urlencode('Bạn đã rời khỏi nhóm thành công.'));
                    exit;
                }
                header("Location: /group_detail.php?id={$groupId}&msg=" . urlencode('Đã xóa thành viên khỏi nhóm thành công.'));
                exit;
            } else {
                $error = 'Không thể xóa thành viên (Chủ nhóm không thể tự rời hoặc Quản trị viên không thể xóa Chủ nhóm).';
            }
        }
    }

    // 4. Tạo thư viện mới trong nhóm (Người chỉnh sửa, Quản trị viên, Chủ nhóm)
    elseif ($action === 'create_group_lib') {
        if (!$isEditor) {
            $error = 'Bạn không có quyền tạo thư viện trong nhóm. Quyền này dành cho Người chỉnh sửa và Quản trị viên.';
        } else {
            $libName = trim((string)($_POST['lib_name'] ?? ''));
            $allowDl = isset($_POST['allow_download']);

            if ($libName === '') {
                $error = 'Tên thư viện không được để trống.';
            } else {
                try {
                    $groupRepo->createGroupLibrary($groupId, $libName, $userId, $allowDl);
                    header("Location: /group_detail.php?id={$groupId}&msg=" . urlencode('Đã tạo thư viện mới trong nhóm thành công!'));
                    exit;
                } catch (Throwable $e) {
                    $error = $e->getMessage();
                }
            }
        }
    }

    // 4b. Đổi tên thư viện trong nhóm (Người chỉnh sửa, Quản trị viên, Chủ nhóm)
    elseif ($action === 'rename_group_lib') {
        if (!$isEditor) {
            $error = 'Bạn không có quyền đổi tên thư viện trong nhóm. Quyền này dành cho Nhóm trưởng và Người chỉnh sửa.';
        } else {
            $libIdToRename = (int)($_POST['library_id'] ?? 0);
            $newName = trim((string)($_POST['new_name'] ?? ''));
            if ($libIdToRename > 0 && $newName !== '') {
                if ($groupRepo->renameGroupLibrary($groupId, $libIdToRename, $newName, $userId)) {
                    header("Location: /group_detail.php?id={$groupId}&msg=" . urlencode("Đã đổi tên thư viện thành '{$newName}' thành công!"));
                    exit;
                } else {
                    $error = 'Không thể đổi tên thư viện này.';
                }
            }
        }
    }

    // 4c. Xóa thư viện trong nhóm (Người chỉnh sửa, Quản trị viên, Chủ nhóm)
    elseif ($action === 'delete_group_lib') {
        if (!$isEditor) {
            $error = 'Bạn không có quyền xóa thư viện trong nhóm. Quyền này dành cho Nhóm trưởng và Người chỉnh sửa.';
        } else {
            $libIdToDelete = (int)($_POST['library_id'] ?? 0);
            if ($libIdToDelete > 0) {
                if ($groupRepo->deleteGroupLibrary($groupId, $libIdToDelete, $userId)) {
                    header("Location: /group_detail.php?id={$groupId}&msg=" . urlencode('Đã xóa thư viện và các bài viết bên trong thành công!'));
                    exit;
                } else {
                    $error = 'Không thể xóa thư viện nhóm này.';
                }
            }
        }
    }

    // 5a. Chia sẻ bài viết từ Kho của tôi vào nhóm (Chỉ Người chỉnh sửa & Quản trị viên)
    elseif ($action === 'share_from_my_storage') {
        if (!$isEditor) {
            $error = 'Bạn không có quyền thêm bài viết vào nhóm. Người xem chỉ được xem bài viết và tải về các file cho phép.';
        } else {
            $sourcePageId = (int)($_POST['source_page_id'] ?? 0);
            $targetLibId = (int)($_POST['library_id'] ?? 0);

            if ($sourcePageId <= 0 || $targetLibId <= 0) {
                $error = 'Vui lòng chọn bài viết từ kho cá nhân và thư viện đích trong nhóm.';
            } else {
                // Lấy snapshot mới nhất của bài viết từ kho cá nhân
                $stmtSrc = db()->prepare("
                    SELECT p.id, p.url, p.title, s.id AS snapshot_id, s.content_html
                    FROM saved_pages p
                    JOIN libraries l ON l.id = p.library_id
                    JOIN snapshots s ON s.saved_page_id = p.id
                         AND s.version_number = (SELECT MAX(version_number) FROM snapshots WHERE saved_page_id = p.id)
                    WHERE p.id = ? AND l.user_id = ?
                ");
                $stmtSrc->execute([$sourcePageId, $userId]);
                $srcPage = $stmtSrc->fetch(PDO::FETCH_ASSOC);

                if (!$srcPage) {
                    $error = 'Không tìm thấy bài viết trong kho của bạn hoặc bạn không sở hữu bài viết này.';
                } else {
                    try {
                        $savedId = $archiveRepo->savePage(
                            $targetLibId,
                            $srcPage['url'],
                            $srcPage['content_html'],
                            [
                                'localize_images' => true,
                                'title' => $srcPage['title'],
                                'source_snapshot_id' => (int)$srcPage['snapshot_id']
                            ],
                            $userId
                        );

                        // Thiết lập phân quyền chi tiết cho bài viết nếu có gửi kèm
                        if (!empty($_POST['perms']) && is_array($_POST['perms'])) {
                            $groupRepo->setPagePermissions($groupId, $savedId, $_POST['perms'], $userId);
                        }

                        header("Location: /view.php?id={$savedId}&msg=" . urlencode('Đã chia sẻ bài viết vào nhóm thành công! Dưới đây là bản đọc offline của bạn.'));
                        exit;
                    } catch (Throwable $e) {
                        $error = 'Lỗi chia sẻ bài viết: ' . $e->getMessage();
                    }
                }
            }
        }
    }

    // 5b. Lưu bài viết qua URL vào thư viện của nhóm (Chỉ Người chỉnh sửa & Quản trị viên)
    elseif ($action === 'save_page_to_group') {
        if (!$isEditor) {
            $error = 'Bạn không có quyền thêm bài viết vào nhóm. Người xem chỉ được xem bài viết và tải về các file cho phép.';
        } else {
            $targetLibId = (int)($_POST['library_id'] ?? 0);
            $url = trim((string)($_POST['url'] ?? ''));

            if ($url === '' || $targetLibId <= 0) {
                $error = 'Vui lòng nhập đường dẫn URL và chọn thư viện trong nhóm.';
            } else {
                $fetchRes = WebFetcher::fetch($url);
                if (!$fetchRes['success']) {
                    $error = 'Lỗi tải trang: ' . $fetchRes['error'];
                } else {
                    try {
                        $savedId = $archiveRepo->savePage($targetLibId, $fetchRes['final_url'], $fetchRes['html'], ['localize_images' => true], $userId);

                        if (!empty($_POST['perms']) && is_array($_POST['perms'])) {
                            $groupRepo->setPagePermissions($groupId, $savedId, $_POST['perms'], $userId);
                        }

                        header("Location: /view.php?id={$savedId}&msg=" . urlencode('Đã lưu bài viết vào thư viện nhóm thành công! Dưới đây là bản đọc offline của bạn.'));
                        exit;
                    } catch (Throwable $e) {
                        $error = 'Lỗi lưu bài: ' . $e->getMessage();
                    }
                }
            }
        }
    }

    // 6. Cập nhật phân quyền bài viết (cho từng thành viên)
    elseif ($action === 'update_page_perms') {
        $pageId = (int)($_POST['page_id'] ?? 0);
        $perms = $_POST['perms'] ?? [];
        if ($pageId > 0 && is_array($perms)) {
            $ok = $groupRepo->setPagePermissions($groupId, $pageId, $perms, $userId);
            if ($ok) {
                header("Location: /group_detail.php?id={$groupId}&lib={$selectedLibId}&msg=" . urlencode('Đã cập nhật phân quyền cho bài viết thành công!'));
                exit;
            } else {
                $error = 'Bạn không có quyền thiết lập phân quyền cho bài viết này (chỉ tác giả hoặc Quản trị viên).';
            }
        }
    }

    // 7. Xóa bài viết khỏi nhóm (Quản trị viên hoặc tác giả bài viết)
    elseif ($action === 'delete_group_page') {
        $pageId = (int)($_POST['page_id'] ?? 0);
        if ($pageId > 0) {
            $ok = $groupRepo->deleteGroupPage($groupId, $pageId, $userId);
            if ($ok) {
                header("Location: /group_detail.php?id={$groupId}&lib={$selectedLibId}&msg=" . urlencode('Đã xóa bài viết khỏi nhóm thành công!'));
                exit;
            } else {
                $error = 'Bạn không có quyền xóa bài viết này (chỉ tác giả hoặc Quản trị viên).';
            }
        }
    }
}

// Lấy danh sách thư viện trong nhóm
$libraries = $groupRepo->getGroupLibraries($groupId);

// Phân trang danh sách thư viện trong nhóm: trên 4 thư viện thì có nút sang trang
$libsPerPage = 4;
$totalLibs = count($libraries);
$totalLibPages = max(1, (int)ceil($totalLibs / $libsPerPage));
$currentLibPage = max(1, min($totalLibPages, (int)($_GET['lib_p'] ?? 1)));
$libOffset = ($currentLibPage - 1) * $libsPerPage;
$pagedLibraries = array_slice($libraries, $libOffset, $libsPerPage);

$selectedLibId = isset($_GET['lib']) ? (int)$_GET['lib'] : 0;
$selectedLib = null;
if ($selectedLibId > 0) {
    foreach ($libraries as $l) {
        if ((int)$l['id'] === $selectedLibId) {
            $selectedLib = $l;
            break;
        }
    }
    // Nếu ID không hợp lệ trong nhóm thì reset về 0
    if (!$selectedLib) {
        $selectedLibId = 0;
    }
}

// Tìm kiếm trong thư viện nhóm
$searchQ = trim($_GET['q'] ?? '');

// Lấy danh sách bài viết trong thư viện đang chọn (có hỗ trợ kiểm tra quyền xem & tìm kiếm)
$pagesInLib = [];
if ($selectedLibId > 0) {
    $stmtPages = db()->prepare("
        SELECT p.id, p.url, p.title, p.created_at, p.user_id AS author_id, u.username AS author_name,
               s.version_number,
               (SELECT COUNT(*) FROM assets WHERE snapshot_id = s.id) AS asset_count
        FROM saved_pages p
        LEFT JOIN users u ON u.id = p.user_id
        LEFT JOIN snapshots s ON s.saved_page_id = p.id 
             AND s.version_number = (SELECT MAX(version_number) FROM snapshots WHERE saved_page_id = p.id)
        WHERE p.library_id = ?
        ORDER BY p.id DESC
    ");
    $stmtPages->execute([$selectedLibId]);
    $rawPages = $stmtPages->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rawPages as $rp) {
        $pPerm = $archiveRepo->getUserPagePermission((int)$rp['id'], $userId);
        if (empty($pPerm['can_view'])) {
            continue; // Ẩn bài viết nếu thành viên này không có quyền xem
        }
        $rp['can_edit'] = !empty($pPerm['can_edit']);
        $rp['can_download'] = !empty($pPerm['can_download']);
        $rp['role_desc'] = $pPerm['role_desc'] ?? 'Thành viên';

        if ($searchQ !== '') {
            $qLower = mb_strtolower($searchQ);
            $titleLower = mb_strtolower($rp['title'] ?? '');
            $urlLower = mb_strtolower($rp['url'] ?? '');
            if (!str_contains($titleLower, $qLower) && !str_contains($urlLower, $qLower)) {
                continue;
            }
        }
        $pagesInLib[] = $rp;
    }
}

// =========================================================================
// PHÂN TRANG THƯ VIỆN NHÓM: TỐI ĐA 5 BÀI VIẾT MỖI TRANG
// =========================================================================
$perPage = 5;
$totalGroupPages = count($pagesInLib);
$totalPages = max(1, (int)ceil($totalGroupPages / $perPage));
$currentPage = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));
$offset = ($currentPage - 1) * $perPage;
$pagedPagesInLib = array_slice($pagesInLib, $offset, $perPage);

// Lấy danh sách bài viết từ Kho của tôi để thành viên tiện chọn chia sẻ vào nhóm
$stmtMyPersonalPages = db()->prepare("
    SELECT p.id, p.title, p.url, l.name AS lib_name
    FROM saved_pages p
    JOIN libraries l ON l.id = p.library_id
    WHERE l.user_id = ? AND l.group_id IS NULL
    ORDER BY p.id DESC
    LIMIT 150
");
$stmtMyPersonalPages->execute([$userId]);
$myPersonalPages = $stmtMyPersonalPages->fetchAll(PDO::FETCH_ASSOC);

// Lấy danh sách thành viên nhóm
$members = $groupRepo->getGroupMembers($groupId);

// Lấy danh sách bạn bè của user (để mời vào nhóm)
$allFriends = $friendRepo->getFriends($userId);
$currentMemberUserIds = array_column($members, 'user_id');
$friendsToInvite = array_filter($allFriends, function($f) use ($currentMemberUserIds) {
    return !in_array((int)$f['id'], $currentMemberUserIds, true);
});

$pageTitle = 'Nhóm: ' . htmlspecialchars($group['name']);
require_once __DIR__ . '/../templates/header.php';
?>

<!-- HEADER NHÓM -->
<div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 20px; flex-wrap: wrap; gap: 12px; background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #dadce0;">
    <div>
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 4px;">
            <a href="/groups.php" style="color: #5f6368; text-decoration: none; font-size: 14px;">&larr; Hội nhóm</a>
            <span style="color: #dadce0;">/</span>
            <span class="badge badge-public" style="font-size: 11px;">Hội nhóm Wiki Archiver</span>
        </div>
        <h1 style="margin: 0 0 6px; font-size: 24px; color: #202124;">
            📁 <?= htmlspecialchars($group['name']) ?>
        </h1>
        <p style="margin: 0; color: #5f6368; font-size: 14px;">
            <?= htmlspecialchars($group['description'] ?: 'Không có mô tả.') ?> &bull; 
            Chủ nhóm: <strong><?= htmlspecialchars($group['owner_fullname'] ?: $group['owner_username']) ?></strong>
        </p>
    </div>

    <div style="display: flex; align-items: center; gap: 8px;">
        <?php
        $myRoleLabel = match($myRole) {
            'owner' => '👑 Chủ nhóm (Owner)',
            'admin' => '⭐ Quản trị viên (Admin)',
            'editor' => '✏️ Chỉnh sửa (Editor)',
            default => '👁️ Người xem (Viewer)',
        };
        ?>
        <span class="badge badge-version" style="font-size: 13px; padding: 6px 12px;">
            Vai trò của bạn: <strong><?= $myRoleLabel ?></strong>
        </span>
        <span class="badge" style="font-size: 13px; padding: 6px 12px; background: <?= $canDownload ? '#e6f4ea' : '#fce8e6' ?>; color: <?= $canDownload ? '#137333' : '#c5221f' ?>; border: 1px solid <?= $canDownload ? '#ceead6' : '#fad2cf' ?>;">
            <?= $canDownload ? '📥 Được phép tải về' : '🔒 Khóa quyền tải về' ?>
        </span>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- BỐ CỤC CHÍNH 2 CỘT -->
<div style="display: grid; grid-template-columns: 1fr 340px; gap: 20px; align-items: start;">

    <!-- CỘT CHÍNH (TRÁI): THƯ VIỆN & TÀI LIỆU CỦA NHÓM -->
    <div>
        <?php if ($isEditor): ?>
            <!-- FORM ĐƯA TÀI LIỆU VÀO NHÓM (DÀNH CHO NGƯỜI CHỈNH SỬA & QUẢN TRỊ VIÊN) -->
            <div class="card" style="border-left: 4px solid var(--accent); margin-bottom: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                    <h3 class="card-title" style="margin: 0;">
                        <span>➕ Đưa tài liệu vào Thư viện nhóm</span>
                    </h3>
                    <div style="display: flex; gap: 6px;">
                        <button type="button" class="btn btn-outline btn-sm active" id="btn-tab-mystorage" onclick="switchUploadMode('storage')">
                            📁 Từ Kho của tôi
                        </button>
                        <button type="button" class="btn btn-outline btn-sm" id="btn-tab-url" onclick="switchUploadMode('url')">
                            🔗 Nhập link URL
                        </button>
                    </div>
                </div>

                <!-- CHẾ ĐỘ 1: CHỌN TỪ KHO CỦA TÔI -->
                <form method="POST" action="" id="form-upload-storage">
                    <input type="hidden" name="action" value="share_from_my_storage">
                    <div style="display: grid; grid-template-columns: 2fr 1.3fr auto; gap: 10px; align-items: end;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">Chọn bài viết từ Kho cá nhân:</label>
                            <?php if (empty($myPersonalPages)): ?>
                                <select class="form-select" disabled>
                                    <option>Kho của bạn chưa có bài viết nào</option>
                                </select>
                            <?php else: ?>
                                <select name="source_page_id" class="form-select" required>
                                    <option value="">-- Chọn bài viết muốn đưa vào nhóm --</option>
                                    <?php 
                                    $currLib = null;
                                    foreach ($myPersonalPages as $mp): 
                                        if ($mp['lib_name'] !== $currLib):
                                            if ($currLib !== null) echo '</optgroup>';
                                            $currLib = $mp['lib_name'];
                                            echo '<optgroup label="📁 ' . htmlspecialchars($currLib) . '">';
                                        endif;
                                    ?>
                                        <option value="<?= $mp['id'] ?>">
                                            <?= htmlspecialchars(mb_strimwidth($mp['title'] ?: $mp['url'], 0, 70, '…')) ?>
                                        </option>
                                    <?php endforeach; if ($currLib !== null) echo '</optgroup>'; ?>
                                </select>
                            <?php endif; ?>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">Lưu vào thư viện nhóm:</label>
                            <select name="library_id" class="form-select" required>
                                <?php foreach ($libraries as $l): ?>
                                    <option value="<?= $l['id'] ?>" <?= $selectedLibId === (int)$l['id'] ? 'selected' : '' ?>>
                                        📁 <?= htmlspecialchars($l['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <button type="submit" class="btn btn-primary" style="height: 38px;" <?= empty($myPersonalPages) ? 'disabled' : '' ?>>
                                Đưa vào nhóm &rarr;
                            </button>
                        </div>
                    </div>

                    <!-- PHÂN QUYỀN TÙY CHỌN KHI TẢI LÊN (EduWiki STYLE) -->
                    <?php if (count($members) > 1): ?>
                        <details style="margin-top: 12px; background: var(--bg-color); border: 1px dashed var(--border-highlight); border-radius: 8px; padding: 10px 14px;">
                            <summary style="font-size: 13px; font-weight: 700; color: var(--primary-dark); cursor: pointer;">
                                🛡️ Phân quyền tài liệu cho từng thành viên trong nhóm (Tùy chọn)
                            </summary>
                            <p style="font-size: 12px; color: var(--text-muted); margin: 6px 0 10px;">
                                Mặc định: Các thành viên đều được xem và tải về; chỉ bạn mới có quyền chỉnh sửa. Bạn có thể tùy chỉnh lại ở dưới:
                            </p>
                            <table style="width: 100%; font-size: 12.5px; border-collapse: collapse;">
                                <thead>
                                    <tr style="border-bottom: 1px solid var(--border-color); text-align: left;">
                                        <th style="padding: 4px 6px;">Thành viên</th>
                                        <th style="padding: 4px 6px; text-align: center;">👁️ Được xem</th>
                                        <th style="padding: 4px 6px; text-align: center;">✏️ Chỉnh sửa</th>
                                        <th style="padding: 4px 6px; text-align: center;">📥 Tải về</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($members as $m): ?>
                                        <?php if ((int)$m['user_id'] === $userId) continue; ?>
                                        <tr style="border-bottom: 1px dashed var(--border-color);">
                                            <td style="padding: 6px;">
                                                <strong><?= htmlspecialchars($m['fullname'] ?: $m['username']) ?></strong>
                                                <span style="font-size: 11px; color: var(--text-muted);">(<?= $m['role'] ?>)</span>
                                            </td>
                                            <td style="padding: 6px; text-align: center;">
                                                <input type="checkbox" name="perms[<?= $m['user_id'] ?>][can_view]" value="1" checked>
                                            </td>
                                            <td style="padding: 6px; text-align: center;">
                                                <input type="checkbox" name="perms[<?= $m['user_id'] ?>][can_edit]" value="1">
                                            </td>
                                            <td style="padding: 6px; text-align: center;">
                                                <input type="checkbox" name="perms[<?= $m['user_id'] ?>][can_download]" value="1" checked>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </details>
                    <?php endif; ?>
                </form>

                <!-- CHẾ ĐỘ 2: NHẬP LINK URL TRỰC TIẾP (ẨN MẶC ĐỊNH) -->
                <form method="POST" action="" id="form-upload-url" style="display: none;">
                    <input type="hidden" name="action" value="save_page_to_group">
                    <div style="display: grid; grid-template-columns: 2fr 1.3fr auto; gap: 10px; align-items: end;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">URL trang web:</label>
                            <input type="url" name="url" class="form-control" placeholder="https://..." required>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">Chọn thư viện trong nhóm:</label>
                            <select name="library_id" class="form-select" required>
                                <?php foreach ($libraries as $l): ?>
                                    <option value="<?= $l['id'] ?>" <?= $selectedLibId === (int)$l['id'] ? 'selected' : '' ?>>
                                        📁 <?= htmlspecialchars($l['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <button type="submit" class="btn btn-primary" style="height: 38px;">
                                Lưu vào nhóm &rarr;
                            </button>
                        </div>
                    </div>

                    <!-- PHÂN QUYỀN TÙY CHỌN KHI LƯU URL -->
                    <?php if (count($members) > 1): ?>
                        <details style="margin-top: 12px; background: var(--bg-color); border: 1px dashed var(--border-highlight); border-radius: 8px; padding: 10px 14px;">
                            <summary style="font-size: 13px; font-weight: 700; color: var(--primary-dark); cursor: pointer;">
                                🛡️ Phân quyền tài liệu cho từng thành viên trong nhóm (Tùy chọn)
                            </summary>
                            <table style="width: 100%; font-size: 12.5px; border-collapse: collapse; margin-top: 6px;">
                                <thead>
                                    <tr style="border-bottom: 1px solid var(--border-color); text-align: left;">
                                        <th style="padding: 4px 6px;">Thành viên</th>
                                        <th style="padding: 4px 6px; text-align: center;">👁️ Được xem</th>
                                        <th style="padding: 4px 6px; text-align: center;">✏️ Chỉnh sửa</th>
                                        <th style="padding: 4px 6px; text-align: center;">📥 Tải về</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($members as $m): ?>
                                        <?php if ((int)$m['user_id'] === $userId) continue; ?>
                                        <tr style="border-bottom: 1px dashed var(--border-color);">
                                            <td style="padding: 6px;">
                                                <strong><?= htmlspecialchars($m['fullname'] ?: $m['username']) ?></strong>
                                                <span style="font-size: 11px; color: var(--text-muted);">(<?= $m['role'] ?>)</span>
                                            </td>
                                            <td style="padding: 6px; text-align: center;">
                                                <input type="checkbox" name="perms[<?= $m['user_id'] ?>][can_view]" value="1" checked>
                                            </td>
                                            <td style="padding: 6px; text-align: center;">
                                                <input type="checkbox" name="perms[<?= $m['user_id'] ?>][can_edit]" value="1">
                                            </td>
                                            <td style="padding: 6px; text-align: center;">
                                                <input type="checkbox" name="perms[<?= $m['user_id'] ?>][can_download]" value="1" checked>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </details>
                    <?php endif; ?>
                </form>
            </div>

            <script>
            function switchUploadMode(mode) {
                const formStorage = document.getElementById('form-upload-storage');
                const formUrl = document.getElementById('form-upload-url');
                const btnStorage = document.getElementById('btn-tab-mystorage');
                const btnUrl = document.getElementById('btn-tab-url');

                if (mode === 'storage') {
                    formStorage.style.display = 'block';
                    formUrl.style.display = 'none';
                    btnStorage.classList.add('active');
                    btnUrl.classList.remove('active');
                } else {
                    formStorage.style.display = 'none';
                    formUrl.style.display = 'block';
                    btnUrl.classList.add('active');
                    btnStorage.classList.remove('active');
                }
            }
            </script>
        <?php else: ?>
            <!-- THÔNG BÁO DÀNH CHO NGƯỜI XEM (VIEWER) -->
            <div class="card" style="border-left: 4px solid #1a73e8; margin-bottom: 20px; background: #f8fafc; padding: 18px 20px;">
                <div style="display: flex; align-items: center; gap: 14px;">
                    <div style="font-size: 30px;">👁️</div>
                    <div>
                        <h4 style="margin: 0 0 4px; font-size: 15px; color: #1e293b; font-weight: 700;">
                            Quyền hạn của bạn: Người xem (Viewer)
                        </h4>
                        <p style="margin: 0; font-size: 13.5px; color: #64748b; line-height: 1.5;">
                            Bạn có thể xem các bài viết trong nhóm và tải về những tài liệu được cho phép. Quyền thêm bài viết và quản lý thuộc về <strong>Người chỉnh sửa</strong> và <strong>Quản trị viên</strong>.
                        </p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- DANH SÁCH THƯ VIỆN TRONG NHÓM -->
        <div class="card" style="margin-bottom: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
                <h3 class="card-title" style="margin: 0;">
                    <span>📂 Các Thư viện trong nhóm (<?= count($libraries) ?>)</span>
                </h3>

                <?php if ($isEditor): ?>
                    <!-- Nút / Form tạo thư viện nhóm mới -->
                    <details style="position: relative;">
                        <summary class="btn btn-outline btn-sm" style="cursor: pointer;">
                            + Tạo thư viện mới
                        </summary>
                        <div style="position: absolute; right: 0; top: 32px; width: 300px; background: #fff; border: 1px solid #dadce0; border-radius: 8px; padding: 14px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 100;">
                            <form method="POST" action="">
                                <input type="hidden" name="action" value="create_group_lib">
                                <div class="form-group">
                                    <label class="form-label">Tên thư viện mới:</label>
                                    <input type="text" name="lib_name" class="form-control" placeholder="Nhập tên thư viện..." required>
                                </div>
                                <div style="margin-bottom: 12px;">
                                    <label style="font-size: 13px; display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                        <input type="checkbox" name="allow_download" value="1" checked>
                                        <span>Cho phép thành viên tải tài liệu</span>
                                    </label>
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm" style="width: 100%;">Tạo thư viện</button>
                            </form>
                        </div>
                    </details>
                <?php endif; ?>
            </div>

            <!-- HIỂN THỊ CÁC THƯ VIỆN DẠNG LƯỚI THẺ (GRID CARDS) RÕ RÀNG -->
            <?php if (empty($libraries)): ?>
                <div style="text-align: center; padding: 36px 16px; color: var(--text-muted); background: #f8fafc; border-radius: 8px;">
                    <div style="font-size: 36px; margin-bottom: 8px;">📂</div>
                    <p style="margin: 0 0 6px 0; font-size: 14px; font-weight: 600; color: #334155;">Nhóm hiện chưa có thư viện nào.</p>
                    <p style="margin: 0; font-size: 12.5px;">Bấm <strong>"+ Tạo thư viện mới"</strong> ở góc phải trên để bắt đầu tạo thư viện nhóm!</p>
                </div>
            <?php else: ?>
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 14px;">
                    <?php foreach ($pagedLibraries as $l): ?>
                        <?php $isActive = ($selectedLibId > 0 && (int)$l['id'] === $selectedLibId); ?>
                        <div style="border: <?= $isActive ? '2px solid #1a73e8' : '1px solid #e2e8f0' ?>; border-radius: 8px; padding: 16px; background: <?= $isActive ? '#f0f7ff' : '#ffffff' ?>; box-shadow: 0 1px 3px rgba(0,0,0,0.05); display: flex; flex-direction: column; justify-content: space-between; gap: 12px; transition: all 0.2s ease;">
                            <div>
                                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; margin-bottom: 8px;">
                                    <h4 style="margin: 0; font-size: 15.5px; font-weight: 700;">
                                        <a href="/group_detail.php?id=<?= $groupId ?>&lib=<?= $l['id'] ?>#groupArticlesSection" style="color: <?= $isActive ? '#1a73e8' : '#1e293b' ?>; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                                            <span>📁</span> <?= htmlspecialchars($l['name']) ?>
                                        </a>
                                    </h4>
                                    <span class="badge" style="background: <?= $isActive ? '#1a73e8' : '#e8f0fe' ?>; color: <?= $isActive ? '#ffffff' : '#1a73e8' ?>; font-weight: 700; font-size: 11.5px; padding: 2px 8px; border-radius: 12px; white-space: nowrap;">
                                        <?= (int)$l['page_count'] ?> bài
                                    </span>
                                </div>
                                <div style="font-size: 12px; color: #64748b; display: flex; flex-direction: column; gap: 4px;">
                                    <span>📅 Ngày tạo: <?= date('d/m/Y', strtotime($l['created_at'])) ?></span>
                                    <span>
                                        <?= !empty($l['allow_download']) ? '<span style="color: #166534; font-weight: 500;">✓ Cho phép tải về</span>' : '<span style="color: #b91c1c; font-weight: 500;">🔒 Khóa tải về</span>' ?>
                                    </span>
                                </div>
                            </div>

                            <div style="border-top: 1px solid <?= $isActive ? '#bfdbfe' : '#f1f5f9' ?>; padding-top: 10px;">
                                <?php if ($isActive): ?>
                                    <div style="display: flex; gap: 6px;">
                                        <a href="#groupArticlesSection" class="btn btn-wiki btn-sm" style="flex: 1; text-align: center; justify-content: center; font-size: 12px; font-weight: 600; height: 32px;">
                                            📖 Đang mở xem (<?= (int)$l['page_count'] ?>)
                                        </a>
                                        <a href="/group_detail.php?id=<?= $groupId ?>" class="btn btn-outline btn-sm" style="padding: 0 10px; font-size: 12px; height: 32px; background: #fff;" title="Đóng xem bài viết">
                                            ✕
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <a href="/group_detail.php?id=<?= $groupId ?>&lib=<?= $l['id'] ?>#groupArticlesSection" class="btn btn-outline btn-sm" style="width: 100%; text-align: center; justify-content: center; font-size: 12px; background: #ffffff; height: 32px;">
                                        📂 Mở xem bài viết &rarr;
                                    </a>
                                <?php endif; ?>

                                <!-- NÚT ĐỔI TÊN HOẶC XÓA THƯ VIỆN NHÓM (CHỈ DÀNH CHO NHÓM TRƯỞNG HOẶC NGƯỜI CHỈNH SỬA) -->
                                <?php if ($isEditor): ?>
                                    <div style="display: flex; gap: 4px; margin-top: 8px;">
                                        <button type="button" class="btn btn-outline btn-sm" style="flex: 1; font-size: 11.5px; padding: 4px 6px; background: #fff;" onclick="promptRenameGroupLib(<?= $l['id'] ?>, '<?= htmlspecialchars(addslashes($l['name'])) ?>')">
                                            ✏️ Đổi tên
                                        </button>
                                        <form method="POST" action="" onsubmit="return eduConfirmSubmit(this, event, 'Bạn có chắc chắn muốn xóa thư viện &quot;<strong><?= htmlspecialchars(addslashes($l['name'])) ?></strong>&quot; cùng toàn bộ bài viết bên trong?<br><small style=\'color:#b91c1c;\'>Thao tác này sẽ xóa vĩnh viễn và không thể hoàn tác.</small>', 'Xác Nhận Xóa Thư Viện', 'Xác nhận xóa');" style="margin: 0;">
                                            <input type="hidden" name="action" value="delete_group_lib">
                                            <input type="hidden" name="library_id" value="<?= $l['id'] ?>">
                                            <button type="submit" class="btn btn-outline btn-sm" style="color: #dc2626; border-color: #fecaca; background: #fef2f2; font-size: 11.5px; padding: 4px 8px;" title="Xóa thư viện này">
                                                🗑️
                                            </button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- PHÂN TRANG THƯ VIỆN NHÓM KHI CÓ TRÊN 4 THƯ VIỆN -->
                <?php if ($totalLibPages > 1): ?>
                    <?php
                    $buildLibPageUrl = function(int $p) use ($groupId, $selectedLibId): string {
                        $params = ['id' => $groupId, 'lib_p' => $p];
                        if ($selectedLibId > 0) $params['lib'] = $selectedLibId;
                        return '/group_detail.php?' . http_build_query($params);
                    };
                    ?>
                    <nav class="edu-pagination" aria-label="Phân trang thư viện nhóm" style="margin-top: 14px; margin-bottom: 0;">
                        <?php if ($currentLibPage > 1): ?>
                            <a href="<?= htmlspecialchars($buildLibPageUrl($currentLibPage - 1)) ?>" class="edu-pagination-item">
                                &larr; Trang trước
                            </a>
                        <?php else: ?>
                            <span class="edu-pagination-item disabled">&larr; Trang trước</span>
                        <?php endif; ?>

                        <?php for ($p = 1; $p <= $totalLibPages; $p++): ?>
                            <?php if ($p === $currentLibPage): ?>
                                <span class="edu-pagination-item active"><?= $p ?></span>
                            <?php else: ?>
                                <a href="<?= htmlspecialchars($buildLibPageUrl($p)) ?>" class="edu-pagination-item">
                                    <?= $p ?>
                                </a>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ($currentLibPage < $totalLibPages): ?>
                            <a href="<?= htmlspecialchars($buildLibPageUrl($currentLibPage + 1)) ?>" class="edu-pagination-item">
                                Trang sau &rarr;
                            </a>
                        <?php else: ?>
                            <span class="edu-pagination-item disabled">Trang sau &rarr;</span>
                        <?php endif; ?>

                        <span class="edu-pagination-info">
                            Trang <?= $currentLibPage ?>/<?= $totalLibPages ?> (Tổng <?= $totalLibs ?> thư viện)
                        </span>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- KHU VỰC BÀI VIẾT ĐÃ ĐĂNG: CHỈ HIỂN THỊ KHI BẤM VÀO THƯ VIỆN -->
        <?php if ($selectedLibId > 0 && $selectedLib !== null): ?>
            <div class="card" id="groupArticlesSection" style="margin-bottom: 20px; border-top: 3px solid #1a73e8;">
                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--wiki-border-light); padding-bottom: 12px; margin-bottom: 16px; flex-wrap: wrap; gap: 10px;">
                    <div>
                        <h3 style="margin: 0; font-size: 17px; color: var(--wiki-blue); display: flex; align-items: center; gap: 8px;">
                            <span>📖</span> Bài viết trong thư viện "<?= htmlspecialchars($selectedLib['name']) ?>"
                            <span style="font-size: 13px; font-weight: normal; color: var(--text-muted);">
                                (<?= $totalGroupPages ?> bài viết)
                            </span>
                        </h3>
                    </div>
                    <a href="/group_detail.php?id=<?= $groupId ?>" class="btn btn-outline btn-sm" style="font-size: 12px;">
                        ✕ Thu gọn bài viết
                    </a>
                </div>

            <!-- THANH TÌM KIẾM TRONG THƯ VIỆN NHÓM -->
            <form method="GET" action="" style="margin-bottom: 14px;">
                <input type="hidden" name="id" value="<?= $groupId ?>">
                <input type="hidden" name="lib" value="<?= $selectedLibId ?>">
                <div style="display: flex; gap: 8px; align-items: center;">
                    <div style="position: relative; flex: 1;">
                        <span style="position: absolute; left: 11px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 15px;">🔍</span>
                        <input type="text" name="q" value="<?= htmlspecialchars($searchQ) ?>"
                               class="form-control"
                               placeholder="Tìm kiếm bài viết trong thư viện này..."
                               style="padding-left: 36px;">
                    </div>
                    <button type="submit" class="btn btn-primary" style="white-space: nowrap;">Tìm kiếm</button>
                    <?php if ($searchQ !== ''): ?>
                        <a href="/group_detail.php?id=<?= $groupId ?>&lib=<?= $selectedLibId ?>"
                           class="btn btn-outline" style="white-space: nowrap;">✕ Xóa</a>
                    <?php endif; ?>
                </div>
            </form>

            <!-- DANH SÁCH BÀI VIẾT CỦA THƯ VIỆN ĐANG CHỌN -->
            <?php if ($searchQ !== ''): ?>
                <div style="margin-bottom: 12px; font-size: 13.5px; color: var(--text-muted);">
                    <?php if (empty($pagesInLib)): ?>
                        Không tìm thấy bài viết nào khớp với "<strong><?= htmlspecialchars($searchQ) ?></strong>".
                    <?php else: ?>
                        Tìm thấy <strong><?= count($pagesInLib) ?></strong> bài viết khớp với "<strong><?= htmlspecialchars($searchQ) ?></strong>".
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (empty($pagesInLib)): ?>
                <div style="text-align: center; padding: 40px 20px; color: #80868b;">
                    <p style="font-size: 15px; margin-bottom: 6px;">Thư viện này hiện chưa có bài viết nào.</p>
                    <p style="font-size: 13px;">Hãy chọn bài từ <strong>Kho của tôi</strong> hoặc dán liên kết vào form ở trên để đưa tài liệu vào nhóm!</p>
                </div>
            <?php else: ?>
                <div style="display: flex; flex-direction: column; gap: 12px;">
                    <?php foreach ($pagedPagesInLib as $p): ?>
                        <?php 
                            $isAuthor = ((int)($p['author_id'] ?? 0) === $userId);
                            $canManageThisPage = ($isAuthor || $isGroupAdmin);
                            $pagePermissions = $canManageThisPage ? $groupRepo->getPagePermissions((int)$p['id']) : [];
                        ?>
                        <div class="article-card" style="margin-bottom: 0;">
                            <div style="display: flex; justify-content: space-between; align-items: start; gap: 12px;">
                                <div style="flex: 1;">
                                    <h4 style="margin: 0 0 4px; font-size: 15px;">
                                        <a href="/view.php?id=<?= $p['id'] ?>" style="color: #1a73e8; text-decoration: none;">
                                            <?= htmlspecialchars($p['title']) ?>
                                        </a>
                                    </h4>
                                    <div style="font-size: 12px; color: #5f6368; display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                                        <span>🔗 <?= htmlspecialchars(parse_url($p['url'], PHP_URL_HOST) ?: $p['url']) ?></span>
                                        <span>👤 Người đăng: <strong><?= htmlspecialchars($p['author_name'] ?: 'Thành viên') ?></strong> <?= $isAuthor ? '(Bạn)' : '' ?></span>
                                        <span>📅 <?= date('d/m/Y H:i', strtotime($p['created_at'])) ?></span>
                                        <span class="badge badge-version">v<?= (int)$p['version_number'] ?></span>
                                    </div>
                                </div>
                                <div style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap;">
                                    <a href="/view.php?id=<?= $p['id'] ?>" class="btn btn-outline btn-sm">
                                        📖 Đọc bài
                                    </a>
                                    <?php if (!empty($p['can_download'])): ?>
                                        <div style="display: inline-flex; align-items: center; border-radius: var(--radius-sm); overflow: hidden; border: 1px solid var(--wiki-border-light);">
                                            <a href="/view.php?id=<?= $p['id'] ?>&action=export&format=html" class="btn btn-sm" style="border: none; padding: 3px 7px; font-size: 11px; background: #fff;" title="Tải HTML">
                                                📥 HTML
                                            </a>
                                            <a href="/view.php?id=<?= $p['id'] ?>&action=export&format=pdf" class="btn btn-sm" style="border: none; border-left: 1px solid var(--wiki-border-light); padding: 3px 7px; font-size: 11px; background: #fff; color: #dc2626;" title="Tải PDF">
                                                📄 PDF
                                            </a>
                                            <a href="/view.php?id=<?= $p['id'] ?>&action=export&format=docx" class="btn btn-sm" style="border: none; border-left: 1px solid var(--wiki-border-light); padding: 3px 7px; font-size: 11px; background: #fff; color: #2563eb;" title="Tải Word (.docx)">
                                                📝 DOCX
                                            </a>
                                        </div>
                                    <?php else: ?>
                                        <span style="font-size: 11px; color: #80868b; padding: 4px 8px; background: #f1f3f4; border-radius: 4px;" title="Tài liệu bị khóa quyền tải về">
                                            🔒 Khóa tải
                                        </span>
                                    <?php endif; ?>

                                    <!-- NÚT XÓA BÀI VIẾT (CHỦ NHÓM HOẶC TÁC GIẢ BÀI VIẾT) -->
                                    <?php if ($canManageThisPage): ?>
                                        <form method="POST" action="" onsubmit="return eduConfirmSubmit(this, event, 'Bạn có chắc muốn xóa bài viết này khỏi thư viện nhóm?<br><small style=\'color:#b91c1c;\'>Thao tác không thể hoàn tác.</small>', 'Xóa Bài Viết Khỏi Nhóm', 'Xác nhận xóa');" style="margin: 0;">
                                            <input type="hidden" name="action" value="delete_group_page">
                                            <input type="hidden" name="page_id" value="<?= $p['id'] ?>">
                                            <button type="submit" class="btn btn-outline btn-sm" style="color: #dc2626; border-color: #fecaca; background: #fef2f2; padding: 4px 8px;" title="Xóa bài viết khỏi nhóm">
                                                🗑️ Xóa
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- BẢNG PHÂN QUYỀN RIÊNG CHO BÀI VIẾT NÀY (DÀNH CHO TÁC GIẢ BÀI VIẾT HOẶC CHỦ NHÓM) -->
                            <?php if ($canManageThisPage && count($members) > 1): ?>
                                <details style="margin-top: 10px; padding-top: 8px; border-top: 1px dashed var(--border-color);">
                                    <summary style="font-size: 12px; color: var(--primary-dark); cursor: pointer; font-weight: 600;">
                                        ⚙️ Phân quyền tài liệu này cho các thành viên trong nhóm...
                                    </summary>
                                    <form method="POST" action="" style="margin-top: 8px; background: var(--bg-color); padding: 10px; border-radius: 6px;">
                                        <input type="hidden" name="action" value="update_page_perms">
                                        <input type="hidden" name="page_id" value="<?= $p['id'] ?>">
                                        <table style="width: 100%; font-size: 12px; border-collapse: collapse;">
                                            <thead>
                                                <tr style="border-bottom: 1px solid var(--border-color); text-align: left;">
                                                    <th style="padding: 4px;">Thành viên</th>
                                                    <th style="padding: 4px; text-align: center;">👁️ Xem</th>
                                                    <th style="padding: 4px; text-align: center;">✏️ Sửa</th>
                                                    <th style="padding: 4px; text-align: center;">📥 Tải</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($members as $m): ?>
                                                    <?php 
                                                        if ((int)$m['user_id'] === (int)($p['author_id'] ?? 0)) continue; 
                                                        $memId = (int)$m['user_id'];
                                                        // Lấy quyền hiện có của thành viên trên file này (nếu chưa set thì lấy theo mặc định nhóm)
                                                        $customP = $pagePermissions[$memId] ?? null;
                                                        $cView = $customP ? $customP['can_view'] : true;
                                                        $cEdit = $customP ? $customP['can_edit'] : in_array($m['role'], ['owner', 'admin', 'editor'], true);
                                                        $cDl = $customP ? $customP['can_download'] : (bool)$m['can_download'];
                                                    ?>
                                                    <tr style="border-bottom: 1px dashed var(--border-color);">
                                                        <td style="padding: 4px;">
                                                            <?= htmlspecialchars($m['fullname'] ?: $m['username']) ?>
                                                            <span style="font-size: 10.5px; color: var(--text-muted);">(<?= $m['role'] ?>)</span>
                                                        </td>
                                                        <td style="padding: 4px; text-align: center;">
                                                            <input type="checkbox" name="perms[<?= $memId ?>][can_view]" value="1" <?= $cView ? 'checked' : '' ?>>
                                                        </td>
                                                        <td style="padding: 4px; text-align: center;">
                                                            <input type="checkbox" name="perms[<?= $memId ?>][can_edit]" value="1" <?= $cEdit ? 'checked' : '' ?>>
                                                        </td>
                                                        <td style="padding: 4px; text-align: center;">
                                                            <input type="checkbox" name="perms[<?= $memId ?>][can_download]" value="1" <?= $cDl ? 'checked' : '' ?>>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                        <div style="margin-top: 8px; text-align: right;">
                                            <button type="submit" class="btn btn-primary btn-sm" style="font-size: 11px; padding: 3px 10px;">
                                                Lưu phân quyền tài liệu
                                            </button>
                                        </div>
                                    </form>
                                </details>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($totalPages > 1): ?>
                    <?php
                    $buildPageUrl = function(int $p) use ($groupId, $selectedLibId, $searchQ): string {
                        $params = ['id' => $groupId, 'lib' => $selectedLibId, 'page' => $p];
                        if ($searchQ !== '') $params['q'] = $searchQ;
                        return '/group_detail.php?' . http_build_query($params);
                    };
                    ?>
                    <nav class="edu-pagination" aria-label="Phân trang bài viết nhóm">
                        <?php if ($currentPage > 1): ?>
                            <a href="<?= htmlspecialchars($buildPageUrl($currentPage - 1)) ?>" class="edu-pagination-item">
                                &larr; Trang trước
                            </a>
                        <?php else: ?>
                            <span class="edu-pagination-item disabled">&larr; Trang trước</span>
                        <?php endif; ?>

                        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                            <?php if ($p === $currentPage): ?>
                                <span class="edu-pagination-item active"><?= $p ?></span>
                            <?php elseif ($p === 1 || $p === $totalPages || abs($p - $currentPage) <= 2): ?>
                                <a href="<?= htmlspecialchars($buildPageUrl($p)) ?>" class="edu-pagination-item">
                                    <?= $p ?>
                                </a>
                            <?php elseif ($p === 2 && $currentPage > 4): ?>
                                <span class="edu-pagination-item disabled">&hellip;</span>
                            <?php elseif ($p === $totalPages - 1 && $currentPage < $totalPages - 3): ?>
                                <span class="edu-pagination-item disabled">&hellip;</span>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ($currentPage < $totalPages): ?>
                            <a href="<?= htmlspecialchars($buildPageUrl($currentPage + 1)) ?>" class="edu-pagination-item">
                                Trang sau &rarr;
                            </a>
                        <?php else: ?>
                            <span class="edu-pagination-item disabled">Trang sau &rarr;</span>
                        <?php endif; ?>

                        <span class="edu-pagination-info">
                            Hiển thị <?= min($totalGroupPages, $offset + 1) ?>-<?= min($totalGroupPages, $offset + count($pagedPagesInLib)) ?> trong <?= $totalGroupPages ?> bài
                        </span>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php else: ?>
            <!-- HƯỚNG DẪN KHI CHƯA BẤM VÀO THƯ VIỆN NÀO -->
            <div style="text-align: center; padding: 24px 16px; color: var(--text-muted); background: #f8fafc; border-radius: 8px; border: 1px dashed #cbd5e1; margin-bottom: 20px;">
                <span style="font-size: 26px;">👆</span>
                <p style="margin: 8px 0 0 0; font-size: 13.5px; font-weight: 500; color: #475569;">
                    Bấm vào nút <strong>"📂 Mở xem bài viết &rarr;"</strong> ở một thư viện phía trên để xem các tài liệu đã đăng bên trong.
                </p>
            </div>
        <?php endif; ?>
    </div>

    <!-- CỘT PHẢI: QUẢN LÝ THÀNH VIÊN & PHÂN QUYỀN Wiki Archiver -->
    <div>
        <!-- FORM THÊM BẠN BÈ VÀO NHÓM (QUẢN TRỊ VIÊN, CHỦ NHÓM & EDITOR) -->
        <?php if ($isEditor): ?>
            <div class="card" style="border-top: 4px solid #1a73e8; margin-bottom: 20px;">
                <h3 class="card-title" style="margin-bottom: 12px; font-size: 15px;">
                    <span>➕ Thêm bạn bè vào nhóm</span>
                </h3>
                <?php if (empty($friendsToInvite)): ?>
                    <p style="font-size: 12px; color: #5f6368; margin-bottom: 8px;">
                        Tất cả bạn bè của bạn đã có trong nhóm hoặc bạn chưa có bạn bè mới.
                    </p>
                    <a href="/friends.php" class="btn btn-outline btn-sm" style="width: 100%;">
                        🔍 Tìm thêm bạn bè &rarr;
                    </a>
                <?php else: ?>
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="add_member">
                        <div class="form-group">
                            <label class="form-label">Chọn người bạn:</label>
                            <select name="friend_id" class="form-select" required>
                                <option value="">-- Chọn từ danh sách bạn bè --</option>
                                <?php foreach ($friendsToInvite as $f): ?>
                                    <option value="<?= $f['id'] ?>">
                                        <?= htmlspecialchars($f['fullname'] ?: $f['username']) ?> (@<?= htmlspecialchars($f['username']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Quyền trong nhóm (Vai trò):</label>
                            <select name="role" class="form-select">
                                <option value="viewer">👁️ Người xem (Viewer) - Chỉ xem & tải file cho phép</option>
                                <option value="editor">✏️ Người chỉnh sửa (Editor) - Thêm & sửa bài viết</option>
                                <?php if ($isOwner): ?>
                                    <option value="admin">⭐ Quản trị nhóm (Admin) - Toàn quyền quản trị</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div style="margin-bottom: 14px;">
                            <label style="font-size: 13px; display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                <input type="checkbox" name="can_download" value="1" checked>
                                <span><strong>Cho phép tải về</strong> (Export HTML / PDF / Word)</span>
                            </label>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm" style="width: 100%;">
                            Thêm vào nhóm
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- DANH SÁCH THÀNH VIÊN VÀ PHÂN QUYỀN HIỆN CÓ -->
        <div class="card">
            <h3 class="card-title" style="margin-bottom: 14px; font-size: 15px;">
                <span>👥 Thành viên nhóm (<?= count($members) ?>)</span>
            </h3>

            <div style="display: flex; flex-direction: column; gap: 12px;">
                <?php foreach ($members as $m): ?>
                    <?php 
                        $isTargetOwner = ($m['role'] === 'owner');
                        $isTargetAdmin = ($m['role'] === 'admin');
                        $isSelf = ((int)$m['user_id'] === $userId);

                        $canManageThisMember = false;
                        if (!$isSelf) {
                            if ($isOwner) {
                                $canManageThisMember = true;
                            } elseif ($isGroupAdmin && !$isTargetOwner && !$isTargetAdmin) {
                                $canManageThisMember = true;
                            }
                        }

                        $roleBadge = match($m['role']) {
                            'owner' => '<span class="badge badge-public" style="font-size: 11px;">👑 Chủ nhóm</span>',
                            'admin' => '<span class="badge" style="font-size: 11px; background: #e8f0fe; color: #1967d2; border: 1px solid #c2e7ff;">⭐ Quản trị</span>',
                            'editor' => '<span class="badge badge-version" style="font-size: 11px;">✏️ Chỉnh sửa</span>',
                            default => '<span class="badge" style="font-size: 11px; background: #f1f3f4; color: #5f6368;">👁️ Người xem</span>',
                        };
                    ?>
                    <div style="padding: 10px; border: 1px solid #e8eaed; border-radius: 6px; background: #fafafa;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                            <div>
                                <strong style="font-size: 13px; color: #202124;">
                                    <?= htmlspecialchars($m['fullname'] ?: $m['username']) ?>
                                    <?= $isSelf ? '<span style="color:#1a73e8; font-weight:normal;">(Bạn)</span>' : '' ?>
                                </strong>
                                <span style="font-size: 11px; color: #5f6368; display: block;">@<?= htmlspecialchars($m['username']) ?></span>
                            </div>
                            <?= $roleBadge ?>
                        </div>

                        <!-- Trạng thái quyền tải về -->
                        <div style="font-size: 11px; margin-bottom: 8px; color: <?= $m['can_download'] ? '#137333' : '#c5221f' ?>;">
                            <?= $m['can_download'] ? '✅ Được phép tải về' : '🔒 Đã tắt quyền tải về' ?>
                        </div>

                        <!-- Nút điều chỉnh phân quyền nếu là Quản trị viên hoặc Chủ nhóm -->
                        <?php if ($canManageThisMember): ?>
                            <details style="margin-top: 6px;">
                                <summary style="font-size: 12px; color: #1a73e8; cursor: pointer;">
                                    ⚙️ Thay đổi quyền...
                                </summary>
                                <div style="margin-top: 8px; padding-top: 8px; border-top: 1px dashed #dadce0;">
                                    <form method="POST" action="">
                                        <input type="hidden" name="action" value="update_member_perm">
                                        <input type="hidden" name="target_user_id" value="<?= $m['user_id'] ?>">
                                        
                                        <div style="margin-bottom: 6px;">
                                            <select name="role" class="form-select" style="font-size: 12px; padding: 4px 6px;">
                                                <option value="viewer" <?= $m['role'] === 'viewer' ? 'selected' : '' ?>>👁️ Người xem (Viewer)</option>
                                                <option value="editor" <?= $m['role'] === 'editor' ? 'selected' : '' ?>>✏️ Chỉnh sửa (Editor)</option>
                                                <?php if ($isOwner): ?>
                                                    <option value="admin" <?= $m['role'] === 'admin' ? 'selected' : '' ?>>⭐ Quản trị (Admin)</option>
                                                <?php endif; ?>
                                            </select>
                                        </div>
                                        <div style="margin-bottom: 8px;">
                                            <label style="font-size: 12px; display: flex; align-items: center; gap: 6px;">
                                                <input type="checkbox" name="can_download" value="1" <?= $m['can_download'] ? 'checked' : '' ?>>
                                                <span>Cho phép tải về</span>
                                            </label>
                                        </div>
                                        <div style="display: flex; gap: 6px;">
                                            <button type="submit" class="btn btn-primary btn-sm" style="flex: 1; padding: 2px 6px; font-size: 11px;">
                                                Lưu quyền
                                            </button>
                                        </div>
                                    </form>

                                    <!-- Nút đuổi khỏi nhóm -->
                                    <form method="POST" action="" onsubmit="return eduConfirmSubmit(this, event, 'Xóa thành viên này khỏi nhóm?<br><small style=\'color:#b91c1c;\'>Toàn bộ bài viết do thành viên này đăng trong nhóm sẽ bị xóa vĩnh viễn.</small>', 'Xóa Thành Viên Khỏi Nhóm', 'Xác nhận xóa');" style="margin-top: 6px;">
                                        <input type="hidden" name="action" value="remove_member">
                                        <input type="hidden" name="target_user_id" value="<?= $m['user_id'] ?>">
                                        <button type="submit" class="btn btn-outline btn-sm" style="width: 100%; color: #d93025; padding: 2px 6px; font-size: 11px;">
                                            🚫 Xóa khỏi nhóm (Xóa toàn bộ bài)
                                        </button>
                                    </form>
                                </div>
                            </details>
                        <?php endif; ?>

                        <!-- Nút tự rời nhóm (nếu không phải Chủ nhóm) -->
                        <?php if ($isSelf && !$isTargetOwner): ?>
                            <form method="POST" action="" onsubmit="return eduConfirmSubmit(this, event, 'Bạn có chắc chắn muốn rời khỏi hội nhóm này?', 'Rời Hội Nhóm', 'Xác nhận rời nhóm');" style="margin-top: 6px;">
                                <input type="hidden" name="action" value="remove_member">
                                <input type="hidden" name="target_user_id" value="<?= $userId ?>">
                                <input type="hidden" name="leave_group" value="1">
                                <button type="submit" class="btn btn-outline btn-sm" style="width: 100%; color: #5f6368; padding: 2px 6px; font-size: 11px;">
                                    🚪 Rời khỏi nhóm
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- FORM ẨN ĐỔI TÊN THƯ VIỆN NHÓM -->
<form id="renameGroupLibForm" method="POST" action="" style="display: none;">
    <input type="hidden" name="action" value="rename_group_lib">
    <input type="hidden" name="library_id" id="renameGroupLibId" value="0">
    <input type="hidden" name="new_name" id="renameGroupNewName" value="">
</form>

<script>
function promptRenameGroupLib(libId, currentName) {
    eduPrompt({
        title: 'Đổi Tên Thư Viện Nhóm',
        label: 'Tên thư viện mới:',
        currentValue: currentName,
        onConfirm: function(newName) {
            if (newName && newName.trim() !== '' && newName.trim() !== currentName) {
                document.getElementById('renameGroupLibId').value = libId;
                document.getElementById('renameGroupNewName').value = newName.trim();
                document.getElementById('renameGroupLibForm').submit();
            }
        }
    });
}
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>

