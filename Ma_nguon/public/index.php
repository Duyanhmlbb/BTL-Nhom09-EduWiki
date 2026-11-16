<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../src/ArchiveRepository.php';
require_once __DIR__ . '/../src/GroupRepository.php';
require_once __DIR__ . '/../src/WebFetcher.php';
require_once __DIR__ . '/../src/Auth.php';

$currentUser = Auth::user();
$userId = $currentUser ? (int)$currentUser['id'] : null;

$repo = new ArchiveRepository(db(), $userId);
$groupRepo = new GroupRepository(db());

$message = isset($_GET['msg']) ? trim($_GET['msg']) : null;
$error = null;
$savedPageId = null;

// Lấy danh sách nhóm & danh sách thư viện cá nhân của user (nếu đã đăng nhập)
$userGroups = $userId !== null ? $groupRepo->getGroupsForUser($userId) : [];
$myLibraries = $userId !== null ? $repo->getLibraries($userId) : [];

// Thông tin Thư viện chung hệ thống
$stmtPubInfo = db()->query("
    SELECT l.id, l.name, l.access_level,
           COUNT(p.id) AS page_count
    FROM libraries l
    LEFT JOIN saved_pages p ON p.library_id = l.id
    WHERE l.id = 1
    GROUP BY l.id, l.name, l.access_level
");
$publicLibInfo = $stmtPubInfo->fetch(PDO::FETCH_ASSOC) ?: ['id' => 1, 'name' => '🌐 Thư viện chung', 'page_count' => 0];

// =========================================================================
// 1. XỬ LÝ POST: LƯU TÀI LIỆU TỪ LINK HOẶC MÃ HTML
// (BẮT BUỘC ĐĂNG NHẬP THEO YÊU CẦU)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_page') {
    if (!$currentUser) {
        header('Location: /login.php?msg=' . urlencode('Vui lòng đăng nhập để lưu trữ hoặc đóng góp bài viết vào hệ thống!'));
        exit;
    }

    $url = trim($_POST['url'] ?? '');
    $chosenLib = trim($_POST['library_choice'] ?? '1'); // '1', 'my_default', 'lib_<id>', 'group_<id>', 'new_lib'
    $newLibName = trim($_POST['new_lib_name'] ?? '');
    $tagsInput = trim($_POST['tags'] ?? '');
    $initialNote = trim($_POST['initial_note'] ?? '');
    $manualHtml = trim($_POST['manual_html'] ?? '');

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
            $error = 'Vui lòng nhập đường dẫn URL của bài viết hoặc dán mã HTML!';
        } else {
            $fetchResult = WebFetcher::fetch($url);
            if (!$fetchResult['success']) {
                $error = 'Không thể nạp nội dung từ liên kết: ' . $fetchResult['error'];
            } else {
                $htmlToSave = $fetchResult['html'];
                $finalUrl = $fetchResult['final_url'];
            }
        }
    }

    if ($htmlToSave !== '' && $error === null) {
        try {
            // Xác định library_id đích
            $targetLibId = 1; // Mặc định Thư viện chung

            if ($chosenLib === '1') {
                $targetLibId = 1;
            } elseif ($chosenLib === 'new_lib' && $newLibName !== '') {
                $targetLibId = $repo->createLibrary($newLibName, 'private', $userId);
            } elseif (str_starts_with($chosenLib, 'lib_')) {
                $targetLibId = (int)str_replace('lib_', '', $chosenLib);
            } elseif (str_starts_with($chosenLib, 'group_')) {
                $targetGroupId = (int)str_replace('group_', '', $chosenLib);
                $stmtGLib = db()->prepare("SELECT id FROM libraries WHERE group_id = ? ORDER BY id ASC LIMIT 1");
                $stmtGLib->execute([$targetGroupId]);
                $targetLibId = (int)$stmtGLib->fetchColumn();
                if (!$targetLibId) {
                    $targetLibId = $groupRepo->createGroupLibrary($targetGroupId, '📁 Tài liệu chung nhóm', $userId, true);
                }
            } else {
                $targetLibId = 1; // Mặc định Thư viện chung (không tự sinh thư viện riêng)
            }

            // Tự động tải ảnh về máy chủ nội bộ để không bao giờ bị mất khi link gốc chết
            $pageId = $repo->savePage($targetLibId, $finalUrl, $htmlToSave, ['localize_images' => true], $userId);
            $savedPageId = $pageId;

            if ($tagsInput !== '') {
                $repo->addTagsFromString($pageId, $tagsInput);
            }

            if ($initialNote !== '') {
                $repo->addNote($pageId, $initialNote);
            }

            $destName = ($targetLibId === 1) ? 'Thư viện chung' : 'Thư viện của bạn';
            header('Location: /view.php?id=' . $pageId . '&msg=' . urlencode("Đã lưu trữ bài viết thành công vào {$destName}! Dưới đây là bản đọc offline của bạn."));
            exit;
        } catch (Throwable $e) {
            $error = 'Lỗi trong quá trình lưu trữ: ' . $e->getMessage();
        }
    }
}

