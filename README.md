# BÀI TẬP LỚN PHÁT TRIỂN PHẦN MỀM MÃ NGUỒN MỞ

# Đề tài 16: Xây dựng ứng dụng lưu nội dung trang Web để đọc lại và tìm kiếm (EduWiki / Wiki Archiver)

**Nhóm thực hiện:** Nhóm 9  
**Thành viên:**
1. **Phạm Duy Anh** (Nhóm trưởng)
2. **Đỗ Nam Khánh**
3. **Nguyễn Trung Đức**

---

## 1. Giới thiệu Đề tài & Bối cảnh

Trong nghiên cứu và học tập, người dùng thường có nhu cầu lưu trữ lại các bài viết hướng dẫn, tài liệu chuyên ngành để đọc lại sau này. Tuy nhiên, đường dẫn liên kết (URL) trên Internet có thể bị thay đổi, trang gốc bị gỡ bỏ, hoặc người dùng cần truy cập khi không có kết nối Internet.

**EduWiki (Wiki Archiver)** là giải pháp lưu trữ tài liệu web ngoại tuyến toàn diện, cho phép:
- Lưu nội dung bài viết gốc, bảo lưu nguồn gốc và thời điểm lấy tin.
- Tự động tách tiêu đề, bài viết chính, loại bỏ quảng cáo, header, footer và các trang lỗi.
- Làm sạch triệt để các mã độc có thể thực thi (XSS, script, iframe nguy hiểm).
- Tải và lưu trữ ảnh ngoại tuyến, nhúng Base64 để bản lưu có thể mở đọc 100% khi tắt mạng.
- Tìm kiếm từ khóa đa phạm vi (trong tiêu đề, thân bài), lọc theo nhãn/thư viện và tô sáng từ khóa.
- Quản lý đa phiên bản (Snapshots), lưu giữ các bản cũ kèm ghi chú nghiên cứu riêng biệt.
- Mô hình chia sẻ thư viện nhóm cộng tác (Google Drive thu nhỏ) với phân quyền chi tiết (Viewer, Editor, Owner) và kiểm soát quyền tải về tệp (HTML, PDF, Word).

---

## 2. Hướng dẫn Khởi chạy Ứng dụng & Kiểm thử

### Các bước khởi chạy:
```bash
# 1. Chạy ứng dụng web
bash lab.sh run

# 2. Mở trình duyệt tại địa chỉ
http://127.0.0.1:8105

# 3. Chạy toàn bộ 50 bài kiểm thử tự động
bash lab.sh test
```
**Kết quả kiểm thử:** Đạt **50/50 PASS (LỖI = 0)**.

Xem chi tiết trong thư mục `Ma_nguon/README.md`.
