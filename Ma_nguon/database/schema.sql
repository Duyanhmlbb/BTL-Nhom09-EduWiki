-- Bảng Người dùng (Tài khoản, Xác thực & Phân quyền)
CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    fullname VARCHAR(100) NOT NULL DEFAULT '',
    role VARCHAR(20) NOT NULL DEFAULT 'member',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Bảng Hội nhóm (Groups / Workspaces)
CREATE TABLE IF NOT EXISTS groups (
    id SERIAL PRIMARY KEY,
    owner_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    name VARCHAR(150) NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Bảng Thành viên Nhóm & Phân quyền Drive
CREATE TABLE IF NOT EXISTS group_members (
    id SERIAL PRIMARY KEY,
    group_id INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role VARCHAR(20) NOT NULL DEFAULT 'viewer', -- 'owner', 'admin', 'editor', 'viewer'
    can_download BOOLEAN NOT NULL DEFAULT TRUE,
    joined_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT unique_group_user UNIQUE (group_id, user_id)
);

-- Bảng Bạn bè (Friendships)
CREATE TABLE IF NOT EXISTS friendships (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    friend_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    status VARCHAR(20) NOT NULL DEFAULT 'pending', -- 'pending', 'accepted', 'rejected'
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT unique_friendship UNIQUE (user_id, friend_id),
    CONSTRAINT chk_no_self_friend CHECK (user_id <> friend_id)
);

-- Bảng Phân quyền từng file/bài viết trong nhóm (Google Drive file sharing)
CREATE TABLE IF NOT EXISTS group_page_permissions (
    id SERIAL PRIMARY KEY,
    page_id INTEGER NOT NULL REFERENCES saved_pages(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    can_view BOOLEAN NOT NULL DEFAULT TRUE,
    can_edit BOOLEAN NOT NULL DEFAULT FALSE,
    can_download BOOLEAN NOT NULL DEFAULT TRUE,
    CONSTRAINT unique_group_page_user UNIQUE (page_id, user_id)
);

-- Bảng Thư viện (Quản lý quyền truy cập và nhóm tài liệu)
CREATE TABLE IF NOT EXISTS libraries (
    id SERIAL PRIMARY KEY,
    user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
    group_id INTEGER REFERENCES groups(id) ON DELETE CASCADE,
    name VARCHAR(150) NOT NULL,
    access_level VARCHAR(30) NOT NULL DEFAULT 'private',
    allow_download BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Bảng Trang web đã lưu (Nguồn gốc, URL gốc)
CREATE TABLE IF NOT EXISTS saved_pages (
    id SERIAL PRIMARY KEY,
    user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
    library_id INTEGER NOT NULL REFERENCES libraries(id) ON DELETE CASCADE,
    url TEXT NOT NULL,
    title VARCHAR(255) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Bảng Phiên bản nội dung (Snapshot: Giữ bản cũ và bản mới khi cập nhật)
CREATE TABLE IF NOT EXISTS snapshots (
    id SERIAL PRIMARY KEY,
    saved_page_id INTEGER NOT NULL REFERENCES saved_pages(id) ON DELETE CASCADE,
    version_number INTEGER NOT NULL DEFAULT 1,
    content_html TEXT NOT NULL,
    content_text TEXT NOT NULL, -- Dùng để tìm kiếm từ khóa trong thân bài
    fetched_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Bảng Tài nguyên cục bộ (Ảnh, tệp hỗ trợ mở bản lưu không phụ thuộc trang gốc)
CREATE TABLE IF NOT EXISTS assets (
    id SERIAL PRIMARY KEY,
    snapshot_id INTEGER NOT NULL REFERENCES snapshots(id) ON DELETE CASCADE,
    original_url TEXT NOT NULL,
    local_path VARCHAR(255) NOT NULL
);

-- Bảng Nhãn (Tags) và liên kết
CREATE TABLE IF NOT EXISTS tags (
    id SERIAL PRIMARY KEY,
    name VARCHAR(50) UNIQUE NOT NULL
);

CREATE TABLE IF NOT EXISTS page_tags (
    saved_page_id INTEGER NOT NULL REFERENCES saved_pages(id) ON DELETE CASCADE,
    tag_id INTEGER NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
    PRIMARY KEY (saved_page_id, tag_id)
);

-- Bảng Ghi chú riêng cho từng bản đã lưu
CREATE TABLE IF NOT EXISTS notes (
    id SERIAL PRIMARY KEY,
    saved_page_id INTEGER NOT NULL REFERENCES saved_pages(id) ON DELETE CASCADE,
    content TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Khởi tạo Thư viện chung hệ thống (ID=1, dùng làm thư viện công khai mặc định)
INSERT INTO libraries (id, name, access_level) VALUES 
(1, '🌐 Thư viện chung', 'shared')
ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name, access_level = EXCLUDED.access_level;

-- Khởi tạo tài khoản quản trị viên mặc định (admin / admin123)
INSERT INTO users (username, password_hash, fullname, role) VALUES 
('admin', '$2y$12$mZPW54fn8gvVdY9.EcbVr.DgPR36YSAXo0yjSlPxKz0YCgZeTvAk2', 'Quản trị viên Hệ thống', 'admin')
ON CONFLICT (username) DO NOTHING;

-- Đồng bộ lại sequence tự tăng cho các bảng
SELECT setval('libraries_id_seq', (SELECT GREATEST(MAX(id), 1) FROM libraries));
SELECT setval('saved_pages_id_seq', (SELECT GREATEST(MAX(id), 1) FROM saved_pages));
SELECT setval('snapshots_id_seq', (SELECT GREATEST(MAX(id), 1) FROM snapshots));
SELECT setval('tags_id_seq', (SELECT GREATEST(MAX(id), 1) FROM tags));
SELECT setval('notes_id_seq', (SELECT GREATEST(MAX(id), 1) FROM notes));
SELECT setval('users_id_seq', (SELECT GREATEST(MAX(id), 1) FROM users));
SELECT setval('groups_id_seq', (SELECT GREATEST(MAX(id), 1) FROM groups));
SELECT setval('group_members_id_seq', (SELECT GREATEST(MAX(id), 1) FROM group_members));
SELECT setval('friendships_id_seq', (SELECT GREATEST(MAX(id), 1) FROM friendships));
