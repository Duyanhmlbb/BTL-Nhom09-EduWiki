-- Dọn sạch toàn bộ dữ liệu mẫu, chỉ để lại khung hệ thống và Thư viện chung
BEGIN;

-- 1. Xóa toàn bộ dữ liệu bài viết, snapshots, hình ảnh, ghi chú, nhãn
TRUNCATE TABLE assets CASCADE;
TRUNCATE TABLE notes CASCADE;
TRUNCATE TABLE page_tags CASCADE;
TRUNCATE TABLE tags CASCADE;
TRUNCATE TABLE group_page_permissions CASCADE;
TRUNCATE TABLE snapshots CASCADE;
TRUNCATE TABLE saved_pages CASCADE;

-- 2. Xóa dữ liệu nhóm và bạn bè (để người dùng tự tạo và mời bạn bè như thực tế)
TRUNCATE TABLE friendships CASCADE;
TRUNCATE TABLE group_members CASCADE;
TRUNCATE TABLE groups CASCADE;

-- 3. Xóa các thư viện cũ, chỉ giữ lại duy nhất 1 "Thư viện chung"
TRUNCATE TABLE libraries CASCADE;
INSERT INTO libraries (id, name, access_level, allow_download, user_id, group_id)
VALUES (1, '🌐 Thư viện chung', 'public', TRUE, NULL, NULL);

-- 4. Giữ lại tài khoản admin hệ thống để đăng nhập quản trị nếu cần (password: admin123)
-- Xóa các tài khoản test tạm thời nếu có
DELETE FROM users WHERE username NOT IN ('admin');

INSERT INTO users (username, password_hash, fullname, role) VALUES 
('admin', '$2y$12$mZPW54fn8gvVdY9.EcbVr.DgPR36YSAXo0yjSlPxKz0YCgZeTvAk2', 'Quản trị viên Hệ thống', 'admin')
ON CONFLICT (username) DO UPDATE SET role = EXCLUDED.role;

-- 5. Reset các Sequence
SELECT setval('libraries_id_seq', (SELECT GREATEST(MAX(id), 1) FROM libraries));
SELECT setval('saved_pages_id_seq', 1, false);
SELECT setval('snapshots_id_seq', 1, false);
SELECT setval('assets_id_seq', 1, false);
SELECT setval('tags_id_seq', 1, false);
SELECT setval('notes_id_seq', 1, false);
SELECT setval('users_id_seq', (SELECT GREATEST(MAX(id), 1) FROM users));
SELECT setval('groups_id_seq', 1, false);
SELECT setval('group_members_id_seq', 1, false);
SELECT setval('friendships_id_seq', 1, false);
SELECT setval('group_page_permissions_id_seq', 1, false);

COMMIT;
