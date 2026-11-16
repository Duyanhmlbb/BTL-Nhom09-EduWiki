<?php
declare(strict_types=1);

class GroupRepository {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Tạo nhóm mới và tự động gán người tạo làm Owner với toàn quyền
     */
    public function createGroup(string $name, string $description, int $ownerId): int {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Tên hội nhóm không được để trống.');
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                INSERT INTO groups (name, description, owner_id) 
                VALUES (?, ?, ?) RETURNING id
            ");
            $stmt->execute([$name, trim($description), $ownerId]);
            $groupId = (int)$stmt->fetchColumn();

            // Thêm chủ nhóm vào group_members với role owner & can_download = true
            $mStmt = $this->db->prepare("
                INSERT INTO group_members (group_id, user_id, role, can_download)
                VALUES (?, ?, 'owner', TRUE)
            ");
            $mStmt->execute([$groupId, $ownerId]);

            // Tự động tạo một thư viện chung đầu tiên cho nhóm
            $libStmt = $this->db->prepare("
                INSERT INTO libraries (group_id, user_id, name, access_level, allow_download)
                VALUES (?, ?, ?, 'group', TRUE)
            ");
            $libStmt->execute([$groupId, $ownerId, '📁 Tài liệu chung nhóm ' . $name]);

            $this->db->commit();
            return $groupId;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Lấy danh sách các nhóm mà người dùng tham gia (hoặc sở hữu)
     */
    public function getGroupsForUser(int $userId): array {
        $sql = "
            SELECT g.id, g.name, g.description, g.created_at, g.owner_id,
                   u.fullname AS owner_fullname, u.username AS owner_username,
                   gm.role AS my_role, gm.can_download AS my_can_download,
                   COUNT(DISTINCT gm2.user_id) AS member_count,
                   COUNT(DISTINCT l.id) AS library_count,
                   COUNT(DISTINCT p.id) AS page_count
            FROM groups g
            JOIN group_members gm ON gm.group_id = g.id AND gm.user_id = ?
            JOIN users u ON u.id = g.owner_id
            LEFT JOIN group_members gm2 ON gm2.group_id = g.id
            LEFT JOIN libraries l ON l.group_id = g.id
            LEFT JOIN saved_pages p ON p.library_id = l.id
            GROUP BY g.id, g.name, g.description, g.created_at, g.owner_id,
                     u.fullname, u.username, gm.role, gm.can_download
            ORDER BY g.created_at DESC
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Lấy thông tin chi tiết một nhóm kèm quyền của user hiện tại
     */
    public function getGroup(int $groupId, ?int $userId = null): ?array {
        $sql = "
            SELECT g.id, g.name, g.description, g.created_at, g.owner_id,
                   u.fullname AS owner_fullname, u.username AS owner_username
            FROM groups g
            JOIN users u ON u.id = g.owner_id
            WHERE g.id = ?
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$groupId]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$group) {
            return null;
        }

        $group['my_role'] = null;
        $group['my_can_download'] = false;

        if ($userId !== null) {
            $mStmt = $this->db->prepare("
                SELECT role, can_download FROM group_members 
                WHERE group_id = ? AND user_id = ?
            ");
            $mStmt->execute([$groupId, $userId]);
            $member = $mStmt->fetch(PDO::FETCH_ASSOC);
            if ($member) {
                $group['my_role'] = $member['role'];
                $group['my_can_download'] = (bool)$member['can_download'];
            }
        }

        return $group;
    }

    /**
     * Lấy danh sách thành viên trong nhóm kèm quyền hạn
     */
    public function getGroupMembers(int $groupId): array {
        $sql = "
            SELECT gm.id AS member_record_id, gm.group_id, gm.user_id,
                   gm.role, gm.can_download, gm.joined_at,
                   u.username, u.fullname, u.role AS global_role
            FROM group_members gm
            JOIN users u ON u.id = gm.user_id
            WHERE gm.group_id = ?
            ORDER BY 
                CASE gm.role
                    WHEN 'owner' THEN 1
                    WHEN 'admin' THEN 2
                    WHEN 'editor' THEN 3
                    ELSE 4
                END,
                u.username ASC
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$groupId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Thêm thành viên vào nhóm
     */
    public function addMember(int $groupId, int $userId, string $role = 'viewer', bool $canDownload = true): bool {
        $validRoles = ['editor', 'viewer', 'admin'];
        if (!in_array($role, $validRoles, true)) {
            $role = 'viewer';
        }

        $stmt = $this->db->prepare("
            INSERT INTO group_members (group_id, user_id, role, can_download)
            VALUES (?, ?, ?, ?)
            ON CONFLICT (group_id, user_id) 
            DO UPDATE SET role = EXCLUDED.role, can_download = EXCLUDED.can_download
        ");
        return $stmt->execute([$groupId, $userId, $role, $canDownload ? 'true' : 'false']);
    }

    /**
     * Cập nhật quyền của thành viên (Viewer / Editor / Admin và can_download)
     */
    public function updateMemberPermission(int $groupId, int $targetUserId, string $role, bool $canDownload, int $currentUserId): bool {
        // Kiểm tra quyền của người sửa: phải là owner hoặc admin của nhóm
        $actorPerm = $this->getUserPermission($groupId, $currentUserId);
        if (!$actorPerm || !in_array($actorPerm['role'], ['owner', 'admin'], true)) {
            return false;
        }

        // Không cho sửa role của owner chính
        $targetPerm = $this->getUserPermission($groupId, $targetUserId);
        if ($targetPerm && $targetPerm['role'] === 'owner') {
            return false;
        }

        // Admin thường không thể sửa quyền của admin khác (chỉ owner mới có quyền)
        if ($actorPerm['role'] === 'admin' && $targetPerm && $targetPerm['role'] === 'admin' && $targetUserId !== $currentUserId) {
            return false;
        }

        $validRoles = ['editor', 'viewer', 'admin'];
        if (!in_array($role, $validRoles, true)) {
            $role = 'viewer';
        }

        // Admin thường không thể tự phong admin cho người khác (chỉ owner mới phong admin)
        if ($actorPerm['role'] === 'admin' && $role === 'admin' && (!$targetPerm || $targetPerm['role'] !== 'admin')) {
            $role = 'editor';
        }

        $stmt = $this->db->prepare("
            UPDATE group_members 
            SET role = ?, can_download = ?
            WHERE group_id = ? AND user_id = ?
        ");
        return $stmt->execute([$role, $canDownload ? 'true' : 'false', $groupId, $targetUserId]);
    }

    /**
     * Xóa thành viên khỏi nhóm:
     * - Admin hoặc Chủ nhóm (owner) có quyền kick thành viên (hoặc tự rời nhóm).
     * - Khi kick, toàn bộ bài viết của người bị kick trong nhóm sẽ bị xóa sạch khỏi thư viện nhóm.
     */
    public function removeMember(int $groupId, int $targetUserId, int $currentUserId): bool {
        // Tự rời nhóm hoặc admin/owner đuổi thành viên khác
        if ($targetUserId !== $currentUserId) {
            $actorPerm = $this->getUserPermission($groupId, $currentUserId);
            if (!$actorPerm || !in_array($actorPerm['role'], ['owner', 'admin'], true)) {
                return false;
            }

            // Không cho kick owner
            $targetPerm = $this->getUserPermission($groupId, $targetUserId);
            if ($targetPerm && $targetPerm['role'] === 'owner') {
                return false;
            }

            // Admin thường không được đuổi admin khác (chỉ owner mới có quyền)
            if ($actorPerm['role'] === 'admin' && $targetPerm && $targetPerm['role'] === 'admin') {
                return false;
            }
        } else {
            // Chủ nhóm không thể tự rời nhóm nếu chưa xóa nhóm hoặc chuyển quyền
            $selfPerm = $this->getUserPermission($groupId, $currentUserId);
            if ($selfPerm && $selfPerm['role'] === 'owner') {
                return false;
            }
        }

        $this->db->beginTransaction();
        try {
            // Xóa toàn bộ bài viết mà người này đã đăng vào các thư viện thuộc nhóm
            $stmtDelPages = $this->db->prepare("
                DELETE FROM saved_pages 
                WHERE user_id = ? AND library_id IN (
                    SELECT id FROM libraries WHERE group_id = ?
                )
            ");
            $stmtDelPages->execute([$targetUserId, $groupId]);

            // Xóa phân quyền file riêng lẻ của user này trong nhóm
            $stmtDelPerms = $this->db->prepare("
                DELETE FROM group_page_permissions 
                WHERE user_id = ? AND page_id IN (
                    SELECT p.id FROM saved_pages p
                    JOIN libraries l ON l.id = p.library_id
                    WHERE l.group_id = ?
                )
            ");
            $stmtDelPerms->execute([$targetUserId, $groupId]);

            // Xóa thành viên khỏi nhóm
            $stmt = $this->db->prepare("DELETE FROM group_members WHERE group_id = ? AND user_id = ?");
            $stmt->execute([$groupId, $targetUserId]);

            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Xóa bài viết trong nhóm: Chỉ chủ nhóm (owner), admin hoặc tác giả bài viết mới có quyền xóa
     */
    public function deleteGroupPage(int $groupId, int $pageId, int $currentUserId): bool {
        $stmtCheck = $this->db->prepare("
            SELECT p.id, p.user_id AS author_id
            FROM saved_pages p
            JOIN libraries l ON l.id = p.library_id
            WHERE p.id = ? AND l.group_id = ?
        ");
        $stmtCheck->execute([$pageId, $groupId]);
        $page = $stmtCheck->fetch(PDO::FETCH_ASSOC);
        if (!$page) {
            return false;
        }

        $perm = $this->getUserPermission($groupId, $currentUserId);
        $isGroupAdmin = ($perm && in_array($perm['role'], ['owner', 'admin'], true));
        $isAuthor = ((int)$page['author_id'] === $currentUserId);

        if (!$isGroupAdmin && !$isAuthor) {
            return false;
        }

        $stmtDel = $this->db->prepare("DELETE FROM saved_pages WHERE id = ?");
        return $stmtDel->execute([$pageId]);
    }

    /**
     * Lấy phân quyền chi tiết từng thành viên cho một bài viết trong nhóm
     */
    public function getPagePermissions(int $pageId): array {
        $stmt = $this->db->prepare("
            SELECT gpp.user_id, gpp.can_view, gpp.can_edit, gpp.can_download,
                   u.username, u.fullname
            FROM group_page_permissions gpp
            JOIN users u ON u.id = gpp.user_id
            WHERE gpp.page_id = ?
        ");
        $stmt->execute([$pageId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $r) {
            $result[(int)$r['user_id']] = [
                'can_view' => (bool)$r['can_view'],
                'can_edit' => (bool)$r['can_edit'],
                'can_download' => (bool)$r['can_download'],
                'username' => $r['username'],
                'fullname' => $r['fullname'],
            ];
        }
        return $result;
    }

    /**
     * Thiết lập quyền xem, chỉnh sửa, tải về cho bài viết của từng thành viên
     * Chỉ tác giả bài viết hoặc chủ nhóm mới được chỉnh sửa
     */
    public function setPagePermissions(int $groupId, int $pageId, array $permissionsByUserId, int $currentUserId): bool {
        $stmtCheck = $this->db->prepare("
            SELECT p.user_id AS author_id
            FROM saved_pages p
            JOIN libraries l ON l.id = p.library_id
            WHERE p.id = ? AND l.group_id = ?
        ");
        $stmtCheck->execute([$pageId, $groupId]);
        $authorId = $stmtCheck->fetchColumn();
        if ($authorId === false) {
            return false;
        }

        $perm = $this->getUserPermission($groupId, $currentUserId);
        $isGroupAdmin = ($perm && in_array($perm['role'], ['owner', 'admin'], true));
        $isAuthor = ((int)$authorId === $currentUserId);

        if (!$isGroupAdmin && !$isAuthor) {
            return false;
        }

        $this->db->beginTransaction();
        try {
            $stmtUpsert = $this->db->prepare("
                INSERT INTO group_page_permissions (page_id, user_id, can_view, can_edit, can_download)
                VALUES (?, ?, ?, ?, ?)
                ON CONFLICT (page_id, user_id) 
                DO UPDATE SET 
                    can_view = EXCLUDED.can_view,
                    can_edit = EXCLUDED.can_edit,
                    can_download = EXCLUDED.can_download
            ");

            foreach ($permissionsByUserId as $uId => $p) {
                $targetUId = (int)$uId;
                if ($targetUId <= 0) continue;
                $cView = !empty($p['can_view']) ? 'true' : 'false';
                $cEdit = !empty($p['can_edit']) ? 'true' : 'false';
                $cDownload = !empty($p['can_download']) ? 'true' : 'false';

                $stmtUpsert->execute([$pageId, $targetUId, $cView, $cEdit, $cDownload]);
            }

            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Lấy quyền cụ thể của một user trong nhóm
     */
    public function getUserPermission(int $groupId, int $userId): ?array {
        $stmt = $this->db->prepare("
            SELECT role, can_download 
            FROM group_members 
            WHERE group_id = ? AND user_id = ?
        ");
        $stmt->execute([$groupId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $role = (string)$row['role'];
        return [
            'role' => $role,
            'can_download' => (bool)$row['can_download'],
            'is_owner' => ($role === 'owner'),
            'is_admin' => in_array($role, ['owner', 'admin'], true),
            'is_editor' => in_array($role, ['owner', 'admin', 'editor'], true),
            'is_viewer' => true,
        ];
    }

    /**
     * Lấy danh sách các thư viện thuộc về một nhóm
     */
    public function getGroupLibraries(int $groupId): array {
        $sql = "
            SELECT l.id, l.name, l.access_level, l.allow_download, l.created_at,
                   COUNT(p.id) AS page_count
            FROM libraries l
            LEFT JOIN saved_pages p ON p.library_id = l.id
            WHERE l.group_id = ?
            GROUP BY l.id, l.name, l.access_level, l.allow_download, l.created_at
            ORDER BY l.id ASC
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$groupId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Tạo thêm một thư viện mới bên trong nhóm
     */
    public function createGroupLibrary(int $groupId, string $name, int $userId, bool $allowDownload = true): int {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Tên thư viện nhóm không được để trống.');
        }

        // Kiểm tra quyền: phải là owner, admin, hoặc editor
        $perm = $this->getUserPermission($groupId, $userId);
        if (!$perm || !$perm['is_editor']) {
            throw new RuntimeException('Bạn không có quyền tạo thư viện trong nhóm này.');
        }

        $stmt = $this->db->prepare("
            INSERT INTO libraries (group_id, user_id, name, access_level, allow_download)
            VALUES (?, ?, ?, 'group', ?) RETURNING id
        ");
        $stmt->execute([$groupId, $userId, $name, $allowDownload ? 'true' : 'false']);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Xóa nhóm (chỉ dành cho Owner hoặc Global Admin)
     */
    public function deleteGroup(int $groupId, int $userId, bool $isGlobalAdmin = false): bool {
        if (!$isGlobalAdmin) {
            $perm = $this->getUserPermission($groupId, $userId);
            if (!$perm || !$perm['is_owner']) {
                return false;
            }
        }

        $stmt = $this->db->prepare("DELETE FROM groups WHERE id = ?");
        return $stmt->execute([$groupId]);
    }

    /**
     * Đổi tên thư viện trong nhóm (chỉ dành cho Owner, Admin hoặc Editor)
     */
    public function renameGroupLibrary(int $groupId, int $libId, string $newName, int $userId): bool {
        $newName = trim($newName);
        if ($newName === '' || $libId <= 0) {
            return false;
        }

        $perm = $this->getUserPermission($groupId, $userId);
        if (!$perm || !$perm['is_editor']) {
            return false;
        }

        $stmt = $this->db->prepare("UPDATE libraries SET name = ? WHERE id = ? AND group_id = ?");
        return $stmt->execute([$newName, $libId, $groupId]) && $stmt->rowCount() > 0;
    }

    /**
     * Xóa thư viện trong nhóm (chỉ dành cho Owner, Admin hoặc Editor)
     */
    public function deleteGroupLibrary(int $groupId, int $libId, int $userId): bool {
        if ($libId <= 0) {
            return false;
        }

        $perm = $this->getUserPermission($groupId, $userId);
        if (!$perm || !$perm['is_editor']) {
            return false;
        }

        // Xóa các bài viết bên trong thư viện trước
        $stmtPages = $this->db->prepare("SELECT id FROM saved_pages WHERE library_id = ?");
        $stmtPages->execute([$libId]);
        $pageIds = $stmtPages->fetchAll(PDO::FETCH_COLUMN);

        foreach ($pageIds as $pid) {
            $this->deleteGroupPage($groupId, (int)$pid, $userId);
        }

        $stmt = $this->db->prepare("DELETE FROM libraries WHERE id = ? AND group_id = ?");
        return $stmt->execute([$libId, $groupId]) && $stmt->rowCount() > 0;
    }
}
