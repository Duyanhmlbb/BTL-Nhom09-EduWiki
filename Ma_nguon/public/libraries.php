<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../src/ArchiveRepository.php';
require_once __DIR__ . '/../src/GroupRepository.php';
require_once __DIR__ . '/../src/WebFetcher.php';
require_once __DIR__ . '/../src/Auth.php';

// Bắt buộc đăng nhập
if (!Auth::check()) {
    header('Location: /login.php?redirect=' . urlencode('/libraries.php') . '&msg=' . urlencode('Vui lòng đăng nhập để truy cập Kho thư viện cá nhân của bạn!'));
    exit;
}

$currentUser = Auth::user();
$userId = (int)$currentUser['id'];

$repo = new ArchiveRepository(db(), $userId);
$groupRepo = new GroupRepository(db());
$userGroups = $groupRepo->getGroupsForUser($userId);

$message = isset($_GET['msg']) ? trim($_GET['msg']) : null;
$error = null;

// =========================================================================
// 1. XỬ LÝ POST: TẠO THƯ VIỆN MỚI
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_library') {
    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        $error = 'Vui lòng nhập tên thư viện cần tạo!';
    } else {
        try {
            $newId = $repo->createLibrary($name, 'private', $userId);
            header('Location: /libraries.php?lib_id=' . $newId . '&msg=' . urlencode("Đã tạo thư viện '{$name}' thành công!"));
            exit;
        } catch (Throwable $e) {
            $error = 'Lỗi tạo thư viện: ' . $e->getMessage();
        }
    }
}

// =========================================================================
// 2. XỬ LÝ POST: ĐỔI TÊN THƯ VIỆN
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'rename_library') {
    $libIdToRename = (int)($_POST['library_id'] ?? 0);
    $newName = trim($_POST['new_name'] ?? '');
    if ($libIdToRename > 0 && $newName !== '') {
        $stmtChk = db()->prepare("SELECT group_id FROM libraries WHERE id = ?");
        $stmtChk->execute([$libIdToRename]);
        $grpId = $stmtChk->fetchColumn();

        if ($grpId) {
            $ok = $groupRepo->renameGroupLibrary((int)$grpId, $libIdToRename, $newName, $userId);
        } else {
            $ok = $repo->renameLibrary($libIdToRename, $newName, $userId);
        }

        if ($ok) {
            $message = "Đã đổi tên thư viện thành '{$newName}' thành công!";
        } else {
            $error = 'Bạn không có quyền đổi tên thư viện này.';
        }
    }
}

// =========================================================================
// 3. XỬ LÝ POST: XÓA THƯ VIỆN
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_library') {
    $libIdToDelete = (int)($_POST['library_id'] ?? 0);
    if ($libIdToDelete > 0) {
        try {
            $stmtChk = db()->prepare("SELECT group_id FROM libraries WHERE id = ?");
            $stmtChk->execute([$libIdToDelete]);
            $grpId = $stmtChk->fetchColumn();

            if ($grpId) {
                $deleted = $groupRepo->deleteGroupLibrary((int)$grpId, $libIdToDelete, $userId);
            } else {
                $deleted = $repo->deleteLibrary($libIdToDelete, $userId);
            }

            if ($deleted) {
                header('Location: /libraries.php?msg=' . urlencode('Đã xóa thư viện và các bài viết bên trong thành công!'));
                exit;
            } else {
                $error = 'Bạn không có quyền xóa thư viện này!';
            }
        } catch (Throwable $e) {
            $error = 'Lỗi khi xóa thư viện: ' . $e->getMessage();
        }
    }
}

// =========================================================================
// 4. XỬ LÝ POST: LƯU BÀI VIẾT VÀO THƯ VIỆN
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_page') {
    $url = trim($_POST['url'] ?? '');
    $libId = (int)($_POST['library_id'] ?? 0);
    $newLibName = trim($_POST['new_lib_name'] ?? '');
    $tagsInput = trim($_POST['tags'] ?? '');
    $initialNote = trim($_POST['initial_note'] ?? '');
    $manualHtml = trim($_POST['manual_html'] ?? '');

    // Nếu người dùng chọn tạo nhanh thư viện mới
    if ($libId === -1 && $newLibName !== '') {
        $libId = $repo->createLibrary($newLibName, 'private', $userId);
    } elseif ($libId <= 0) {
        $libId = 1; // Mặc định đưa vào Thư viện chung
    }

    $htmlToSave = '';
    $finalUrl = $url;

    if ($manualHtml !== '') {
        $validation = WebFetcher::validateHtmlContent($manualHtml);
        if (!$validation['valid']) {
            $error = 'Không thể lưu: ' . $validation['message'];
        } else {
            $htmlToSave = $manualHtml;
            if ($url === '') {
                require_once __DIR__ . '/../src/HtmlCleaner.php';
                $extractedTitle = HtmlCleaner::extractTitle($manualHtml);
                $slug = preg_replace('/[^a-zA-Z0-9_\x{00C0}-\x{024F}\x{1E00}-\x{1EFF}-]/u', '-', $extractedTitle);
                $slug = trim(preg_replace('/-+/', '-', $slug), '-');
                if ($slug === '') $slug = 'tai-lieu-ngoai-tuyen-' . time();
                $finalUrl = 'offline://' . $slug;
            } else {
                $finalUrl = $url;
            }
        }
    } else {
        if ($url === '') {
            $error = 'Vui lòng nhập đường dẫn URL bài viết hoặc dán mã HTML!';
        } else {
            $fetchResult = WebFetcher::fetch($url);
            if (!$fetchResult['success']) {
                $error = 'Không thể nạp nội dung: ' . $fetchResult['error'];
            } else {
                $htmlToSave = $fetchResult['html'];
                $finalUrl = $fetchResult['final_url'];
            }
        }
    }

    if ($htmlToSave !== '' && $error === null) {
        try {
            $pageId = $repo->savePage($libId, $finalUrl, $htmlToSave, ['localize_images' => true], $userId);
            if ($tagsInput !== '') {
                $repo->addTagsFromString($pageId, $tagsInput);
            }
            if ($initialNote !== '') {
                $repo->addNote($pageId, $initialNote);
            }

            header('Location: /view.php?id=' . $pageId . '&msg=' . urlencode('Đã lưu bài viết vào thư viện thành công! Dưới đây là bản đọc offline của bạn.'));
            exit;
        } catch (Throwable $e) {
            $error = 'Lỗi lưu bài viết: ' . $e->getMessage();
        }
    }
}

