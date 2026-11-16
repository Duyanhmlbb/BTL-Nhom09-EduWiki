BEGIN;

-- Chuẩn hóa 8 Kho Môn học / Chuyên mục Học thuật chính
UPDATE libraries SET name = '📐 Toán học & Thống kê', access_level = 'public', allow_download = TRUE WHERE id = 1;
UPDATE libraries SET name = '💻 Công nghệ Thông tin & Lập trình', access_level = 'public', allow_download = TRUE WHERE id = 2;
UPDATE libraries SET name = '🔬 Khoa học Tự nhiên (Vật lý, Hóa, Sinh)', access_level = 'public', allow_download = TRUE WHERE id = 3;
UPDATE libraries SET name = '📚 Ngữ văn, Ngôn ngữ & Ngoại ngữ', access_level = 'public', allow_download = TRUE WHERE id = 4;
UPDATE libraries SET name = '🏛️ Lịch sử, Triết học & Xã hội', access_level = 'public', allow_download = TRUE WHERE id = 5;
UPDATE libraries SET name = '💼 Kinh tế, Tài chính & Quản trị', access_level = 'public', allow_download = TRUE WHERE id = 123;
UPDATE libraries SET name = '🏥 Y Dược & Khoa học Sức khỏe', access_level = 'public', allow_download = TRUE WHERE id = 124;
UPDATE libraries SET name = '🎓 Giáo trình, Bài giảng & Giáo án', access_level = 'public', allow_download = TRUE WHERE id = 125;

-- Xóa các thư viện rác/test không cần thiết nếu có
DELETE FROM libraries WHERE id NOT IN (1, 2, 3, 4, 5, 123, 124, 125) AND name LIKE '%test%';

COMMIT;
