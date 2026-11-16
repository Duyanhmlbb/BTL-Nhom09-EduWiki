<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

class AdminRepository {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Lấy các số liệu thống kê tổng quan của hệ thống
     */
    public function getSystemStats(): array {
        // 1. Thống kê số lượng bản ghi
        $usersCount = (int)$this->db->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $pagesCount = (int)$this->db->query("SELECT COUNT(*) FROM saved_pages")->fetchColumn();
        $libsCount = (int)$this->db->query("SELECT COUNT(*) FROM libraries")->fetchColumn();
        $notesCount = (int)$this->db->query("SELECT COUNT(*) FROM notes")->fetchColumn();

        // 2. Thống kê dung lượng media lưu trữ
        $mediaDir = dirname(__DIR__) . '/public/assets/saved_media';
        $mediaFileCount = 0;
        $mediaTotalBytes = 0;

        if (is_dir($mediaDir)) {
            $files = scandir($mediaDir);
            if ($files !== false) {
                foreach ($files as $file) {
                    if ($file !== '.' && $file !== '..') {
                        $fullPath = $mediaDir . '/' . $file;
                        if (is_file($fullPath)) {
                            $mediaFileCount++;
                            $mediaTotalBytes += (int)filesize($fullPath);
                        }
                    }
                }
            }
        }

        // 3. Top các tên miền được lưu nhiều nhất
        $stmtUrls = $this->db->query("SELECT url FROM saved_pages");
        $domainCounts = [];
        while ($url = $stmtUrls->fetchColumn()) {
            $host = parse_url((string)$url, PHP_URL_HOST);
            if ($host) {
                $host = preg_replace('/^www\./i', '', strtolower($host));
                $domainCounts[$host] = ($domainCounts[$host] ?? 0) + 1;
            }
        }
        arsort($domainCounts);
        $topDomains = array_slice($domainCounts, 0, 5, true);

        // 4. Thống kê mở rộng mô hình Drive: Hội nhóm, Bạn bè & Phân loại thư viện
        $groupsCount = (int)$this->db->query("SELECT COUNT(*) FROM groups")->fetchColumn();
        $groupMembersCount = (int)$this->db->query("SELECT COUNT(*) FROM group_members")->fetchColumn();
        $friendshipsCount = (int)$this->db->query("SELECT COUNT(*) FROM friendships WHERE status = 'accepted'")->fetchColumn();
        $publicLibsCount = (int)$this->db->query("SELECT COUNT(*) FROM libraries WHERE access_level = 'public'")->fetchColumn();
        $groupLibsCount = (int)$this->db->query("SELECT COUNT(*) FROM libraries WHERE access_level = 'group' OR group_id IS NOT NULL")->fetchColumn();
        $privateLibsCount = (int)$this->db->query("SELECT COUNT(*) FROM libraries WHERE access_level = 'private' AND group_id IS NULL")->fetchColumn();

        // 5. Danh sách thông số các hội nhóm
        $sqlGroups = "
            SELECT g.id, g.name, g.created_at, u.username AS owner_name,
                   COUNT(DISTINCT gm.user_id) AS member_count,
                   COUNT(DISTINCT l.id) AS library_count,
                   COUNT(DISTINCT p.id) AS page_count
            FROM groups g
            JOIN users u ON u.id = g.owner_id
            LEFT JOIN group_members gm ON gm.group_id = g.id
            LEFT JOIN libraries l ON l.group_id = g.id
            LEFT JOIN saved_pages p ON p.library_id = l.id
            GROUP BY g.id, g.name, g.created_at, u.username
            ORDER BY g.created_at DESC
        ";
        $allGroups = $this->db->query($sqlGroups)->fetchAll(PDO::FETCH_ASSOC);

        return [
            'total_users' => $usersCount,
            'total_pages' => $pagesCount,
            'total_libraries' => $libsCount,
            'total_notes' => $notesCount,
            'total_groups' => $groupsCount,
            'total_group_members' => $groupMembersCount,
            'total_friendships' => $friendshipsCount,
            'public_libraries' => $publicLibsCount,
            'group_libraries' => $groupLibsCount,
            'private_libraries' => $privateLibsCount,
            'groups_list' => $allGroups,
            'media_files' => $mediaFileCount,
            'media_bytes' => $mediaTotalBytes,
            'media_size_formatted' => self::formatBytes($mediaTotalBytes),
            'top_domains' => $topDomains,
        ];
    }