// =========================================================================
// 5. XỬ LÝ POST: ĐƯA LÊN THƯ VIỆN CHUNG HOẶC RÚT VỀ
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'publish_public') {
    $pId = (int)($_POST['page_id'] ?? 0);
    if ($pId > 0 && $repo->publishToPublic($pId, $userId)) {
        $message = "Đã đưa bài viết lên Thư viện chung thành công!";
    } else {
        $error = "Không thể đưa bài viết lên Thư viện chung.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'unpublish_public') {
    $pId = (int)($_POST['page_id'] ?? 0);
    if ($pId > 0 && $repo->unpublishFromPublic($pId, $userId)) {
        $message = "Đã rút bài viết về Kho cá nhân!";
    } else {
        $error = "Không thể chuyển bài viết.";
    }
}

// Xử lý xóa bài
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_page') {
    $deleteId = (int)($_POST['page_id'] ?? 0);
    $userRole = $currentUser['role'] ?? 'member';
    if ($deleteId > 0 && $repo->deletePage($deleteId, $userId, $userRole)) {
        $message = "Đã xóa bài viết thành công!";
    } else {
        $error = "Bạn không có quyền xóa bài viết này.";
    }
}

// Lấy danh sách tất cả các thư viện cá nhân của tôi VÀ thư viện các hội nhóm tôi tham gia
$myLibraries = $repo->getUserAndGroupLibraries($userId);

// Phân trang danh sách thư viện ở Kho của tôi: tối đa 4 thư viện mỗi trang
$libPerPage = 4;
$totalLibs = count($myLibraries);
$totalLibPages = max(1, (int)ceil($totalLibs / $libPerPage));
$currentLibPage = max(1, min($totalLibPages, (int)($_GET['lib_p'] ?? 1)));
$libOffset = ($currentLibPage - 1) * $libPerPage;
$pagedLibraries = array_slice($myLibraries, $libOffset, $libPerPage);

// Thư viện đang chọn xem
$selectedLibId = isset($_GET['lib_id']) ? (int)$_GET['lib_id'] : 0;
$selectedLib = null;
if ($selectedLibId > 0) {
    foreach ($myLibraries as $l) {
        if ((int)$l['id'] === $selectedLibId) {
            $selectedLib = $l;
            break;
        }
    }
}

// Lấy danh sách bài viết theo thư viện đang chọn hoặc toàn bộ bài cá nhân
$articles = [];
if ($selectedLibId > 0) {
    $articles = $repo->searchPages($selectedLibId, null, null, null, null, $userId, 'all');
} else {
    // Tất cả bài cá nhân của tôi
    $stmtAll = db()->prepare("
        SELECT p.id, p.library_id, p.url, p.title, p.created_at, p.user_id,
               l.name AS library_name, l.access_level,
               s.id AS snapshot_id, s.version_number, s.content_text,
               (SELECT COUNT(*) FROM assets WHERE snapshot_id = s.id) AS asset_count
        FROM saved_pages p
        JOIN libraries l ON l.id = p.library_id
        JOIN snapshots s ON s.saved_page_id = p.id
             AND s.version_number = (SELECT MAX(version_number) FROM snapshots WHERE saved_page_id = p.id)
        WHERE p.user_id = ?
        ORDER BY p.id DESC
    ");
    $stmtAll->execute([$userId]);
    $articles = $stmtAll->fetchAll(PDO::FETCH_ASSOC);
    foreach ($articles as &$a) {
        $a['tags'] = $repo->getTagsForPage((int)$a['id']);
    }
}

// =========================================================================
// PHÂN TRANG: TỐI ĐA 5 BÀI VIẾT MỖI TRANG
// =========================================================================
$perPage = 5;
$totalArticles = count($articles);
$totalPages = max(1, (int)ceil($totalArticles / $perPage));
$currentPage = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));
$offset = ($currentPage - 1) * $perPage;
$pagedArticles = array_slice($articles, $offset, $perPage);

$pageTitle = 'Kho Của Tôi — Quản Lý Thư Viện Cá Nhân - Wiki Archiver';
require_once __DIR__ . '/../templates/header.php';
?>

