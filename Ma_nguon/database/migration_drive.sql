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

-- Bổ sung cột cho bảng libraries nếu chưa có
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'libraries' AND column_name = 'group_id') THEN
        ALTER TABLE libraries ADD COLUMN group_id INTEGER REFERENCES groups(id) ON DELETE CASCADE;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'libraries' AND column_name = 'allow_download') THEN
        ALTER TABLE libraries ADD COLUMN allow_download BOOLEAN NOT NULL DEFAULT TRUE;
    END IF;
END $$;

-- Sequence auto-increment
SELECT setval('friendships_id_seq', (SELECT GREATEST(MAX(id), 1) FROM friendships));
SELECT setval('groups_id_seq', (SELECT GREATEST(MAX(id), 1) FROM groups));
SELECT setval('group_members_id_seq', (SELECT GREATEST(MAX(id), 1) FROM group_members));
