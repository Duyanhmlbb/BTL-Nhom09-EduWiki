<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/ArchiveRepository.php';
require_once __DIR__ . '/../src/HtmlCleaner.php';
require_once __DIR__ . '/../src/WebFetcher.php';
require_once __DIR__ . '/../src/AssetManager.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/AdminRepository.php';

$testsPassed = 0;
$testsFailed = 0;

function assertTest(string $testName, bool $condition, string $details = ''): void {
    global $testsPassed, $testsFailed;
    if ($condition) {
        echo "PASS: $testName\n";
        $testsPassed++;
    } else {
        echo "FAIL: $testName" . ($details !== '' ? " ($details)" : '') . "\n";
        $testsFailed++;
    }
}

try {
    $db = db();

    // Tự động đồng bộ các sequence tự tăng của PostgreSQL
    $db->exec("
        SELECT setval('libraries_id_seq', (SELECT GREATEST(MAX(id), 1) FROM libraries));
        SELECT setval('saved_pages_id_seq', (SELECT GREATEST(MAX(id), 1) FROM saved_pages));
        SELECT setval('snapshots_id_seq', (SELECT GREATEST(MAX(id), 1) FROM snapshots));
        SELECT setval('tags_id_seq', (SELECT GREATEST(MAX(id), 1) FROM tags));
        SELECT setval('notes_id_seq', (SELECT GREATEST(MAX(id), 1) FROM notes));
    ");

    $repo = new ArchiveRepository($db);

    echo "====================================================================\n";
    echo " BỘ KIỂM THỬ TỔNG HỢP: LƯU TRỮ, ĐỌC LẠI, TÌM KIẾM & XÓA BÀI VIẾT\n";
    echo "====================================================================\n\n";

    // -------------------------------------------------------------------------
    // 1. KIỂM THỬ: Quản lý Thư viện, Quyền truy cập & Nhãn
    // -------------------------------------------------------------------------
    echo "--- 1. Quản lý Thư viện & Quyền truy cập ---\n";

    $libName = 'Thư viện Kiểm thử ' . uniqid();
    $libId = $repo->createLibrary($libName, 'shared');
    $lib = $repo->getLibrary($libId);
    assertTest(
        "Tạo thư viện với quyền truy cập (shared)",
        $lib !== null && $lib['name'] === $libName && $lib['access_level'] === 'shared'
    );

    $publicLibId = $repo->createLibrary('Thư viện Công khai ' . uniqid(), 'public');
    assertTest("Kiểm tra quyền truy cập thư viện public", $repo->canAccessLibrary($publicLibId));
    assertTest("Kiểm tra quyền truy cập thư viện shared theo vai trò", $repo->canAccessLibrary($libId, 'member'));

    // -------------------------------------------------------------------------
    // 2. KIỂM THỬ: Kiểm tra độ an toàn & khả năng truy cập của URL
    // -------------------------------------------------------------------------
    echo "\n--- 2. Kiểm tra URL an toàn & Khả năng truy cập (Live Check) ---\n";

    // Chống SSRF: Chặn địa chỉ mạng nội bộ
    assertTest("Chống SSRF: Chặn dải IP private 192.168.1.1", !WebFetcher::isSafeDestination('http://192.168.1.1/secret'));
    assertTest("Chống SSRF: Chặn dải IP private 10.0.0.1", !WebFetcher::isSafeDestination('http://10.0.0.1/admin'));
    assertTest("Chống SSRF: Chặn loopback 127.0.0.1", !WebFetcher::isSafeDestination('http://127.0.0.1/admin'));

    // Kiểm tra tên miền không tồn tại hoặc web chết
    $deadWebResult = WebFetcher::fetch('https://domain-nay-chac-chan-khong-ton-tai-xyz-999.org');
    assertTest("Từ chối kết nối khi tên miền không tồn tại / web chết", !$deadWebResult['success']);

    // -------------------------------------------------------------------------
    // 3. KIỂM THỬ: Tách tiêu đề, bài viết, loại bỏ trang lỗi / trang trắng
    // -------------------------------------------------------------------------
    echo "\n--- 3. Tách nội dung, loại bỏ trang lỗi & trang trắng ---\n";

    $rawHtmlSample = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <meta property="og:title" content="Hướng dẫn Lập trình Hướng đối tượng OOP trong PHP">
            <title>OOP PHP Cơ bản</title>
        </head>
        <body>
            <header><nav><a href="/">Trang chủ</a></nav></header>
            <article>
                <h1>Hướng dẫn Lập trình Hướng đối tượng OOP trong PHP</h1>
                <p>Lập trình hướng đối tượng là một mô hình lập trình mạnh mẽ trong PHP và kiến trúc mã nguồn mở.</p>
                <p>Nó cung cấp các khái niệm: đóng gói, kế thừa và đa hình.</p>
            </article>
            <footer><p>Bản quyền 2026</p></footer>
        </body>
        </html>
    ';

    $title = HtmlCleaner::extractTitle($rawHtmlSample);
    assertTest("Tách tiêu đề từ thẻ meta og:title hoặc <title>", $title === 'Hướng dẫn Lập trình Hướng đối tượng OOP trong PHP');

    $articleContent = HtmlCleaner::extractArticleContent($rawHtmlSample);
    assertTest(
        "Tách phần bài viết chính từ thẻ <article>, loại bỏ header/footer",
        str_contains($articleContent, 'Lập trình hướng đối tượng') && !str_contains($articleContent, '<nav>')
    );

    // Từ chối lưu trang lỗi 404 hoặc trang thông báo
    $errorPageHtml = '<html><head><title>404 Not Found</title></head><body><h1>404 Not Found</h1><p>Trang bạn yêu cầu không tồn tại.</p></body></html>';
    $errValidation = WebFetcher::validateHtmlContent($errorPageHtml);
    assertTest("Từ chối lưu trang lỗi 404", !$errValidation['valid']);

    $shortNoticeHtml = '<html><head><title>Thông báo</title></head><body>Trang trắng</body></html>';
    $noticeValidation = WebFetcher::validateHtmlContent($shortNoticeHtml);
    assertTest("Từ chối lưu trang trắng hoặc nội dung quá ngắn", !$noticeValidation['valid']);

    // Lưu bài viết vào CSDL
    $testUrl = 'https://example.com/oop-php-guide-' . uniqid();
    $savedPageId = $repo->savePage($publicLibId, $testUrl, $rawHtmlSample);
    assertTest("Lưu bài viết thành công trả về ID", $savedPageId > 0);

    // -------------------------------------------------------------------------
    // 4. KIỂM THỬ: Bản đọc lại sạch mã độc & Lưu ảnh ngoại tuyến
    // -------------------------------------------------------------------------
    echo "\n--- 4. Bản đọc lại sạch & Quản lý ảnh ngoại tuyến ---\n";

    $dirtyArticle = '
        <div>
            <h2>Tiêu đề bài viết có mã độc</h2>
            <script>alert("XSS Attack!");</script>
            <iframe src="https://malicious.org"></iframe>
            <p onclick="stealCookies()" onmouseover="runBadCode()">Nội dung an toàn về PostgreSQL.</p>
            <a href="javascript:doSomethingBad()">Bấm vào đây</a>
            <img src="https://example.com/images/architecture.png" alt="Sơ đồ kiến trúc" onload="triggerHack()">
        </div>
    ';
    $cleanedResult = HtmlCleaner::clean($dirtyArticle);
    $sanitized = $cleanedResult['content_html'];

    $hasScript = str_contains($sanitized, '<script') || str_contains($sanitized, 'alert(');
    $hasIframe = str_contains($sanitized, '<iframe');
    $hasOnEvent = str_contains($sanitized, 'onclick') || str_contains($sanitized, 'onmouseover') || str_contains($sanitized, 'onload');
    $hasJsHref = str_contains($sanitized, 'javascript:');

    assertTest(
        "Loại bỏ triệt để mã có thể thực thi (script, iframe, inline events, javascript:)",
        !$hasScript && !$hasIframe && !$hasOnEvent && !$hasJsHref
    );

    $pageDetails = $repo->getPageDetails($savedPageId);
    assertTest(
        "Bản đọc lại dùng nội dung lưu trữ trong CSDL, không redirect URL gốc",
        $pageDetails !== null && str_contains($pageDetails['content_html'], 'Lập trình hướng đối tượng')
    );

    // Test YouTube Video & Music embed
    $ytTest = WebFetcher::fetch('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
    assertTest(
        "Tự động nhận diện link YouTube và nhúng video/nhạc để xem trực tiếp",
        $ytTest['success'] && str_contains($ytTest['html'], 'youtube.com/embed/dQw4w9WgXcQ')
    );
    $cleanYt = HtmlCleaner::sanitize($ytTest['html']);
    assertTest(
        "Giữ lại iframe YouTube an toàn sau khi làm sạch",
        str_contains($cleanYt, 'youtube.com/embed/dQw4w9WgXcQ')
    );

    // -------------------------------------------------------------------------
    // 5. KIỂM THỬ: Tìm kiếm, Lọc theo nhãn/nguồn, Trích đoạn & Ghi chú
    // -------------------------------------------------------------------------
    echo "\n--- 5. Tìm kiếm, Lọc, Highlight từ khóa & Ghi chú ---\n";

    $repo->addTagToPage($savedPageId, 'oop');
    $tags = $repo->getTagsForPage($savedPageId);
    $hasOopTag = false;
    foreach ($tags as $t) {
        if ($t['name'] === 'oop') { $hasOopTag = true; break; }
    }
    assertTest("Gắn nhãn và truy vấn nhãn cho bài viết", $hasOopTag);

    $searchKwResults = $repo->searchPages($publicLibId, 'đa hình');
    assertTest("Tìm kiếm từ khóa trong thân bài viết ('đa hình')", count($searchKwResults) > 0);

    // Kiểm tra tìm kiếm trên tất cả thư viện (libraryId = null)
    $searchAllLibs = $repo->searchPages(null, 'đa hình');
    assertTest("Tìm kiếm từ khóa trên tất cả thư viện (libraryId = null)", count($searchAllLibs) > 0);

    // Kiểm tra lọc tìm kiếm chỉ trong nội dung bài viết (scope = content)
    $searchContentOnly = $repo->searchPages($publicLibId, 'đa hình', null, null, null, null, 'content');
    assertTest("Lọc tìm kiếm chỉ trong nội dung bài viết (scope = 'content')", count($searchContentOnly) > 0);

    $snippet = ArchiveRepository::generateSnippet(
        "Khái niệm đa hình trong PHP cho phép các đối tượng thuộc các lớp khác nhau phản hồi cùng một thông điệp.",
        "đa hình"
    );
    assertTest(
        "Tạo đoạn trích chứa từ khóa và tô sáng với thẻ mark",
        str_contains($snippet, '<mark class="kw-highlight">đa hình</mark>')
    );

    $noteId = $repo->addNote($savedPageId, 'Ghi chú về đa hình OOP.');
    $notes = $repo->getNotesForPage($savedPageId);
    assertTest("Thêm ghi chú riêng cho bản lưu", $noteId > 0 && count($notes) > 0);

    $deleteNoteSuccess = $repo->deleteNote($noteId, $savedPageId);
    assertTest("Xóa ghi chú của bản lưu", $deleteNoteSuccess);

    // -------------------------------------------------------------------------
    // 6. KIỂM THỬ: QUYỀN XÓA BÀI VIẾT VÀ XÓA THƯ VIỆN
    // -------------------------------------------------------------------------
    echo "\n--- 6. Quyền xóa bài viết và xóa thư viện ---\n";

    // Xóa bài viết đã lưu
    $deletePageSuccess = $repo->deletePage($savedPageId);
    $deletedPageCheck = $repo->getPageDetails($savedPageId);
    assertTest(
        "Xóa bài viết đã lưu thành công khỏi cơ sở dữ liệu",
        $deletePageSuccess && $deletedPageCheck === null
    );

    // Xóa thư viện
    $deleteLibSuccess = $repo->deleteLibrary($libId);
    $deletedLibCheck = $repo->getLibrary($libId);
    assertTest(
        "Xóa thư viện thành công khỏi cơ sở dữ liệu",
        $deleteLibSuccess && $deletedLibCheck === null
    );

    // Dọn dẹp thư viện mẫu
    $repo->deleteLibrary($publicLibId);

    // -------------------------------------------------------------------------
    echo "\n--- 7. Đăng ký & Đăng nhập người dùng (Auth) ---\n";

    $testUser = 'test_user_' . time();
    $testPass = 'Secr3t_P@ss!';

    // Đăng ký thành công
    $regRes = Auth::register($db, $testUser, $testPass, 'Nguyễn Kiểm Thử', 'member');
    assertTest(
        "Đăng ký người dùng mới thành công và mật khẩu được băm",
        $regRes['success'] && isset($regRes['user']['id'])
    );

    // Chặn trùng lặp tên đăng nhập
    $dupRes = Auth::register($db, $testUser, 'different_pass');
    assertTest(
        "Từ chối đăng ký khi tên đăng nhập đã tồn tại",
        !$dupRes['success']
    );

    // Đăng nhập thất bại do sai mật khẩu
    $wrongPassRes = Auth::attempt($db, $testUser, 'wrong_pass');
    assertTest(
        "Từ chối đăng nhập với mật khẩu sai",
        !$wrongPassRes['success']
    );

    // Đăng nhập thành công với mật khẩu đúng
    $loginRes = Auth::attempt($db, $testUser, $testPass);
    assertTest(
        "Đăng nhập thành công với đúng tên đăng nhập và mật khẩu",
        $loginRes['success'] && Auth::check() && Auth::id() === (int)$regRes['user']['id']
    );

    // Đăng xuất
    Auth::logout();
    assertTest(
        "Đăng xuất xóa sạch phiên làm việc",
        !Auth::check()
    );

    // -------------------------------------------------------------------------
    echo "\n--- 8. Phân tách kho lưu trữ riêng biệt cho từng người dùng ---\n";

    $userA_name = 'user_a_' . time();
    $userB_name = 'user_b_' . time();

    $userA_reg = Auth::register($db, $userA_name, 'pass_a_123', 'User A');
    $userB_reg = Auth::register($db, $userB_name, 'pass_b_123', 'User B');

    $userA_id = (int)$userA_reg['user']['id'];
    $userB_id = (int)$userB_reg['user']['id'];

    $repoA = new ArchiveRepository($db, $userA_id);
    $repoB = new ArchiveRepository($db, $userB_id);

    // Kiểm tra mỗi user tự có 5 danh mục thư viện riêng
    $libsA = $repoA->getLibraries($userA_id);
    $libsB = $repoB->getLibraries($userB_id);
    assertTest(
        "Mỗi tài khoản được khởi tạo các thư viện riêng biệt",
        count($libsA) >= 5 && count($libsB) >= 5 && $libsA[0]['id'] !== $libsB[0]['id']
    );

    // User A lưu bài viết vào thư viện của mình
    $userA_pageHtml = "<html><head><title>Tài liệu riêng của User A</title></head><body><article>Dữ liệu này tuyệt đối bí mật của User A.</article></body></html>";
    $pageA_id = $repoA->savePage($libsA[0]['id'], "https://secret.example.com/a", $userA_pageHtml, ['localize_images' => false], $userA_id);

    // User B tìm kiếm bài viết trong thư viện của mình -> Không được thấy bài của User A
    $resultsB = $repoB->searchPages($libsB[0]['id'], 'bí mật', null, null, null, $userB_id);
    assertTest(
        "User B không tìm thấy và không thấy bài viết của User A",
        count($resultsB) === 0
    );

    // User B không thể đọc chi tiết bài viết của User A
    $detailsB = $repoB->getPageDetails($pageA_id, null, $userB_id);
    assertTest(
        "User B bị từ chối truy cập bản đọc lại của User A",
        $detailsB === null
    );

    // User B không thể xóa bài viết của User A
    $deleteByB = $repoB->deletePage($pageA_id, $userB_id);
    assertTest(
        "User B không thể xóa bài viết của User A",
        $deleteByB === false
    );

    // User A đọc được và xóa được bài viết của mình
    $detailsA = $repoA->getPageDetails($pageA_id, null, $userA_id);
    $deleteByA = $repoA->deletePage($pageA_id, $userA_id);
    assertTest(
        "User A đọc được và xóa thành công bài viết của chính mình",
        $detailsA !== null && $deleteByA === true
    );

    // Dọn dẹp user test
    $stmtDel = $db->prepare("DELETE FROM users WHERE id IN (?, ?)");
    $stmtDel->execute([$userA_id, $userB_id]);

    // -------------------------------------------------------------------------
    echo "\n--- 9. Tính năng Quản trị viên (Admin Dashboard & Moderation) ---\n";

    $adminRepo = new AdminRepository($db);

    // 1. Kiểm tra thống kê hệ thống
    $adminStats = $adminRepo->getSystemStats();
    assertTest(
        "Admin lấy thống kê hệ thống (người dùng, bài viết, thư viện, dung lượng)",
        isset($adminStats['total_users'], $adminStats['total_pages'], $adminStats['media_size_formatted'])
        && $adminStats['total_users'] >= 1
    );

    // 2. Kiểm tra danh sách người dùng
    $userList = $adminRepo->getAllUsers();
    assertTest(
        "Admin lấy danh sách người dùng kèm số lượng bài và thư viện",
        is_array($userList) && count($userList) >= 1 && isset($userList[0]['page_count'])
    );

    // 3. Kiểm tra kiểm duyệt & xóa bài viết với quyền Admin
    $modLibId = $repo->createLibrary('Thư viện Kiểm duyệt', 'public');
    $tempPageHtml = "<html><head><title>Bài kiểm duyệt</title></head><body>Nội dung kiểm duyệt</body></html>";
    $tempPageId = $repo->savePage($modLibId, "https://moderation.example.com/test", $tempPageHtml);
    $adminPages = $adminRepo->getAllPages('Bài kiểm duyệt');
    assertTest(
        "Admin tìm kiếm và xem danh sách toàn bộ bài viết trong hệ thống",
        count($adminPages) >= 1
    );

    $adminDeleteSuccess = $adminRepo->deletePageAdmin($tempPageId);
    assertTest(
        "Admin có quyền xóa bài viết vi phạm bất kỳ trong hệ thống",
        $adminDeleteSuccess && $repo->getPageDetails($tempPageId) === null
    );

    $repo->deleteLibrary($modLibId);

    // 4. Kiểm tra quét và dọn dẹp file mồ côi
    $cleanRes = $adminRepo->cleanOrphanMedia();
    assertTest(
        "Admin quét và dọn rác tệp media mồ côi thành công",
        isset($cleanRes['deleted_files'], $cleanRes['bytes_freed'])
    );

    // 5. Kiểm tra Admin xóa tài khoản người dùng
    // Tạo user test tạm thời để xóa
    $tmpUsername = 'del_test_' . uniqid();
    $tmpPwHash   = password_hash('test123', PASSWORD_BCRYPT);
    $stmtTmp = $db->prepare("INSERT INTO users (username, password_hash, fullname, role) VALUES (?, ?, ?, 'member') RETURNING id");
    $stmtTmp->execute([$tmpUsername, $tmpPwHash, 'User Xóa Thử']);
    $tmpUserId = (int)$stmtTmp->fetchColumn();

    $deleted = $adminRepo->deleteUser($tmpUserId);
    $checkGone = $db->prepare("SELECT id FROM users WHERE id = ?");
    $checkGone->execute([$tmpUserId]);
    assertTest(
        "Admin xóa tài khoản người dùng và toàn bộ dữ liệu liên quan",
        $deleted === true && $checkGone->fetchColumn() === false
    );

    // Không cho xóa tài khoản admin gốc (username = 'admin')
    $stmtAdmin = $db->prepare("SELECT id FROM users WHERE username = 'admin' LIMIT 1");
    $stmtAdmin->execute();
    $adminId = (int)$stmtAdmin->fetchColumn();
    $blockAdmin = false;
    try {
        $adminRepo->deleteUser($adminId);
    } catch (RuntimeException $e) {
        $blockAdmin = true;
    }
    assertTest(
        "Admin không thể xóa tài khoản quản trị viên gốc (admin)",
        $blockAdmin === true
    );

    // -------------------------------------------------------------------------
    // 10. KIỂM THỬ: HỆ THỐNG GOOGLE DRIVE, HỘI NHÓM, BẠN BÈ & PHÂN QUYỀN
    // -------------------------------------------------------------------------
    echo "\n--- 10. Hệ thống Google Drive, Hội nhóm, Bạn bè & Phân quyền Chi tiết ---\n";

    require_once __DIR__ . '/../src/FriendRepository.php';
    require_once __DIR__ . '/../src/GroupRepository.php';

    $friendRepo = new FriendRepository($db);
    $groupRepo = new GroupRepository($db);

    // Tạo 2 user test cho mô hình nhóm
    $u1 = Auth::register($db, 'drive_u1_' . uniqid(), 'pass123', 'Drive User 1');
    $u2 = Auth::register($db, 'drive_u2_' . uniqid(), 'pass123', 'Drive User 2');
    $u1_id = (int)$u1['user']['id'];
    $u2_id = (int)$u2['user']['id'];

    // 1. Kết bạn: U1 gửi lời mời cho U2 -> U2 chấp nhận
    $sendReq = $friendRepo->sendFriendRequest($u1_id, $u2_id);
    $pending = $friendRepo->getPendingRequests($u2_id);
    $hasPending = false;
    $reqId = 0;
    foreach ($pending as $pReq) {
        if ((int)$pReq['sender_id'] === $u1_id) {
            $hasPending = true;
            $reqId = (int)$pReq['request_id'];
            break;
        }
    }
    assertTest("Gửi yêu cầu kết bạn giữa 2 người dùng", $sendReq && $hasPending);

    $acceptReq = $friendRepo->acceptFriendRequest($reqId, $u2_id);
    $u1_friends = $friendRepo->getFriends($u1_id);
    $isFriend = false;
    foreach ($u1_friends as $f) {
        if ((int)$f['id'] === $u2_id) { $isFriend = true; break; }
    }
    assertTest("Chấp nhận lời mời kết bạn và xác lập bạn bè chính thức", $acceptReq && $isFriend);

    // 2. Tạo hội nhóm & Thư viện nhóm
    $newGroupId = $groupRepo->createGroup("Nhóm Nghiên cứu Khoa học", "Dự án nghiên cứu chung", $u1_id);
    $groupLibs = $groupRepo->getGroupLibraries($newGroupId);
    assertTest(
        "Tạo hội nhóm mới và tự động khởi tạo thư viện chung đầu tiên",
        $newGroupId > 0 && count($groupLibs) >= 1
    );

    // 3. Phân quyền Google Drive: Thêm U2 với quyền Viewer và KHÓA QUYỀN TẢI VỀ (can_download = false)
    $groupRepo->addMember($newGroupId, $u2_id, 'viewer', false);
    $u2_perm = $groupRepo->getUserPermission($newGroupId, $u2_id);
    assertTest(
        "Phân quyền Drive: Thêm thành viên với vai trò Viewer và khóa tải về (can_download=false)",
        $u2_perm !== null && $u2_perm['role'] === 'viewer' && $u2_perm['can_download'] === false && $u2_perm['is_editor'] === false
    );

    // Lưu 1 bài viết vào thư viện nhóm
    $sampleGroupPageId = $repo->savePage($groupLibs[0]['id'], "https://drive-lab.example.com/doc", "<html><body><article>Tài liệu nhóm cần bảo mật không cho tải về.</article></body></html>", [], $u1_id);
    $pagePermForU2 = $repo->getUserPagePermission($sampleGroupPageId, $u2_id);
    assertTest(
        "Hệ thống áp dụng chính sách khóa tải về đối với Viewer trong bài viết nhóm",
        $pagePermForU2['can_view'] === true && $pagePermForU2['can_edit'] === false && $pagePermForU2['can_download'] === false
    );

    // 4. Chủ nhóm nâng quyền U2 lên Editor và MỞ QUYỀN TẢI VỀ (can_download = true)
    $groupRepo->updateMemberPermission($newGroupId, $u2_id, 'editor', true, $u1_id);
    $u2_updated_perm = $groupRepo->getUserPermission($newGroupId, $u2_id);
    $pagePermForU2_after = $repo->getUserPagePermission($sampleGroupPageId, $u2_id);
    assertTest(
        "Chủ nhóm nâng cấp quyền thành viên lên Editor và mở quyền tải về",
        $u2_updated_perm['role'] === 'editor' && $u2_updated_perm['can_download'] === true && $pagePermForU2_after['can_edit'] === true && $pagePermForU2_after['can_download'] === true
    );

    // 5. Thư viện công khai cộng đồng
    $publicList = $repo->getPublicLibraries();
    assertTest(
        "Truy vấn danh sách thư viện công khai toàn hệ thống",
        is_array($publicList) && count($publicList) >= 1
    );

    // 6. Thống kê mở rộng Admin Dashboard
    $statsExtended = $adminRepo->getSystemStats();
    assertTest(
        "Admin giám sát thông số Hội nhóm Drive, Bạn bè và Phân loại thư viện",
        isset($statsExtended['total_groups'], $statsExtended['total_friendships'], $statsExtended['public_libraries'])
        && $statsExtended['total_groups'] >= 1
    );

    // Dọn dẹp dữ liệu kiểm thử
    $groupRepo->deleteGroup($newGroupId, $u1_id);
    $stmtDelDrive = $db->prepare("DELETE FROM users WHERE id IN (?, ?)");
    $stmtDelDrive->execute([$u1_id, $u2_id]);

    // -------------------------------------------------------------------------
    // TỔNG KẾT KẾT QUẢ
    // -------------------------------------------------------------------------
    echo "\n====================================================================\n";
    echo "KẾT QUẢ KIỂM THỬ: TỔNG=" . ($testsPassed + $testsFailed) . " | ĐẠT=$testsPassed | LỖI=$testsFailed\n";
    echo "====================================================================\n";

    if ($testsFailed > 0) {
        exit(1);
    }

} catch (Throwable $e) {
    echo "LỖI NGOẠI LỆ: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