<!-- TIÊU ĐỀ TRANG KHO CỦA TÔI -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-top: 18px; margin-bottom: 22px; flex-wrap: wrap; gap: 12px; border-bottom: 1px solid var(--wiki-border-light); padding-bottom: 14px;">
    <div>
        <h1 style="margin: 0; font-size: 24px; color: var(--wiki-blue); display: flex; align-items: center; gap: 8px;">
            <span>📁</span> Kho Thư Viện Của Tôi
        </h1>
        <p style="margin: 4px 0 0; color: var(--text-muted); font-size: 14px;">
            Quản lý và lưu trữ các bài viết, tư liệu của bạn.
        </p>
    </div>

    <div style="display: flex; gap: 8px;">
        <button type="button" class="btn btn-wiki btn-sm" onclick="document.getElementById('saveModal').style.display = 'flex';">
            ➕ Lưu bài viết mới
        </button>
        <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('newLibBox').scrollIntoView({behavior: 'smooth'}); document.getElementById('libNameInput').focus();">
            ➕ Thêm thư viện mới
        </button>
    </div>
</div>

<!-- TOAST THÔNG BÁO NỔI Ở GÓC MÀN HÌNH -->
<?php if ($message || $error): ?>
<div id="eduToastNotification" style="position: fixed; top: 24px; right: 24px; z-index: 99999; max-width: 420px; min-width: 320px; box-shadow: 0 8px 24px rgba(0,0,0,0.18); border-radius: 8px; overflow: hidden; pointer-events: auto;">
    <?php if ($error): ?>
        <div style="background: #ffffff; border: 1.5px solid #fad2cf; border-left: 5px solid #c5221f; padding: 14px 16px; font-size: 13.5px; display: flex; align-items: flex-start; gap: 10px;">
            <span style="font-size: 18px; line-height: 1;">⚠️</span>
            <div style="flex: 1; color: #202124;">
                <div style="font-weight: 700; color: #c5221f; margin-bottom: 3px;">Có lỗi xảy ra</div>
                <div><?= htmlspecialchars($error) ?></div>
            </div>
            <button type="button" onclick="document.getElementById('eduToastNotification').remove();" style="background: none; border: none; color: #5f6368; font-size: 16px; cursor: pointer; padding: 0 4px; line-height: 1;">✕</button>
        </div>
    <?php elseif ($message): ?>
        <div style="background: #ffffff; border: 1.5px solid #ceead6; border-left: 5px solid #137333; padding: 14px 16px; font-size: 13.5px; display: flex; align-items: flex-start; gap: 10px;">
            <span style="font-size: 18px; line-height: 1;">✅</span>
            <div style="flex: 1; color: #202124;">
                <div style="font-weight: 700; color: #137333; margin-bottom: 3px;">Thành công</div>
                <div><?= htmlspecialchars($message) ?></div>
            </div>
            <button type="button" onclick="document.getElementById('eduToastNotification').remove();" style="background: none; border: none; color: #5f6368; font-size: 16px; cursor: pointer; padding: 0 4px; line-height: 1;">✕</button>
        </div>
    <?php endif; ?>
</div>
<script>
    setTimeout(function() {
        var t = document.getElementById('eduToastNotification');
        if (t && <?= $message ? 'true' : 'false' ?>) {
            t.style.opacity = '0';
            t.style.transition = 'opacity 0.5s ease';
            setTimeout(function() { t.remove(); }, 500);
        }
    }, 8000);
</script>
<?php endif; ?>

<!-- THÔNG BÁO HỆ THỐNG NỀN TRANG (ẨN KHI ĐANG MỞ MODAL LƯU) -->
<?php 
$isSaveAction = (isset($_POST['action']) && $_POST['action'] === 'save_page');
?>
<?php if ($message && !$isSaveAction): ?>
    <div class="alert alert-success">
        <strong>✓ Thành công:</strong> <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<?php if ($error && !$isSaveAction): ?>
    <div class="alert alert-danger">
        <strong>✕ Lỗi:</strong> <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<!-- DANH SÁCH THƯ VIỆN CÁ NHÂN (GRID) -->
