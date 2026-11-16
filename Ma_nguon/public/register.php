<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../src/Auth.php';

$pdo = db();
$error = null;

// Nếu đã đăng nhập rồi thì chuyển hướng về trang chủ
if (Auth::check()) {
    header('Location: /index.php');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $fullname = trim($_POST['fullname'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $passwordConfirm = (string)($_POST['password_confirm'] ?? '');

    if ($password !== $passwordConfirm) {
        $error = 'Xác nhận mật khẩu không khớp. Vui lòng nhập lại.';
    } else {
        $result = Auth::register($pdo, $username, $password, $fullname, 'member');
        if ($result['success']) {
            // Tự động đăng nhập luôn sau khi đăng ký
            Auth::login($result['user']);
            header('Location: /index.php?msg=' . urlencode('Chào mừng ' . ($fullname !== '' ? $fullname : $username) . '! Tài khoản của bạn đã được đăng ký thành công.'));
            exit;
        } else {
            $error = $result['error'];
        }
    }
}

$pageTitle = 'Đăng ký tài khoản - Wiki Archiver';
require_once __DIR__ . '/../templates/header.php';
?>

<div style="max-width: 460px; margin: 40px auto; width: 100%;">
    <div class="card" style="padding: 32px 28px; box-shadow: var(--shadow-md); border-top: 4px solid var(--wiki-blue);">
        
        <!-- TIÊU ĐỀ & BIỂU TƯỢNG -->
        <div style="text-align: center; margin-bottom: 24px;">
            <div style="display: inline-flex; align-items: center; justify-content: center; width: 54px; height: 54px; background: var(--wiki-blue-light); color: var(--wiki-blue); border-radius: 50%; margin-bottom: 12px; font-size: 26px;">
                📝
            </div>
            <h2 style="margin: 0; font-size: 22px; font-weight: 700; color: var(--text-main);">
                Tạo Tài Khoản Mới
            </h2>
            <p style="color: var(--text-muted); font-size: 13.5px; margin: 6px 0 0;">
                Bắt đầu lưu trữ, quản lý và đọc lại tư liệu ngoại tuyến của riêng bạn
            </p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger" style="margin-bottom: 16px;">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group" style="margin-bottom: 16px;">
                <label class="form-label" for="username" style="font-weight: 600;">
                    Tên đăng nhập: <span style="color: #b32424;">*</span>
                </label>
                <input type="text" id="username" name="username" class="form-control" 
                       placeholder="Từ 3-30 ký tự (chữ cái, số, gạch dưới...)" 
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" 
                       required autofocus style="height: 40px;">
            </div>

            <div class="form-group" style="margin-bottom: 16px;">
                <label class="form-label" for="fullname" style="font-weight: 600;">
                    Họ và tên (Tùy chọn):
                </label>
                <input type="text" id="fullname" name="fullname" class="form-control" 
                       placeholder="VD: Nguyễn Văn A" 
                       value="<?= htmlspecialchars($_POST['fullname'] ?? '') ?>" 
                       style="height: 40px;">
            </div>

            <div class="form-group" style="margin-bottom: 16px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <label class="form-label" for="password" style="font-weight: 600; margin-bottom: 0;">
                        Mật khẩu: <span style="color: #b32424;">*</span>
                    </label>
                    <button type="button" onclick="togglePasswordVisibility('password', this)" style="background: none; border: none; font-size: 12px; color: var(--link-color); cursor: pointer; padding: 0;">
                        👁️ Hiện
                    </button>
                </div>
                <input type="password" id="password" name="password" class="form-control" 
                       placeholder="Tối thiểu 6 ký tự" required minlength="6" style="height: 40px;">
            </div>

            <div class="form-group" style="margin-bottom: 24px;">
                <label class="form-label" for="password_confirm" style="font-weight: 600;">
                    Xác nhận lại mật khẩu: <span style="color: #b32424;">*</span>
                </label>
                <input type="password" id="password_confirm" name="password_confirm" class="form-control" 
                       placeholder="Nhập lại chính xác mật khẩu ở trên" required minlength="6" style="height: 40px;">
            </div>

            <button type="submit" class="btn btn-wiki" style="width: 100%; height: 42px; font-size: 15px; font-weight: 600; color: #ffffff !important;">
                Hoàn tất đăng ký &rarr;
            </button>
        </form>

        <div style="text-align: center; margin-top: 22px; padding-top: 18px; border-top: 1px solid var(--wiki-border-light); font-size: 13.5px; color: var(--text-muted);">
            Đã có tài khoản? <a href="/login.php" style="color: var(--link-color); font-weight: 600;">Đăng nhập ngay</a>
        </div>
    </div>
</div>

<script>
function togglePasswordVisibility(inputId, btn) {
    var input = document.getElementById(inputId);
    if (!input) return;
    if (input.type === 'password') {
        input.type = 'text';
        btn.textContent = '🙈 Ẩn';
    } else {
        input.type = 'password';
        btn.textContent = '👁️ Hiện';
    }
}
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
