<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

class Auth {
    /**
     * Lấy thông tin người dùng đang đăng nhập
     */
    public static function user(): ?array {
        return $_SESSION['user'] ?? null;
    }

    /**
     * Kiểm tra người dùng đã đăng nhập chưa
     */
    public static function check(): bool {
        return isset($_SESSION['user']) && is_array($_SESSION['user']);
    }

    /**
     * Lấy ID người dùng hiện tại
     */
    public static function id(): ?int {
        return isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
    }

    /**
     * Lấy vai trò (role) của người dùng hiện tại (vd: admin, member)
     */
    public static function role(): ?string {
        return $_SESSION['user']['role'] ?? null;
    }

    /**
     * Lưu trạng thái đăng nhập vào Session
     */
    public static function login(array $user): void {
        $_SESSION['user'] = [
            'id' => (int)$user['id'],
            'username' => $user['username'],
            'fullname' => $user['fullname'] ?? '',
            'role' => $user['role'] ?? 'member',
        ];
    }

    /**
     * Đăng xuất, xóa thông tin phiên làm việc
     */
    public static function logout(): void {
        unset($_SESSION['user']);
    }

    /**
     * Đăng ký người dùng mới
     */
    public static function register(PDO $db, string $username, string $password, string $fullname = '', string $role = 'member'): array {
        $username = trim($username);
        $fullname = trim($fullname);

        if (strlen($username) < 3 || strlen($username) > 30) {
            return ['success' => false, 'error' => 'Tên đăng nhập phải từ 3 đến 30 ký tự.'];
        }

        if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
            return ['success' => false, 'error' => 'Tên đăng nhập chỉ được chứa chữ cái, số, dấu gạch dưới, gạch ngang hoặc dấu chấm.'];
        }

        if (strlen($password) < 6) {
            return ['success' => false, 'error' => 'Mật khẩu phải có ít nhất 6 ký tự.'];
        }

        // Kiểm tra xem tên đăng nhập đã tồn tại chưa
        $stmt = $db->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(?)");
        $stmt->execute([$username]);
        if ($stmt->fetchColumn()) {
            return ['success' => false, 'error' => 'Tên đăng nhập này đã được sử dụng. Vui lòng chọn tên khác.'];
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $role = in_array($role, ['admin', 'member'], true) ? $role : 'member';

        $stmtInsert = $db->prepare("
            INSERT INTO users (username, password_hash, fullname, role) 
            VALUES (?, ?, ?, ?) 
            RETURNING id, username, fullname, role, created_at
        ");
        $stmtInsert->execute([$username, $passwordHash, $fullname, $role]);
        $user = $stmtInsert->fetch(PDO::FETCH_ASSOC);

        return [
            'success' => true,
            'user' => $user,
        ];
    }

    /**
     * Xác thực đăng nhập
     */
    public static function attempt(PDO $db, string $username, string $password): array {
        $username = trim($username);

        if ($username === '' || $password === '') {
            return ['success' => false, 'error' => 'Vui lòng nhập đầy đủ tên đăng nhập và mật khẩu.'];
        }

        $stmt = $db->prepare("SELECT id, username, password_hash, fullname, role FROM users WHERE LOWER(username) = LOWER(?)");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return ['success' => false, 'error' => 'Tên đăng nhập hoặc mật khẩu không chính xác.'];
        }

        self::login($user);

        return [
            'success' => true,
            'user' => $user,
        ];
    }
}
