-- Chuyển ràng buộc ON DELETE CASCADE của reading_history sang ON DELETE SET NULL
-- và thêm cột page_title, page_url lưu lại tiêu đề để khi bài viết gốc bị xóa, lịch sử vẫn còn
ALTER TABLE reading_history ADD COLUMN IF NOT EXISTS page_title VARCHAR(255) DEFAULT '';
ALTER TABLE reading_history ADD COLUMN IF NOT EXISTS page_url TEXT DEFAULT '';

-- Cập nhật tiêu đề và url hiện tại cho các bản ghi lịch sử cũ
UPDATE reading_history rh
SET page_title = p.title, page_url = p.url
FROM saved_pages p
WHERE rh.page_id = p.id AND (rh.page_title IS NULL OR rh.page_title = '');

-- Tháo ràng buộc khóa ngoại CASCADE để khi xóa bài viết, lịch sử không bị xóa
DO $$
DECLARE
    r RECORD;
BEGIN
    FOR r IN (
        SELECT constraint_name 
        FROM information_schema.table_constraints 
        WHERE table_name = 'reading_history' AND constraint_type = 'FOREIGN KEY'
    ) LOOP
        IF r.constraint_name LIKE '%page_id%' OR r.constraint_name = 'reading_history_page_id_fkey' THEN
            EXECUTE 'ALTER TABLE reading_history DROP CONSTRAINT ' || quote_ident(r.constraint_name);
        END IF;
    END LOOP;
END $$;

-- Thêm lại khóa ngoại với ON DELETE SET NULL (hoặc để page_id trỏ tự do)
ALTER TABLE reading_history ALTER COLUMN page_id DROP NOT NULL;
ALTER TABLE reading_history ADD CONSTRAINT reading_history_page_id_fkey FOREIGN KEY (page_id) REFERENCES saved_pages(id) ON DELETE SET NULL;