<div style="margin-bottom: 26px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
        <h3 style="margin: 0; font-size: 17px; color: var(--text-main);">
            📚 Các Thư Viện Của Bạn (<?= count($myLibraries) ?> thư viện)
        </h3>
        <a href="/libraries.php" class="btn btn-outline btn-sm" style="<?= $selectedLibId === 0 ? 'background: var(--wiki-blue-light); color: var(--wiki-blue); font-weight: 700;' : '' ?>">
            Xem tất cả bài viết (<?= count($articles) ?> bài)
        </a>
    </div>

    <?php if (empty($myLibraries)): ?>
        <div class="card" style="text-align: center; padding: 32px 20px; color: var(--text-muted);">
            <div style="font-size: 36px; margin-bottom: 8px;">📂</div>
            <p style="margin: 0 0 12px 0; font-size: 15px; font-weight: 600;">Bạn chưa có thư viện nào.</p>
            <p style="font-size: 13px; margin: 0 0 16px 0;">Hãy tạo thư viện đầu tiên ở khung bên dưới để bắt đầu lưu trữ.</p>
        </div>
    <?php else: ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px;">
            <?php foreach ($pagedLibraries as $lib): ?>
                <?php $isSelected = ($selectedLibId === (int)$lib['id']); ?>
                <div style="border: 1px solid <?= $isSelected ? 'var(--wiki-blue)' : 'var(--wiki-border-light)' ?>; border-radius: var(--radius-sm); padding: 16px; background: <?= $isSelected ? 'var(--wiki-blue-light)' : '#ffffff' ?>; box-shadow: var(--shadow-sm); display: flex; flex-direction: column; justify-content: space-between; gap: 12px;">
                    <div>
                        <!-- CHÚ THÍCH HỘI NHÓM HOẶC CÁ NHÂN -->
                        <?php if (!empty($lib['group_name'])): ?>
                            <div style="margin-bottom: 6px;">
                                <span class="badge" style="background: #eef2ff; color: #4338ca; font-weight: 600; font-size: 11px; border: 1px solid #c7d2fe; display: inline-flex; align-items: center; gap: 4px;">
                                    👥 Nhóm: <?= htmlspecialchars($lib['group_name']) ?>
                                </span>
                            </div>
                        <?php else: ?>
                            <div style="margin-bottom: 6px;">
                                <span class="badge" style="background: #f8fafc; color: #475569; font-weight: 600; font-size: 11px; border: 1px solid #e2e8f0; display: inline-flex; align-items: center; gap: 4px;">
                                    👤 Thư viện cá nhân
                                </span>
                            </div>
                        <?php endif; ?>

                        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                            <h4 style="margin: 0 0 6px 0; font-size: 16px; font-weight: 700;">
                                <a href="/libraries.php?lib_id=<?= $lib['id'] ?>" style="color: <?= $isSelected ? 'var(--wiki-blue)' : 'var(--text-main)' ?>; text-decoration: none;">
                                    <?= htmlspecialchars($lib['name']) ?>
                                </a>
                            </h4>
                            <span class="badge" style="background: #e8f0fe; color: #0f4c81; font-weight: 700;">
                                <?= (int)$lib['page_count'] ?> bài
                            </span>
                        </div>
                        <div style="font-size: 12px; color: var(--text-muted);">
                            Tạo ngày: <?= date('d/m/Y', strtotime($lib['created_at'])) ?>
                        </div>
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid var(--wiki-border-light); padding-top: 10px;">
                        <a href="/libraries.php?lib_id=<?= $lib['id'] ?>" class="btn btn-outline btn-sm" style="font-size: 12px;">
                            📂 Xem bài &rarr;
                        </a>

                        <?php 
                        $canManageThisLib = empty($lib['group_id']) || in_array($lib['my_group_role'] ?? '', ['owner', 'admin', 'editor'], true) || ($currentUser['role'] ?? '') === 'admin';
                        ?>
                        <?php if ($canManageThisLib): ?>
                            <div style="display: flex; gap: 4px;">
                                <button type="button" class="btn btn-outline btn-sm" style="font-size: 12px; padding: 4px 8px;" onclick="promptRename(<?= $lib['id'] ?>, '<?= htmlspecialchars(addslashes($lib['name'])) ?>')">
                                    ✏️ Đổi tên
                                </button>
                                <form method="POST" action="" onsubmit="return eduConfirmSubmit(this, event, 'Bạn có chắc chắn muốn xóa thư viện này?<br><small style=\'color:#b91c1c;\'>Toàn bộ bài viết bên trong sẽ bị xóa vĩnh viễn.</small>', 'Xóa Thư Viện', 'Xác nhận xóa');" style="margin: 0;">
                                    <input type="hidden" name="action" value="delete_library">
                                    <input type="hidden" name="library_id" value="<?= $lib['id'] ?>">
                                    <button type="submit" class="btn btn-outline btn-sm" style="color: var(--danger); font-size: 12px; padding: 4px 8px;">
                                        🗑️
                                    </button>
                                </form>
                            </div>
                        <?php else: ?>
                            <span style="font-size: 11.5px; color: var(--text-muted); font-style: italic;">(Chỉ xem)</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- PHÂN TRANG THƯ VIỆN KHI CÓ TRÊN 4 THƯ VIỆN -->
        <?php if ($totalLibPages > 1): ?>
            <?php
            $buildLibUrl = function(int $p) use ($selectedLibId): string {
                $params = ['lib_p' => $p];
                if ($selectedLibId > 0) $params['lib_id'] = $selectedLibId;
                return '/libraries.php?' . http_build_query($params);
            };
            ?>
            <nav class="edu-pagination" aria-label="Phân trang thư viện" style="margin-top: 14px; margin-bottom: 0;">
                <?php if ($currentLibPage > 1): ?>
                    <a href="<?= htmlspecialchars($buildLibUrl($currentLibPage - 1)) ?>" class="edu-pagination-item">
                        &larr; Trang trước
                    </a>
                <?php else: ?>
                    <span class="edu-pagination-item disabled">&larr; Trang trước</span>
                <?php endif; ?>

                <?php for ($p = 1; $p <= $totalLibPages; $p++): ?>
                    <?php if ($p === $currentLibPage): ?>
                        <span class="edu-pagination-item active"><?= $p ?></span>
                    <?php else: ?>
                        <a href="<?= htmlspecialchars($buildLibUrl($p)) ?>" class="edu-pagination-item">
                            <?= $p ?>
                        </a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($currentLibPage < $totalLibPages): ?>
                    <a href="<?= htmlspecialchars($buildLibUrl($currentLibPage + 1)) ?>" class="edu-pagination-item">
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