    /**
     * Lấy danh sách toàn bộ người dùng kèm số lượng bài viết và thư viện
     */
    public function getAllUsers(): array {
        $sql = "
            SELECT u.id, u.username, u.fullname, u.role, u.created_at,
                   COUNT(DISTINCT p.id) AS page_count,
                   COUNT(DISTINCT l.id) AS library_count
            FROM users u
            LEFT JOIN saved_pages p ON p.user_id = u.id
            LEFT JOIN libraries l ON l.user_id = u.id
            GROUP BY u.id, u.username, u.fullname, u.role, u.created_at
            ORDER BY u.id ASC
        ";
        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Lấy danh sách toàn bộ bài viết trên hệ thống để kiểm duyệt
     */
    public function getAllPages(?string $keyword = null, ?int $filterUserId = null, int $limit = 50): array {
        $params = [];
        $sql = "
            SELECT p.id, p.url, p.title, p.created_at, p.user_id,
                   u.username AS author_username, u.fullname AS author_fullname,
                   l.name AS library_name, l.access_level,
                   s.version_number, s.fetched_at,
                   (SELECT COUNT(*) FROM notes n WHERE n.saved_page_id = p.id) AS notes_count,
                   (SELECT COUNT(*) FROM assets a WHERE a.snapshot_id = s.id) AS assets_count
            FROM saved_pages p
            LEFT JOIN users u ON u.id = p.user_id
            LEFT JOIN libraries l ON l.id = p.library_id
            LEFT JOIN snapshots s ON s.saved_page_id = p.id 
                 AND s.version_number = (SELECT MAX(version_number) FROM snapshots WHERE saved_page_id = p.id)
            WHERE 1=1
        ";

        if ($filterUserId !== null) {
            $sql .= " AND p.user_id = ?";
            $params[] = $filterUserId;
        }

        if ($keyword !== null && trim($keyword) !== '') {
            $sql .= " AND (p.title ILIKE ? OR p.url ILIKE ?)";
            $params[] = '%' . trim($keyword) . '%';
            $params[] = '%' . trim($keyword) . '%';
        }

        $sql .= " ORDER BY p.id DESC LIMIT " . (int)$limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Xóa bài viết với quyền Quản trị viên (xóa sạch bài + file ảnh đính kèm)
     */
    public function deletePageAdmin(int $pageId): bool {
        // Dọn dẹp ảnh trên ổ đĩa
        $stmtAssets = $this->db->prepare("
            SELECT a.local_path 
            FROM assets a 
            JOIN snapshots s ON s.id = a.snapshot_id 
            WHERE s.saved_page_id = ?
        ");
        $stmtAssets->execute([$pageId]);
        $assets = $stmtAssets->fetchAll(PDO::FETCH_ASSOC);

        $publicDir = dirname(__DIR__) . '/public';
        foreach ($assets as $asset) {
            $filePath = $publicDir . $asset['local_path'];
            if (is_file($filePath)) {
                @unlink($filePath);
            }
        }

        $stmt = $this->db->prepare("DELETE FROM saved_pages WHERE id = ?");
        $stmt->execute([$pageId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Xóa tài khoản người dùng (kèm dọn dẹp thư viện, bài viết, và tệp ảnh liên quan)
     */
    public function deleteUser(int $userId): bool {
        // 1. Kiểm tra tài khoản tồn tại và không cho phép xóa tài khoản admin gốc
        $stmtCheck = $this->db->prepare("SELECT username, role FROM users WHERE id = ?");
        $stmtCheck->execute([$userId]);
        $targetUser = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$targetUser) {
            return false;
        }

        if ($targetUser['username'] === 'admin') {
            throw new RuntimeException('Không thể xóa tài khoản Quản trị viên mặc định (admin).');
        }

        // 2. Tìm tất cả các file ảnh của người dùng này để dọn dẹp trên ổ đĩa
        $stmtAssets = $this->db->prepare("
            SELECT a.local_path 
            FROM assets a
            JOIN snapshots s ON s.id = a.snapshot_id
            JOIN saved_pages p ON p.id = s.saved_page_id
            WHERE p.user_id = ?
        ");
        $stmtAssets->execute([$userId]);
        $assets = $stmtAssets->fetchAll(PDO::FETCH_ASSOC);

        $publicDir = dirname(__DIR__) . '/public';
        foreach ($assets as $asset) {
            $filePath = $publicDir . $asset['local_path'];
            if (is_file($filePath)) {
                @unlink($filePath);
            }
        }

        // 3. Xóa user khỏi bảng users (Khóa ngoại ON DELETE CASCADE sẽ xóa libraries, saved_pages, snapshots...)
        $stmtDel = $this->db->prepare("DELETE FROM users WHERE id = ?");
        $stmtDel->execute([$userId]);

        return $stmtDel->rowCount() > 0;
    }

    /**
     * Quét và dọn dẹp các tệp ảnh/media mồ côi (không còn bài viết nào trỏ đến)
     */
    public function cleanOrphanMedia(): array {
        $mediaDir = dirname(__DIR__) . '/public/assets/saved_media';
        if (!is_dir($mediaDir)) {
            return ['deleted_files' => 0, 'bytes_freed' => 0, 'freed_formatted' => '0 B'];
        }

        // Lấy danh sách tất cả file đang được ghi nhận hợp lệ trong CSDL
        $validPaths = $this->db->query("SELECT local_path FROM assets")->fetchAll(PDO::FETCH_COLUMN);
        $validBasenames = [];
        foreach ($validPaths as $path) {
            $validBasenames[basename((string)$path)] = true;
        }

        $deletedCount = 0;
        $bytesFreed = 0;
        $files = scandir($mediaDir);

        if ($files !== false) {
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                // Nếu file trên ổ đĩa KHÔNG có trong bảng assets -> file rác mồ côi
                if (!isset($validBasenames[$file])) {
                    $fullPath = $mediaDir . '/' . $file;
                    if (is_file($fullPath)) {
                        $size = (int)filesize($fullPath);
                        if (@unlink($fullPath)) {
                            $deletedCount++;
                            $bytesFreed += $size;
                        }
                    }
                }
            }
        }

        return [
            'deleted_files' => $deletedCount,
            'bytes_freed' => $bytesFreed,
            'freed_formatted' => self::formatBytes($bytesFreed),
        ];
    }

    /**
     * Kiểm tra xem user có phải super admin không (username = 'admin')
     */
    public function isSuperAdmin(int $userId): bool {
        $stmt = $this->db->prepare("SELECT username FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row && $row['username'] === 'admin';
    }

    /**
     * Nâng cấp user thành admin
     */
    public function promoteToAdmin(int $userId): bool {
        $stmtCheck = $this->db->prepare("SELECT username FROM users WHERE id = ?");
        $stmtCheck->execute([$userId]);
        $row = $stmtCheck->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;

        $stmt = $this->db->prepare("UPDATE users SET role = 'admin' WHERE id = ?");
        $stmt->execute([$userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Giáng cấp admin xuống thành viên (không cho phép giáng cấp super admin)
     */
    public function demoteToMember(int $userId): bool {
        // Không được giáng cấp super admin
        if ($this->isSuperAdmin($userId)) {
            throw new RuntimeException('Không thể giáng cấp Quản trị viên gốc (super admin).');
        }
        $stmt = $this->db->prepare("UPDATE users SET role = 'member' WHERE id = ?");
        $stmt->execute([$userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Định dạng kích thước byte ra KB, MB
     */
    public static function formatBytes(int $bytes): string {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }
}
