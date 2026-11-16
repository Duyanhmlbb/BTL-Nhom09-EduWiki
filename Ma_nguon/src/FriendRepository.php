<?php
declare(strict_types=1);

class FriendRepository {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Lấy danh sách bạn bè đã chấp nhận kết nối của user
     */
    public function getFriends(int $userId): array {
        $sql = "
            SELECT u.id, u.username, u.fullname, u.role, f.id AS friendship_id, f.created_at AS friends_since
            FROM friendships f
            JOIN users u ON (CASE WHEN f.user_id = :uid THEN f.friend_id ELSE f.user_id END) = u.id
            WHERE (f.user_id = :uid OR f.friend_id = :uid)
              AND f.status = 'accepted'
            ORDER BY u.fullname ASC, u.username ASC
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Lấy danh sách lời mời kết bạn đang chờ duyệt (người khác gửi cho user)
     */
    public function getPendingRequests(int $userId): array {
        $sql = "
            SELECT f.id AS request_id, f.user_id AS sender_id, u.username, u.fullname, f.created_at
            FROM friendships f
            JOIN users u ON u.id = f.user_id
            WHERE f.friend_id = ? AND f.status = 'pending'
            ORDER BY f.created_at DESC
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Lấy danh sách lời mời mà user đã gửi đi đang chờ đối phương chấp nhận
     */
    public function getSentRequests(int $userId): array {
        $sql = "
            SELECT f.id AS request_id, f.friend_id AS receiver_id, u.username, u.fullname, f.created_at
            FROM friendships f
            JOIN users u ON u.id = f.friend_id
            WHERE f.user_id = ? AND f.status = 'pending'
            ORDER BY f.created_at DESC
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Tìm kiếm người dùng trong hệ thống để kết bạn
     */
    public function searchUsers(int $currentUserId, string $query): array {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $sql = "
            SELECT u.id, u.username, u.fullname,
                   f.id AS friendship_id, f.status AS friendship_status, f.user_id AS req_sender_id
            FROM users u
            LEFT JOIN friendships f ON (
                (f.user_id = :uid AND f.friend_id = u.id) OR
                (f.friend_id = :uid AND f.user_id = u.id)
            )
            WHERE u.id <> :uid
              AND (u.username ILIKE :q OR u.fullname ILIKE :q)
            ORDER BY u.username ASC
            LIMIT 20
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'uid' => $currentUserId,
            'q'   => '%' . $query . '%'
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Gửi yêu cầu kết bạn
     */
    public function sendFriendRequest(int $userId, int $targetUserId): bool {
        if ($userId === $targetUserId) {
            return false;
        }

        // Kiểm tra xem đã có quan hệ hay chưa
        $stmt = $this->db->prepare("
            SELECT id, status, user_id FROM friendships 
            WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)
        ");
        $stmt->execute([$userId, $targetUserId, $targetUserId, $userId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            if ($existing['status'] === 'rejected') {
                // Cho phép gửi lại nếu từng bị từ chối
                $up = $this->db->prepare("UPDATE friendships SET user_id = ?, friend_id = ?, status = 'pending', updated_at = NOW() WHERE id = ?");
                return $up->execute([$userId, $targetUserId, $existing['id']]);
            }
            return false; // Đã là bạn hoặc đang chờ
        }

        $insert = $this->db->prepare("INSERT INTO friendships (user_id, friend_id, status) VALUES (?, ?, 'pending')");
        return $insert->execute([$userId, $targetUserId]);
    }

    /**
     * Chấp nhận lời mời kết bạn
     */
    public function acceptFriendRequest(int $requestId, int $currentUserId): bool {
        $stmt = $this->db->prepare("
            UPDATE friendships 
            SET status = 'accepted', updated_at = NOW() 
            WHERE id = ? AND friend_id = ? AND status = 'pending'
        ");
        return $stmt->execute([$requestId, $currentUserId]) && $stmt->rowCount() > 0;
    }

    /**
     * Từ chối hoặc hủy kết bạn / thu hồi lời mời
     */
    public function removeFriendship(int $friendshipId, int $currentUserId): bool {
        $stmt = $this->db->prepare("
            DELETE FROM friendships 
            WHERE id = ? AND (user_id = ? OR friend_id = ?)
        ");
        return $stmt->execute([$friendshipId, $currentUserId, $currentUserId]) && $stmt->rowCount() > 0;
    }
}