<!-- KHUNG THÊM THƯ VIỆN MỚI -->
<div class="card" id="newLibBox" style="margin-bottom: 26px; border-left: 4px solid var(--wiki-blue);">
    <div class="card-title">
        <span>➕ Thêm Thư Viện Mới</span>
    </div>
    <form method="POST" action="">
        <input type="hidden" name="action" value="create_library">
        <div style="display: grid; grid-template-columns: 1fr auto; gap: 12px; align-items: flex-end;">
            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label" style="font-weight: 600;">Tên thư viện mới:</label>
                <input type="text" name="name" id="libNameInput" class="form-control" 
                       placeholder="Nhập tên thư viện..." required>
            </div>
            <button type="submit" class="btn btn-wiki" style="height: 40px; padding: 0 20px;">
                + Tạo thư viện
            </button>
        </div>
    </form>
</div>

<!-- KHU VỰC DANH SÁCH BÀI VIẾT TRONG THƯ VIỆN ĐANG CHỌN -->
<div class="card" style="padding: 24px;">
    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--wiki-border-light); padding-bottom: 14px; margin-bottom: 18px; flex-wrap: wrap; gap: 10px;">
        <div>
            <h3 style="margin: 0; font-size: 18px; color: var(--text-main);">
                📖 Bài Viết Trong <?= $selectedLib ? 'Thư Viện "' . htmlspecialchars($selectedLib['name']) . '"' . (!empty($selectedLib['group_name']) ? ' <span style="font-size: 14px; color: #4338ca; font-weight: normal;">(👥 Nhóm: ' . htmlspecialchars($selectedLib['group_name']) . ')</span>' : '') : 'Tất Cả Thư Viện Của Tôi' ?>
                <span style="font-size: 13px; font-weight: normal; color: var(--text-muted); margin-left: 6px;">
                    (<?= $totalArticles ?> bài viết<?= $totalPages > 1 ? " &bull; Trang $currentPage/$totalPages" : '' ?>)
                </span>
            </h3>
        </div>

        <div style="display: flex; gap: 8px;">
            <?php if ($selectedLib && !empty($selectedLib['group_id'])): ?>
                <a href="/group_detail.php?id=<?= $selectedLib['group_id'] ?>&lib=<?= $selectedLib['id'] ?>" class="btn btn-outline btn-sm" style="font-size: 12px;">
                    👥 Mở trong Hội nhóm &rarr;
                </a>
            <?php endif; ?>
            <button type="button" class="btn btn-wiki btn-sm" onclick="openSaveModalWithLib(<?= $selectedLibId > 0 ? $selectedLibId : 0 ?>)">
                ➕ Lưu bài vào thư viện này
            </button>
        </div>
    </div>

    <?php if (empty($articles)): ?>
        <div style="text-align: center; padding: 48px 20px; color: var(--text-muted);">
            <div style="font-size: 48px; margin-bottom: 12px;">📭</div>
            <h4 style="font-size: 17px; margin: 0 0 8px 0; color: var(--text-main);">
                Chưa có bài viết nào trong thư viện này.
            </h4>
            <p style="font-size: 13.5px; margin: 0 0 16px 0;">
                Dán đường link bài viết bất kỳ bạn muốn lưu để bắt đầu lưu trữ vào thư viện!
            </p>
            <button type="button" class="btn btn-wiki btn-sm" onclick="openSaveModalWithLib(<?= $selectedLibId > 0 ? $selectedLibId : 0 ?>)">
                ➕ Lưu bài viết đầu tiên ngay
            </button>
        </div>
    <?php else: ?>
        <div style="display: flex; flex-direction: column; gap: 16px;">
            <?php foreach ($pagedArticles as $art): ?>
                <?php 
                $isPublic = ($art['access_level'] === 'public');
                ?>
                <div style="border: 1px solid var(--wiki-border-light); border-radius: var(--radius-sm); padding: 18px 20px; background: #ffffff; box-shadow: var(--shadow-sm);">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 14px; flex-wrap: wrap;">
                        <div style="flex: 1; min-width: 280px;">
                            <h3 style="margin: 0 0 8px 0; font-size: 18px;">
                                <a href="/view.php?id=<?= $art['id'] ?>" style="color: var(--link-color); text-decoration: none;">
                                    <?= htmlspecialchars($art['title']) ?>
                                </a>
                            </h3>

                            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 12.5px; color: var(--text-muted); margin-bottom: 8px;">
                                <span class="badge" style="background: #f1f3f5; color: #495057; font-weight: 700;">
                                    📁 <?= htmlspecialchars($art['library_name']) ?>
                                </span>

                                <?php if ($isPublic): ?>
                                    <span class="badge" style="background: #e8f0fe; color: #0f4c81; font-weight: 700;">
                                        🌐 Đang ở Thư viện chung
                                    </span>
                                <?php endif; ?>

                                <span class="badge" style="background: #e6f4ea; color: #137333;">
                                    🖼️ <?= (int)$art['asset_count'] ?> ảnh offline
                                </span>

                                <span>Lưu ngày: <?= date('d/m/Y H:i', strtotime($art['created_at'])) ?></span>
                            </div>

                            <div style="font-size: 12.5px; color: var(--text-muted); word-break: break-all; margin-bottom: 8px;">
                                <strong>Nguồn:</strong> 
                                <?php if (str_starts_with($art['url'], 'offline://') || str_starts_with($art['url'], 'manual://')): ?>
                                    <span class="badge badge-tag" style="font-weight: 500;">📄 Nhập HTML thủ công (Ngoại tuyến)</span>
                                <?php else: ?>
                                    <a href="<?= htmlspecialchars($art['url']) ?>" target="_blank" rel="noopener noreferrer" style="color: var(--link-color);"><?= htmlspecialchars($art['url']) ?></a>
                                <?php endif; ?>
                            </div>

                            <!-- NHÃN (TAGS) -->
                            <?php if (!empty($art['tags'])): ?>
                                <div style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap; margin-top: 6px;">
                                    <?php foreach ($art['tags'] as $t): ?>
                                        <a href="/index.php?tag=<?= urlencode($t['name']) ?>" class="badge badge-tag" style="text-decoration: none;">
                                            #<?= htmlspecialchars($t['name']) ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- CỤM NÚT THAO TÁC BÀI VIẾT -->
                        <div style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap;">
                            <a href="/view.php?id=<?= $art['id'] ?>" class="btn btn-wiki btn-sm" style="color: #ffffff !important;">
                                📖 Đọc bài
                            </a>

                            <div style="display: inline-flex; align-items: center; border-radius: var(--radius-sm); overflow: hidden; border: 1px solid var(--wiki-border-light);">
                                <a href="/view.php?id=<?= $art['id'] ?>&action=export&format=html" class="btn btn-sm" style="border: none; padding: 4px 8px; font-size: 11.5px; background: #fff;" title="Tải HTML kèm ảnh">
                                    📥 HTML
                                </a>
                                <a href="/view.php?id=<?= $art['id'] ?>&action=export&format=pdf" class="btn btn-sm" style="border: none; border-left: 1px solid var(--wiki-border-light); padding: 4px 8px; font-size: 11.5px; background: #fff; color: #dc2626;" title="Tải PDF (.pdf)">
                                    📄 PDF
                                </a>
                                <a href="/view.php?id=<?= $art['id'] ?>&action=export&format=docx" class="btn btn-sm" style="border: none; border-left: 1px solid var(--wiki-border-light); padding: 4px 8px; font-size: 11.5px; background: #fff; color: #2563eb;" title="Tải Word (.docx)">
                                    📝 DOCX
                                </a>
                            </div>

                            <?php if (!$isPublic): ?>
                                <form method="POST" action="" style="display: inline;" onsubmit="return eduConfirmSubmit(this, event, 'Đưa bài viết này lên Thư viện chung để mọi người cùng xem?', 'Đưa Lên Thư Viện Chung', 'Xác nhận', false);">
                                    <input type="hidden" name="action" value="publish_public">
                                    <input type="hidden" name="page_id" value="<?= $art['id'] ?>">
                                    <button type="submit" class="btn btn-outline btn-sm" style="color: var(--wiki-blue);" title="Đưa lên Thư viện chung">
                                        🌐 Lên thư viện chung
                                    </button>
                                </form>
                            <?php else: ?>
                                <form method="POST" action="" style="display: inline;" onsubmit="return eduConfirmSubmit(this, event, 'Rút bài viết này về kho cá nhân riêng tư?', 'Rút Về Riêng Tư', 'Xác nhận', false);">
                                    <input type="hidden" name="action" value="unpublish_public">
                                    <input type="hidden" name="page_id" value="<?= $art['id'] ?>">
                                    <button type="submit" class="btn btn-outline btn-sm" style="color: #6c757d;" title="Rút về riêng tư">
                                        🔒 Rút về riêng tư
                                    </button>
                                </form>
                            <?php endif; ?>

                            <form method="POST" action="" style="display: inline;" onsubmit="return eduConfirmSubmit(this, event, 'Bạn có chắc chắn muốn xóa bài viết này?<br><small style=\'color:#b91c1c;\'>Thao tác không thể hoàn tác.</small>', 'Xóa Bài Viết', 'Xác nhận xóa');">
                                <input type="hidden" name="action" value="delete_page">
                                <input type="hidden" name="page_id" value="<?= $art['id'] ?>">
                                <button type="submit" class="btn btn-outline btn-sm" style="color: var(--danger);" title="Xóa bài">
                                    🗑️
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($totalPages > 1): ?>
            <?php
            $buildPageUrl = function(int $p): string {
                $params = $_GET;
                $params['page'] = $p;
                return '/libraries.php?' . http_build_query($params);
            };
            ?>
            <nav class="edu-pagination" aria-label="Phân trang bài viết">
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
                    Hiển thị <?= min($totalArticles, $offset + 1) ?>-<?= min($totalArticles, $offset + count($pagedArticles)) ?> trong <?= $totalArticles ?> bài
                </span>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- MODAL LƯU BÀI VIẾT (HỎI RÕ: THƯ VIỆN NÀO, TAG GÌ, GHI CHÚ) -->