// =========================================================================
// 2. XỬ LÝ POST: ĐƯA LÊN THƯ VIỆN CHUNG / RÚT VỀ / XÓA
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'publish_public') {
    if (!$currentUser) {
        $error = "Bạn cần đăng nhập để thực hiện thao tác này.";
    } else {
        $pId = (int)($_POST['page_id'] ?? 0);
        if ($pId > 0 && $repo->publishToPublic($pId, $userId)) {
            $message = "Đã đưa bài viết lên Thư viện chung thành công!";
        } else {
            $error = "Bạn không có quyền đưa bài viết này lên Thư viện chung.";
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'unpublish_public') {
    if (!$currentUser) {
        $error = "Bạn cần đăng nhập để thực hiện thao tác này.";
    } else {
        $pId = (int)($_POST['page_id'] ?? 0);
        if ($pId > 0 && $repo->unpublishFromPublic($pId, $userId)) {
            $message = "Đã rút bài viết về Kho cá nhân riêng tư của bạn!";
        } else {
            $error = "Bạn không có quyền chuyển bài viết này.";
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_page') {
    if (!$currentUser) {
        $error = "Bạn cần đăng nhập để thực hiện thao tác này.";
    } else {
        $deleteId = (int)($_POST['page_id'] ?? 0);
        $userRole = $currentUser['role'] ?? 'member';
        if ($deleteId > 0 && $repo->deletePage($deleteId, $userId, $userRole)) {
            $message = "Đã xóa bài viết khỏi hệ thống thành công!";
        } else {
            $error = "Bạn không có quyền xóa bài viết này.";
        }
    }
}

// =========================================================================
// 3. XỬ LÝ TÌM KIẾM ĐA TIÊU CHÍ (THƯ VIỆN, TAG, NỘI DUNG, TÊN ĐỀ MỤC BÀI)
// =========================================================================
$searchKeyword = trim($_GET['q'] ?? '');
$searchScope = trim($_GET['scope'] ?? 'all'); // 'all', 'heading', 'content'
$searchTag = trim($_GET['tag'] ?? '');
$searchLibParam = $_GET['library_id'] ?? '1'; // Mặc định hiển thị Thư viện chung (1)

$selectedLibId = null;
if ($searchLibParam === 'all') {
    $selectedLibId = null; // Tìm trên tất cả thư viện được phép
} elseif (is_numeric($searchLibParam) && (int)$searchLibParam > 0) {
    $selectedLibId = (int)$searchLibParam;
} else {
    $selectedLibId = 1; // Mặc định trang chính là Thư viện chung
}

$articles = $repo->searchPages(
    $selectedLibId,
    $searchKeyword !== '' ? $searchKeyword : null,
    $searchTag !== '' ? $searchTag : null,
    null,
    null,
    $userId,
    $searchScope
);

// =========================================================================
// PHÂN TRANG: TỐI ĐA 5 BÀI VIẾT MỖI TRANG
// =========================================================================
$perPage = 5;
$totalArticles = count($articles);
$totalPages = max(1, (int)ceil($totalArticles / $perPage));
$currentPage = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));
$offset = ($currentPage - 1) * $perPage;
$pagedArticles = array_slice($articles, $offset, $perPage);

// Lấy danh sách tất cả các tags để gợi ý
$allTags = $repo->getAllTags($userId);

$pageTitle = 'Trang Chính - Wiki Archiver';
require_once __DIR__ . '/../templates/header.php';
?>

<!-- BANNER TRANG CHÍNH: HIỂN THỊ THÔNG TIN THƯ VIỆN CHUNG -->
<div style="background: #ffffff; border: 1px solid var(--wiki-border-light); border-radius: var(--radius-md); padding: 24px 28px; margin-top: 18px; margin-bottom: 22px; box-shadow: var(--shadow-sm);">
    <div>
        <div style="display: inline-flex; align-items: center; gap: 6px; background: var(--wiki-blue-light); color: var(--wiki-blue); padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; margin-bottom: 8px;">
            🌐 Kho Lưu Trữ & Tra Cứu Tư Liệu Mở
        </div>
        <h1 style="font-size: 26px; font-weight: 700; margin: 0 0 8px 0; color: var(--text-main); line-height: 1.25;">
            Wiki Archiver — <?= htmlspecialchars($publicLibInfo['name']) ?>
        </h1>
        <p style="margin: 0; font-size: 14.5px; color: var(--text-muted); line-height: 1.6;">
            Nền tảng sao lưu và lưu trữ bài viết, tin tức, tư liệu web ngoại tuyến. Hệ thống tự động làm sạch nội dung và tải toàn bộ hình ảnh về máy chủ nội bộ để bạn có thể đọc offline bất cứ lúc nào.
        </p>
    </div>
</div>

<!-- TOAST THÔNG BÁO NỔI Ở GÓC MÀN HÌNH -->
<?php if ($message || $error): ?>
<div id="eduToastNotification" style="position: fixed; top: 24px; right: 24px; z-index: 99999; max-width: 420px; min-width: 320px; box-shadow: 0 8px 24px rgba(0,0,0,0.18); border-radius: 8px; overflow: hidden; pointer-events: auto; animation: toastSlideIn 0.35s cubic-bezier(0.16, 1, 0.3, 1);">
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
                <?php if (!empty($savedPageId)): ?>
                    <div style="margin-top: 8px;">
                        <a href="/view.php?id=<?= $savedPageId ?>" class="btn btn-wiki btn-sm" style="color: #fff !important; font-size: 12px; padding: 4px 10px;">
                            📖 Đọc bài viết ngay &rarr;
                        </a>
                    </div>
                <?php endif; ?>
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

<!-- THÔNG BÁO NỀN TRANG CHÍNH (KHÔNG HIỂN THỊ NẾU LÀ THAO TÁC TỪ MODAL ĐỂ TRÁNH BỊ CHE KHUẤT) -->
<?php 
$isSaveAction = (isset($_POST['action']) && $_POST['action'] === 'save_page');
?>
<?php if ($message && !$isSaveAction): ?>
    <div class="alert alert-success">
        <div style="flex: 1;">
            <strong>✓ Thành công:</strong> <?= htmlspecialchars($message) ?>
        </div>
        <?php if ($savedPageId): ?>
            <a href="/view.php?id=<?= $savedPageId ?>" class="btn btn-wiki btn-sm" style="color: #fff !important;">
                📖 Mở đọc bài viết ngay &rarr;
            </a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($error && !$isSaveAction): ?>
    <div class="alert alert-danger">
        <strong>✕ Lỗi:</strong> <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<!-- ========================================================================= -->
<!-- BỘ TÌM KIẾM NÂNG CAO (TÌM THEO THƯ VIỆN, TAG, NỘI DUNG, TÊN ĐỀ MỤC BÀI) -->
<!-- ========================================================================= -->
<div class="card" style="margin-bottom: 22px; border-top: 3px solid var(--wiki-blue); padding: 20px 24px;">
    <div class="card-title" style="margin-bottom: 14px;">
        <span style="display: flex; align-items: center; gap: 8px;">
            <span>🔍</span> Tìm Kiếm Tư Liệu & Bài Viết
        </span>
        <span style="font-size: 12.5px; font-weight: normal; color: var(--text-muted);">
            Tìm theo thư viện, thẻ tag, nội dung hoặc tên đề mục bài viết
        </span>
    </div>

    <form method="GET" action="/index.php">
        <div style="display: grid; grid-template-columns: 2fr 1.3fr 1.3fr 1fr auto; gap: 10px; align-items: end;">
            <!-- 1. TỪ KHÓA TÌM KIẾM -->
            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label" style="font-weight: 600; font-size: 13px;">Từ khóa cần tìm:</label>
                <input type="text" name="q" value="<?= htmlspecialchars($searchKeyword) ?>" 
                       class="form-control" placeholder="Nhập từ khóa, tên bài viết, tiêu đề tư liệu..." style="height: 38px;">
            </div>

            <!-- 2. PHẠM VI TÌM KIẾM (ĐỀ MỤC BÀI, NỘI DUNG, TẤT CẢ) -->
            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label" style="font-weight: 600; font-size: 13px;">Phạm vi tìm kiếm:</label>
                <select name="scope" class="form-select" style="height: 38px;">
                    <option value="all" <?= $searchScope === 'all' ? 'selected' : '' ?>>🔍 Tất cả (Đề mục & Nội dung)</option>
                    <option value="heading" <?= $searchScope === 'heading' ? 'selected' : '' ?>>🏷️ Tên đề mục & Tiêu đề bài</option>
                    <option value="content" <?= $searchScope === 'content' ? 'selected' : '' ?>>📄 Chỉ trong nội dung bài đó</option>
                </select>
            </div>

            <!-- 3. TÌM THEO THƯ VIỆN -->
            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label" style="font-weight: 600; font-size: 13px;">Thư viện:</label>
                <select name="library_id" class="form-select" style="height: 38px;">
                    <option value="1" <?= $searchLibParam === '1' ? 'selected' : '' ?>>🌐 Thư viện chung</option>
                    <?php if ($currentUser && !empty($myLibraries)): ?>
                        <optgroup label="Thư viện cá nhân của tôi">
                            <?php foreach ($myLibraries as $ml): ?>
                                <option value="<?= $ml['id'] ?>" <?= $searchLibParam === (string)$ml['id'] ? 'selected' : '' ?>>
                                    📁 <?= htmlspecialchars($ml['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>
                    <option value="all" <?= $searchLibParam === 'all' ? 'selected' : '' ?>>📂 Tất cả thư viện được phép</option>
                </select>
            </div>

            <!-- 4. TÌM THEO TAG -->
            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label" style="font-weight: 600; font-size: 13px;">Lọc theo Tag:</label>
                <input type="text" name="tag" value="<?= htmlspecialchars($searchTag) ?>" 
                       class="form-control" placeholder="VD: cong-nghe, tin-tuc..." style="height: 38px;">
            </div>

            <!-- NÚT TÌM KIẾM -->
            <div style="display: flex; gap: 4px;">
                <button type="submit" class="btn btn-wiki" style="height: 38px; padding: 0 18px; font-size: 13.5px;">
                    Tra cứu
                </button>
                <?php if ($searchKeyword !== '' || $searchTag !== '' || $searchLibParam !== '1' || $searchScope !== 'all'): ?>
                    <a href="/index.php" class="btn btn-outline" style="height: 38px; padding: 0 10px; display: flex; align-items: center;" title="Đặt lại bộ lọc">
                        ✕
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- CÁC THẺ TAG GỢI Ý NHANH -->
        <?php if (!empty($allTags)): ?>
            <div style="display: flex; gap: 6px; align-items: center; margin-top: 10px; flex-wrap: wrap; font-size: 12px; color: var(--text-muted);">
                <span>Tag phổ biến:</span>
                <?php foreach (array_slice($allTags, 0, 8) as $tg): ?>
                    <a href="/index.php?tag=<?= urlencode($tg['name']) ?>&library_id=<?= htmlspecialchars($searchLibParam) ?>" class="badge badge-tag" style="text-decoration: none;">
                        #<?= htmlspecialchars($tg['name']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </form>
</div>

<!-- ========================================================================= -->
<!-- KHU VỰC NÚT LƯU BÀI VIẾT NHỎ GỌN (BẤM VÀO MỚI HIỆN POPUP ĐỂ KHÔNG BỊ LOẠN) -->
<!-- ========================================================================= -->
<div style="margin-bottom: 22px;">
    <?php if ($currentUser): ?>
        <div style="display: flex; justify-content: space-between; align-items: center; background: #ffffff; border: 1px solid var(--wiki-border-light); border-radius: var(--radius-md); padding: 14px 20px; box-shadow: var(--shadow-sm); flex-wrap: wrap; gap: 12px;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 40px; height: 40px; border-radius: 8px; background: var(--wiki-blue-light); color: var(--wiki-blue); display: flex; align-items: center; justify-content: center; font-size: 20px;">
                    📥
                </div>
                <div>
                    <h4 style="margin: 0; font-size: 15px; font-weight: 700; color: var(--text-main);">
                        Lưu trữ bài viết hoặc trang web mới vào hệ thống
                    </h4>
                    <p style="margin: 2px 0 0; font-size: 13px; color: var(--text-muted);">
                        Hệ thống tự động quét, làm sạch và tải toàn bộ ảnh về máy chủ nội bộ an toàn.
                    </p>
                </div>
            </div>
            <button type="button" class="btn btn-wiki" onclick="openSaveModal();" style="color: #ffffff !important; padding: 8px 18px; font-size: 13.5px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;">
                <span>➕</span> Lưu bài viết mới
            </button>
        </div>

        <!-- MODAL POPUP LƯU BÀI VIẾT GỌN GÀNG -->
        <div id="homeSaveModal" style="display: <?= (!empty($error) && isset($_POST['action']) && $_POST['action'] === 'save_page') || isset($_GET['open_save']) ? 'flex' : 'none' ?>; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 2000; align-items: center; justify-content: center; padding: 16px;">
            <div style="background: #ffffff; border-radius: var(--radius-md); max-width: 640px; width: 100%; padding: 26px; box-shadow: var(--shadow-md); max-height: 90vh; overflow-y: auto;">
                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--wiki-border-light); padding-bottom: 12px; margin-bottom: 18px;">
                    <h3 style="margin: 0; font-size: 19px; font-weight: 700; color: var(--wiki-blue); display: flex; align-items: center; gap: 8px;">
                        <span>📥</span> Lưu Bài Viết Vào Hệ Thống (URL Archiver)
                    </h3>
                    <button type="button" class="btn btn-outline btn-sm" onclick="closeSaveModal();" style="font-size: 14px; padding: 4px 10px;">✕</button>
                </div>

                <!-- THÔNG BÁO LỖI HOẶC THÀNH CÔNG NGAY BÊN TRONG MODAL -->
                <?php if ($isSaveAction && $error): ?>
                    <div class="alert alert-danger" style="margin-bottom: 16px; border-left: 4px solid var(--danger); display: flex; align-items: flex-start; gap: 8px;">
                        <span style="font-size: 16px; line-height: 1;">⚠️</span>
                        <div><strong>Lỗi:</strong> <?= htmlspecialchars($error) ?></div>
                    </div>
                <?php endif; ?>

                <?php if ($isSaveAction && $message): ?>
                    <div class="alert alert-success" style="margin-bottom: 16px; border-left: 4px solid var(--success); display: flex; align-items: flex-start; gap: 8px;">
                        <span style="font-size: 16px; line-height: 1;">✅</span>
                        <div style="flex: 1;">
                            <div><strong>Thành công:</strong> <?= htmlspecialchars($message) ?></div>
                            <?php if (!empty($savedPageId)): ?>
                                <div style="margin-top: 8px;">
                                    <a href="/view.php?id=<?= $savedPageId ?>" class="btn btn-wiki btn-sm" style="color: #fff !important; font-size: 12px;">
                                        📖 Mở đọc bài viết ngay &rarr;
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <input type="hidden" name="action" value="save_page">

                    <!-- URL BÀI VIẾT -->
                    <div class="form-group" style="margin-bottom: 14px;">
                        <label class="form-label" style="font-weight: 700; color: var(--wiki-blue);">
                            🔗 Đường dẫn URL bài viết cần lưu: <span id="homeUrlRequiredBadge" style="color: #b32424;">*</span>
                        </label>
                        <input type="url" name="url" id="homeUrlInput" class="form-control" 
                               placeholder="https://vi.wikipedia.org/... hoặc link bài viết, tin tức bất kỳ..." 
                               value="<?= htmlspecialchars($_POST['url'] ?? '') ?>"
                               required style="height: 40px;">
                        <div id="homeUrlNote" style="font-size: 11.5px; color: var(--text-muted); margin-top: 3px; display: none;">
                            💡 Bạn đã dán mã HTML trực tiếp, ô URL này không còn bắt buộc (có thể để trống).
                        </div>
                    </div>

                    <!-- LƯU Ở THƯ VIỆN NÀO? -->
                    <div class="form-group" style="margin-bottom: 14px;">
                        <label class="form-label" style="font-weight: 700;">
                            📁 Lưu ở Thư viện nào?
                        </label>
                        <select name="library_choice" id="homeLibChoice" class="form-select" style="height: 40px;" onchange="toggleHomeNewLib(this.value)">
                            <option value="1">🌐 Đưa thẳng lên Thư viện chung</option>
                            <?php if (!empty($myLibraries)): ?>
                                <optgroup label="Kho thư viện cá nhân của tôi">
                                    <?php foreach ($myLibraries as $mlib): ?>
                                        <option value="lib_<?= $mlib['id'] ?>">📁 <?= htmlspecialchars($mlib['name']) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                            <option value="new_lib">➕ [Tạo nhanh thư viện mới...]</option>
                            <?php if (!empty($userGroups)): ?>
                                <optgroup label="Thư viện Nhóm bạn bè">
                                    <?php foreach ($userGroups as $grp): ?>
                                        <option value="group_<?= $grp['id'] ?>">👥 Nhóm: <?= htmlspecialchars($grp['name']) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                        </select>
                    </div>

                    <!-- Ô NHẬP TÊN THƯ VIỆN MỚI NẾU CHỌN TẠO NHANH -->
                    <div id="homeNewLibRow" class="form-group" style="display: none; margin-bottom: 14px; background: #f8f9fa; padding: 12px; border-radius: var(--radius-sm); border: 1px dashed var(--wiki-border);">
                        <label class="form-label" style="font-weight: 600;">Nhập tên thư viện mới muốn tạo:</label>
                        <input type="text" name="new_lib_name" class="form-control" placeholder="Nhập tên thư viện...">
                    </div>

                    <!-- TAG GÌ VÀ GHI CHÚ -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label" style="font-weight: 600; font-size: 13px;">🏷️ Tag (cách nhau bằng dấu phẩy):</label>
                            <input type="text" name="tags" class="form-control" placeholder="cong-nghe, tin-tuc..." style="height: 38px;">
                        </div>

                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label" style="font-weight: 600; font-size: 13px;">📝 Ghi chú tư liệu ban đầu:</label>
                            <input type="text" name="initial_note" class="form-control" placeholder="Ghi chú tóm tắt bài viết..." style="height: 38px;">
                        </div>
                    </div>

                    <!-- DÁN HTML THỦ CÔNG (GẬP MỞ) -->
                    <div style="margin-bottom: 18px;">
                        <button type="button" class="btn btn-outline btn-sm" onclick="var el = document.getElementById('home-manual-html'); el.style.display = el.style.display === 'none' ? 'block' : 'none';" style="font-size: 12.5px;">
                            ✍️ Dán mã HTML thủ công (tùy chọn)
                        </button>
                        <div id="home-manual-html" style="display: <?= !empty($_POST['manual_html']) ? 'block' : 'none' ?>; margin-top: 10px; background: #f8f9fa; border: 1px dashed var(--wiki-border); padding: 12px; border-radius: var(--radius-sm);">
                            <label class="form-label" style="font-weight: 600;">Dán nội dung HTML bài viết trực tiếp (dành cho trang nội bộ / yêu cầu đăng nhập):</label>
                            <textarea name="manual_html" id="homeManualHtmlInput" class="form-control" rows="4" 
                                      placeholder="<article><h2>Tiêu đề bài viết</h2><p>Nội dung...</p></article>"
                                      oninput="handleManualHtmlChange(this.value)"><?= htmlspecialchars($_POST['manual_html'] ?? '') ?></textarea>
                            <div id="homeHtmlTitlePreview" style="display: none; margin-top: 8px; font-size: 12.5px; color: #15803d; font-weight: 600; background: #eefbf1; border: 1px solid #bbf7d0; padding: 6px 10px; border-radius: 4px;"></div>
                        </div>
                    </div>

                    <div style="display: flex; justify-content: flex-end; gap: 10px; border-top: 1px solid var(--wiki-border-light); padding-top: 14px;">
                        <button type="button" class="btn btn-outline" onclick="closeSaveModal();">Hủy bỏ</button>
                        <button type="submit" class="btn btn-wiki" style="color: #ffffff !important; padding: 8px 22px; font-weight: 600;">
                            🚀 Bắt đầu lưu trữ
                        </button>
                    </div>
                </form>
            </div>
        </div>
    <?php else: ?>
        <!-- NẾU CHƯA ĐĂNG NHẬP: THÔNG BÁO GỌN GÀNG -->
        <div style="display: flex; justify-content: space-between; align-items: center; background: #ffffff; border: 1px solid var(--wiki-border-light); border-radius: var(--radius-md); padding: 14px 20px; box-shadow: var(--shadow-sm); flex-wrap: wrap; gap: 14px;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 40px; height: 40px; border-radius: 8px; background: #fef3c7; color: #d97706; display: flex; align-items: center; justify-content: center; font-size: 20px;">
                    🔒
                </div>
                <div>
                    <h4 style="margin: 0; font-size: 15px; font-weight: 700; color: var(--text-main);">
                        Bạn muốn lưu bài viết mới hoặc đóng góp vào Thư viện chung?
                    </h4>
                    <p style="margin: 2px 0 0; font-size: 13px; color: var(--text-muted);">
                        Vui lòng <strong>Đăng nhập tài khoản</strong> để lưu bài viết ngoại tuyến hoặc tải tài liệu về máy.
                    </p>
                </div>
            </div>
            <div style="display: flex; gap: 8px;">
                <a href="/login.php?msg=<?= urlencode('Vui lòng đăng nhập để lưu trữ bài viết!') ?>" class="btn btn-wiki btn-sm" style="color: #ffffff !important; padding: 8px 18px;">
                    🔑 Đăng nhập
                </a>
                <a href="/register.php" class="btn btn-outline btn-sm" style="padding: 8px 18px;">
                    📝 Đăng ký
                </a>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- ========================================================================= -->
<!-- DANH SÁCH BÀI VIẾT TRONG THƯ VIỆN CHUNG (HOẶC KẾT QUẢ TÌM KIẾM) -->
<!-- ========================================================================= -->
<div class="card" style="padding: 24px;">
    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--wiki-border-light); padding-bottom: 14px; margin-bottom: 18px; flex-wrap: wrap; gap: 10px;">
        <h3 style="margin: 0; font-size: 18px; color: var(--text-main);">
            📖 <?= $searchLibParam === '1' ? 'Tài Liệu Trong Thư Viện Chung' : ($searchLibParam === 'all' ? 'Tất Cả Tài Liệu Được Tìm Thấy' : 'Tài Liệu Trong Thư Viện Đã Chọn') ?>
            <span style="font-size: 13px; font-weight: normal; color: var(--text-muted); margin-left: 6px;">
                (<?= $totalArticles ?> bài viết<?= $totalPages > 1 ? " &bull; Trang $currentPage/$totalPages" : '' ?>)
            </span>
        </h3>

        <?php if ($currentUser): ?>
            <a href="/libraries.php" class="btn btn-outline btn-sm">
                📁 Quản lý Kho của tôi &rarr;
            </a>
        <?php endif; ?>
    </div>

    <?php if (empty($articles)): ?>
        <div style="text-align: center; padding: 48px 20px; color: var(--text-muted);">
            <div style="font-size: 48px; margin-bottom: 12px;">📭</div>
            <h4 style="font-size: 17px; margin: 0 0 8px 0; color: var(--text-main);">
                <?php if ($searchKeyword !== '' || $searchTag !== ''): ?>
                    Không tìm thấy bài viết phù hợp với tiêu chí tra cứu.
                <?php else: ?>
                    Chưa có bài viết nào trong Thư viện chung.
                <?php endif; ?>
            </h4>
            <p style="font-size: 13.5px; margin: 0 0 16px 0;">
                <?php if ($currentUser): ?>
                    Hãy dán link bài viết vào form phía trên để bắt đầu lưu trữ bài học đầu tiên!
                <?php else: ?>
                    Hãy đăng nhập để đóng góp và lưu trữ bài học đầu tiên vào Thư viện chung!
                <?php endif; ?>
            </p>
        </div>
    <?php else: ?>
        <div style="display: flex; flex-direction: column; gap: 16px;">
            <?php foreach ($pagedArticles as $art): ?>
                <?php 
                $isAuthor = $userId !== null && ((int)$art['user_id'] === $userId || ($currentUser['role'] ?? '') === 'admin');
                $isPublic = ($art['access_level'] === 'public');
                ?>
                <div style="border: 1px solid var(--wiki-border-light); border-radius: var(--radius-sm); padding: 18px 20px; background: #ffffff; box-shadow: var(--shadow-sm);">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 14px; flex-wrap: wrap;">
                        <div style="flex: 1; min-width: 280px;">
                            <!-- TIÊU ĐỀ BÀI VIẾT -->
                            <h3 style="margin: 0 0 8px 0; font-size: 18px;">
                                <a href="/view.php?id=<?= $art['id'] ?>" style="color: var(--link-color); text-decoration: none;">
                                    <?= htmlspecialchars($art['title']) ?>
                                </a>
                            </h3>

                            <!-- BADGES THÔNG TIN -->
                            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 12.5px; color: var(--text-muted); margin-bottom: 8px;">
                                <span class="badge" style="background: #e8f0fe; color: #0f4c81; font-weight: 700;">
                                    <?= htmlspecialchars($art['library_name']) ?>
                                </span>

                                <span class="badge" style="background: #e6f4ea; color: #137333;">
                                    🖼️ <?= (int)$art['assets_count'] ?> ảnh offline
                                </span>

                                <span>Lưu ngày: <?= date('d/m/Y H:i', strtotime($art['created_at'])) ?></span>

                                <?php
                                $cardRating = $repo->getPageRatingStats((int)$art['id']);
                                if ($cardRating['avg_stars'] > 0): ?>
                                    <span style="color: #d97706; font-weight: 700; font-size: 12.5px;">
                                        ★ <?= number_format($cardRating['avg_stars'], 1) ?>
                                        <span style="font-weight: normal; color: var(--text-muted); font-size: 11px;">(<?= $cardRating['total_ratings'] ?>)</span>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- NGUỒN GỐC URL -->
                            <div style="font-size: 12.5px; color: var(--text-muted); word-break: break-all; margin-bottom: 8px;">
                                <strong>Nguồn:</strong> 
                                <?php if (str_starts_with($art['url'], 'offline://') || str_starts_with($art['url'], 'manual://')): ?>
                                    <span class="badge badge-tag" style="font-weight: 500;">📄 Nhập HTML thủ công (Ngoại tuyến)</span>
                                <?php else: ?>
                                    <a href="<?= htmlspecialchars($art['url']) ?>" target="_blank" rel="noopener noreferrer" style="color: var(--link-color);"><?= htmlspecialchars($art['url']) ?></a>
                                <?php endif; ?>
                            </div>

                            <!-- HIỂN THỊ ĐỀ MỤC KHỚP NẾU CÓ (SEARCH HEADING MATCH) -->
                            <?php if (!empty($art['matched_headings'])): ?>
                                <div style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap; margin-bottom: 8px;">
                                    <span style="font-size: 12px; font-weight: 700; color: #b45309;">🔖 Khớp đề mục:</span>
                                    <?php foreach ($art['matched_headings'] as $mHeading): ?>
                                        <span class="badge" style="background: #fef3c7; color: #92400e; font-size: 12px;">
                                            <?= htmlspecialchars($mHeading) ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <!-- TRÍCH ĐOẠN NỘI DUNG TÌM THẤY (SNIPPET) -->
                            <?php if (!empty($art['snippet'])): ?>
                                <div style="font-size: 13px; color: #374151; background: #fdf6e2; padding: 8px 12px; border-left: 3px solid #d97706; border-radius: 2px; margin-top: 6px; line-height: 1.5;">
                                    <?= $art['snippet'] ?>
                                </div>
                            <?php endif; ?>

                            <!-- THẺ NHÃN (TAGS) -->
                            <?php if (!empty($art['tags'])): ?>
                                <div style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap; margin-top: 8px;">
                                    <?php foreach ($art['tags'] as $tg): ?>
                                        <a href="/index.php?tag=<?= urlencode($tg['name']) ?>" class="badge badge-tag" style="text-decoration: none;">
                                            #<?= htmlspecialchars($tg['name']) ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- CỤM NÚT THAO TÁC / QUY ĐỊNH ĐĂNG NHẬP ĐỂ TẢI BÀI VỀ -->
                        <div style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap;">
                            <!-- ĐỌC BÀI VIẾT -->
                            <a href="/view.php?id=<?= $art['id'] ?>" class="btn btn-wiki btn-sm" style="color: #ffffff !important;">
                                📖 Đọc bài
                            </a>

                            <!-- NÚT TẢI VỀ: HỖ TRỢ HTML / PDF / WORD (DOCX) -->
                            <?php if ($currentUser): ?>
                                <div style="display: inline-flex; align-items: center; border-radius: var(--radius-sm); overflow: hidden; border: 1px solid var(--wiki-border-light);">
                                    <a href="/view.php?id=<?= $art['id'] ?>&action=export&format=html" class="btn btn-sm" style="border: none; padding: 4px 8px; font-size: 11.5px; background: #fff;" title="Tải trang HTML kèm toàn bộ ảnh">
                                        📥 HTML
                                    </a>
                                    <a href="/view.php?id=<?= $art['id'] ?>&action=export&format=pdf" class="btn btn-sm" style="border: none; border-left: 1px solid var(--wiki-border-light); padding: 4px 8px; font-size: 11.5px; background: #fff; color: #dc2626;" title="Tải tài liệu PDF (.pdf)">
                                        📄 PDF
                                    </a>
                                    <a href="/view.php?id=<?= $art['id'] ?>&action=export&format=docx" class="btn btn-sm" style="border: none; border-left: 1px solid var(--wiki-border-light); padding: 4px 8px; font-size: 11.5px; background: #fff; color: #2563eb;" title="Tải văn bản Word (.docx)">
                                        📝 DOCX
                                    </a>
                                </div>
                            <?php else: ?>
                                <a href="/login.php?msg=<?= urlencode('Vui lòng đăng nhập để tải bài viết về máy!') ?>&redirect=<?= urlencode('/view.php?id=' . $art['id'] . '&action=export') ?>" 
                                   class="btn btn-outline btn-sm" style="color: var(--wiki-blue);" title="Cần đăng nhập để tải bài viết này về máy">
                                    🔒 Tải về (Cần đăng nhập)
                                </a>
                            <?php endif; ?>

                            <!-- QUYỀN SỞ HỮU: NÚT XÓA HOẶC RÚT VỀ -->
                            <?php if ($isAuthor): ?>
                                <form method="POST" action="" style="display: inline;" onsubmit="return eduConfirmSubmit(this, event, 'Xóa bài viết này khỏi hệ thống?<br><small style=\'color:#b91c1c;\'>Thao tác không thể hoàn tác.</small>', 'Xóa Bài Viết', 'Xác nhận xóa');">
                                    <input type="hidden" name="action" value="delete_page">
                                    <input type="hidden" name="page_id" value="<?= $art['id'] ?>">
                                    <button type="submit" class="btn btn-outline btn-sm" style="color: var(--danger);" title="Xóa tài liệu">
                                        🗑️
                                    </button>
                                </form>
                            <?php endif; ?>
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
                return '/index.php?' . http_build_query($params);
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

<script>
function openSaveModal() {
    var m = document.getElementById('homeSaveModal');
    if (m) m.style.display = 'flex';
}
function closeSaveModal() {
    var m = document.getElementById('homeSaveModal');
    if (m) m.style.display = 'none';
}
window.addEventListener('click', function(e) {
    var m = document.getElementById('homeSaveModal');
    if (e.target === m) closeSaveModal();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeSaveModal();
});

function toggleHomeNewLib(val) {
    var row = document.getElementById('homeNewLibRow');
    if (val === 'new_lib') {
        row.style.display = 'block';
    } else {
        row.style.display = 'none';
    }
}

function handleManualHtmlChange(val) {
    var urlInp = document.getElementById('homeUrlInput');
    var badge = document.getElementById('homeUrlRequiredBadge');
    var note = document.getElementById('homeUrlNote');
    var preview = document.getElementById('homeHtmlTitlePreview');
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
        urlInp.placeholder = 'https://vi.wikipedia.org/... hoặc link bài viết, tin tức bất kỳ...';
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
    var ta = document.getElementById('homeManualHtmlInput');
    if (ta && ta.value.trim() !== '') {
        handleManualHtmlChange(ta.value);
    }
});
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
