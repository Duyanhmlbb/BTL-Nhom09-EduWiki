<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../src/Auth.php';

$pdo = db();
$error = null;
$message = null;

// Hỗ trợ redirect sau khi đăng nhập
$redirect = $_GET['redirect'] ?? $_POST['redirect'] ?? '';
if (!empty($redirect) && !str_starts_with($redirect, '/')) {
    $redirect = '';
}

// Thông báo hệ thống
if (isset($_GET['msg'])) {
    $message = htmlspecialchars($_GET['msg']);
}

if (isset($_GET['registered'])) {
    $message = 'Đăng ký tài khoản thành công! Bạn có thể đăng nhập ngay bây giờ.';
}

// Nếu đã đăng nhập rồi thì chuyển hướng
if (Auth::check()) {
    if (Auth::role() === 'admin') {
        header('Location: /admin.php');
    } else {
        header('Location: ' . ($redirect ?: '/index.php'));
    }
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $redirect = $_POST['redirect'] ?? '';
    if (!empty($redirect) && !str_starts_with($redirect, '/')) {
        $redirect = '';
    }

    $result = Auth::attempt($pdo, $username, $password);
    if ($result['success']) {
        $loggedUser = $result['user'];
        // Tự động điều hướng theo quyền hạn người dùng
        if ($loggedUser['role'] === 'admin') {
            header('Location: /admin.php');
        } else {
            header('Location: ' . ($redirect ?: '/index.php'));
        }
        exit;
    } else {
        $error = $result['error'];
    }
}

$pageTitle = 'Đăng nhập - Wiki Archiver';
require_once __DIR__ . '/../templates/header.php';
?>

<div style="max-width: 440px; margin: 40px auto; width: 100%;">
    <div class="card" style="padding: 32px 28px; box-shadow: var(--shadow-md); border-top: 4px solid var(--wiki-blue);">
        
        <!-- TIÊU ĐỀ & BIỂU TƯỢNG -->
        <div style="text-align: center; margin-bottom: 24px;">
            <div style="display: inline-flex; align-items: center; justify-content: center; width: 54px; height: 54px; background: var(--wiki-blue-light); color: var(--wiki-blue); border-radius: 50%; margin-bottom: 12px; font-size: 26px;">
                📁
            </div>
            <h2 style="margin: 0; font-size: 22px; font-weight: 700; color: var(--text-main);">
                Đăng Nhập Tài Khoản
            </h2>
            <p style="color: var(--text-muted); font-size: 13.5px; margin: 6px 0 0;">
                Truy cập hệ thống lưu trữ và quản lý tư liệu của bạn
            </p>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success" style="margin-bottom: 16px;">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger" style="margin-bottom: 16px;">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">

            <div class="form-group" style="margin-bottom: 16px;">
                <label class="form-label" for="username" style="font-weight: 600;">
                    Tên đăng nhập:
                </label>
                <input type="text" id="username" name="username" class="form-control" 
                       placeholder="Nhập tên đăng nhập của bạn" 
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" 
                       required autofocus style="height: 40px;">
            </div>

            <div class="form-group" style="margin-bottom: 24px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <label class="form-label" for="password" style="font-weight: 600; margin-bottom: 0;">
                        Mật khẩu:
                    </label>
                    <button type="button" onclick="togglePasswordVisibility('password', this)" style="background: none; border: none; font-size: 12px; color: var(--link-color); cursor: pointer; padding: 0;">
                        👁️ Hiện mật khẩu
                    </button>
                </div>
                <input type="password" id="password" name="password" class="form-control" 
                       placeholder="Nhập mật khẩu" required style="height: 40px;">
            </div>

            <button type="submit" class="btn btn-wiki" style="width: 100%; height: 42px; font-size: 15px; font-weight: 600; color: #ffffff !important;">
                Đăng nhập ngay &rarr;
            </button>
        </form>

        <div style="text-align: center; margin-top: 22px; padding-top: 18px; border-top: 1px solid var(--wiki-border-light); font-size: 13.5px; color: var(--text-muted);">
            <div>
                Chưa có tài khoản? <a href="/register.php" style="color: var(--link-color); font-weight: 600;">Đăng ký tài khoản mới</a>
            </div>
        </div>
    </div>
</div>

<script>
function togglePasswordVisibility(inputId, btn) {
    var input = document.getElementById(inputId);
    if (!input) return;
    if (input.type === 'password') {
        input.type = 'text';
        btn.textContent = '🙈 Ẩn mật khẩu';
    } else {
        input.type = 'password';
        btn.textContent = '👁️ Hiện mật khẩu';
    }
}
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