<div id="saveModal" style="display: <?= (!empty($error) && isset($_POST['action']) && $_POST['action'] === 'save_page') ? 'flex' : 'none' ?>; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 2000; align-items: center; justify-content: center;">
    <div style="background: #ffffff; border-radius: var(--radius-md); max-width: 640px; width: 92%; padding: 26px; box-shadow: var(--shadow-md); max-height: 90vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--wiki-border-light); padding-bottom: 12px; margin-bottom: 16px;">
            <h3 style="margin: 0; font-size: 20px; font-weight: 700; color: var(--wiki-blue);">
                📥 Lưu Bài Viết Vào Thư Viện Cá Nhân
            </h3>
            <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('saveModal').style.display = 'none';">✕</button>
        </div>

        <!-- THÔNG BÁO LỖI BÊN TRONG MODAL -->
        <?php if ($isSaveAction && $error): ?>
            <div class="alert alert-danger" style="margin-bottom: 16px; border-left: 4px solid var(--danger); display: flex; align-items: flex-start; gap: 8px;">
                <span style="font-size: 16px; line-height: 1;">⚠️</span>
                <div><strong>Lỗi:</strong> <?= htmlspecialchars($error) ?></div>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="action" value="save_page">

            <!-- 1. URL BÀI VIẾT -->
            <div class="form-group" style="margin-bottom: 14px;">
                <label class="form-label" style="font-weight: 700; color: var(--wiki-blue);">
                    🔗 Đường dẫn URL bài viết: <span id="libUrlRequiredBadge" style="color: #b32424;">*</span>
                </label>
                <input type="url" name="url" id="libUrlInput" class="form-control" placeholder="https://vi.wikipedia.org/... hoặc link bài bất kỳ..." value="<?= htmlspecialchars($_POST['url'] ?? '') ?>" required style="height: 40px;">
                <div id="libUrlNote" style="font-size: 11.5px; color: var(--text-muted); margin-top: 3px; display: none;">
                    💡 Bạn đã dán mã HTML trực tiếp, ô URL này không còn bắt buộc (có thể để trống).
                </div>
            </div>

            <!-- 2. LƯU Ở THƯ VIỆN NÀO? -->
            <div class="form-group" style="margin-bottom: 14px;">
                <label class="form-label" style="font-weight: 700;">
                    📁 Bạn muốn lưu ở Thư viện nào?
                </label>
                <select name="library_id" id="modalLibSelect" class="form-select" style="height: 40px;" onchange="checkNewLibOption(this.value)">
                    <?php if (empty($myLibraries)): ?>
                        <option value="-1" selected>➕ [Tạo thư viện cá nhân mới...]</option>
                        <option value="1">🌐 Đưa thẳng lên Thư viện chung</option>
                    <?php else: ?>
                        <?php foreach ($myLibraries as $lib): ?>
                            <option value="<?= $lib['id'] ?>" <?= $selectedLibId === (int)$lib['id'] ? 'selected' : '' ?>>
                                📁 <?= htmlspecialchars($lib['name']) ?>
                            </option>
                        <?php endforeach; ?>
                        <option value="-1">➕ [Tạo nhanh thư viện mới...]</option>
                        <option value="1">🌐 Đưa thẳng lên Thư viện chung</option>
                    <?php endif; ?>
                </select>
            </div>

            <!-- Ô NHẬP TÊN THƯ VIỆN MỚI NẾU CHỌN TẠO NHANH -->
            <div id="newLibInputRow" class="form-group" style="display: <?= empty($myLibraries) ? 'block' : 'none' ?>; margin-bottom: 14px; background: #f8f9fa; padding: 12px; border-radius: var(--radius-sm); border: 1px dashed var(--wiki-border);">
                <label class="form-label" style="font-weight: 600;">Nhập tên thư viện mới:</label>
                <input type="text" name="new_lib_name" class="form-control" placeholder="VD: 📚 Học tập, 🎮 Giải trí, 💻 Lập trình...">
            </div>

            <!-- 3. TAG GÌ? -->
            <div class="form-group" style="margin-bottom: 14px;">
                <label class="form-label" style="font-weight: 700;">
                    🏷️ Gắn Tag (Thẻ nhãn, phân cách bằng dấu phẩy):
                </label>
                <input type="text" name="tags" class="form-control" placeholder="toan-hoc, giai-tich, lap-trinh, bai-giang...">
            </div>

            <!-- 4. GHI CHÚ? -->
            <div class="form-group" style="margin-bottom: 16px;">
                <label class="form-label" style="font-weight: 700;">
                    📝 Ghi chú cá nhân (Tùy chọn):
                </label>
                <textarea name="initial_note" class="form-control" rows="2" placeholder="Ghi chú tóm tắt, điểm cần lưu ý trong bài này..."><?= htmlspecialchars($_POST['initial_note'] ?? '') ?></textarea>
            </div>

            <!-- DÁN HTML THỦ CÔNG (TÙY CHỌN) -->
            <div style="margin-bottom: 16px;">
                <button type="button" class="btn btn-outline btn-sm" onclick="var el = document.getElementById('lib-manual-html'); el.style.display = el.style.display === 'none' ? 'block' : 'none';" style="font-size: 12.5px;">
                    ✍️ Dán mã HTML thủ công (tùy chọn)
                </button>
                <div id="lib-manual-html" style="display: <?= !empty($_POST['manual_html']) ? 'block' : 'none' ?>; margin-top: 10px; background: #f8f9fa; border: 1px dashed var(--wiki-border); padding: 12px; border-radius: var(--radius-sm);">
                    <label class="form-label" style="font-weight: 600;">Dán nội dung HTML bài viết trực tiếp (dành cho trang nội bộ / yêu cầu đăng nhập):</label>
                    <textarea name="manual_html" id="libManualHtmlInput" class="form-control" rows="4" 
                              placeholder="<article><h2>Tiêu đề bài viết</h2><p>Nội dung...</p></article>"
                              oninput="handleLibManualHtmlChange(this.value)"><?= htmlspecialchars($_POST['manual_html'] ?? '') ?></textarea>
                    <div id="libHtmlTitlePreview" style="display: none; margin-top: 8px; font-size: 12.5px; color: #15803d; font-weight: 600; background: #eefbf1; border: 1px solid #bbf7d0; padding: 6px 10px; border-radius: 4px;"></div>
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 8px;">
                <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('saveModal').style.display = 'none';">Hủy</button>
                <button type="submit" class="btn btn-wiki btn-sm">🚀 Lưu trữ ngay</button>
            </div>
        </form>
    </div>
</div>

<!-- FORM ĐỔI TÊN THƯ VIỆN MODAL -->
<form id="renameForm" method="POST" action="" style="display: none;">
    <input type="hidden" name="action" value="rename_library">
    <input type="hidden" name="library_id" id="renameLibId" value="">
    <input type="hidden" name="new_name" id="renameNewName" value="">
</form>

<script>
function openSaveModalWithLib(libId) {
    var select = document.getElementById('modalLibSelect');
    if (libId > 0) {
        select.value = libId;
    }
    document.getElementById('saveModal').style.display = 'flex';
}

function checkNewLibOption(val) {
    var row = document.getElementById('newLibInputRow');
    if (val === '-1') {
        row.style.display = 'block';
    } else {
        row.style.display = 'none';
    }
}

function promptRename(libId, currentName) {
    eduPrompt({
        title: 'Đổi Tên Thư Viện',
        label: 'Tên thư viện mới:',
        currentValue: currentName,
        onConfirm: function(newName) {
            if (newName && newName.trim() !== '' && newName.trim() !== currentName) {
                document.getElementById('renameLibId').value = libId;
                document.getElementById('renameNewName').value = newName.trim();
                document.getElementById('renameForm').submit();
            }
        }
    });
}

function handleLibManualHtmlChange(val) {
    var urlInp = document.getElementById('libUrlInput');
    var badge = document.getElementById('libUrlRequiredBadge');
    var note = document.getElementById('libUrlNote');
    var preview = document.getElementById('libHtmlTitlePreview');
    if (!urlInp) return;
    if (val && val.trim() !== '') {
        urlInp.removeAttribute('required');
        if (badge) badge.innerHTML = '<span style="color:#6b7280; font-weight:normal; font-size:12px;">(Tùy chọn khi đã dán HTML)</span>';
        urlInp.placeholder = '(Tùy chọn) Nhập link nguồn gốc nếu có, hoặc để trống...';
        if (note) note.style.display = 'block';

        // Tự động phân tích xem trước tiêu đề bài viết
        try {
            var doc = new DOMParser().parseFromString(val, 'text/html');
            var title = '';
            var og = doc.querySelector('meta[property="og:title"], meta[content][property="og:title"]');
            if (og && og.getAttribute('content')) title = og.getAttribute('content').trim();
            if (!title) {
                var t = doc.querySelector('title');
                if (t && t.textContent) title = t.textContent.trim();
            }
            if (!title) {
                var h1 = doc.querySelector('h1');
                if (h1 && h1.textContent) title = h1.textContent.trim();
            }
            if (!title) {
                var h2 = doc.querySelector('h2');
                if (h2 && h2.textContent) title = h2.textContent.trim();
            }
            if (!title) {
                var h3 = doc.querySelector('h3');
                if (h3 && h3.textContent) title = h3.textContent.trim();
            }
            if (!title) {
                var bodyText = doc.body ? (doc.body.textContent || '').trim() : '';
                if (bodyText) {
                    var lines = bodyText.split(/\r\n|\r|\n/);
                    title = (lines[0] || '').trim();
                    if (title.length > 70) title = title.substring(0, 67) + '...';
                }
            }
            if (preview) {
                if (title) {
                    preview.style.display = 'block';
                    preview.innerHTML = '✨ Tự động nhận diện tiêu đề: <em>"' + escapeHtml(title) + '"</em> (không cần nhập URL)';
                } else {
                    preview.style.display = 'none';
                }
            }
        } catch (e) {}
    } else {
        urlInp.setAttribute('required', 'required');
        if (badge) badge.innerHTML = '<span style="color:#b32424;">*</span>';
        urlInp.placeholder = 'https://vi.wikipedia.org/... hoặc link bài bất kỳ...';
        if (note) note.style.display = 'none';
        if (preview) preview.style.display = 'none';
    }
}

function escapeHtml(text) {
    return (text || '').replace(/[&<>"']/g, function(m) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m];
    });
}

document.addEventListener('DOMContentLoaded', function() {
    var ta = document.getElementById('libManualHtmlInput');
    if (ta && ta.value.trim() !== '') {
        handleLibManualHtmlChange(ta.value);
    }
});
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
