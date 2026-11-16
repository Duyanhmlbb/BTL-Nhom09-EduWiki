# HƯỚNG DẪN ĐÓNG GÓP MÃ NGUỒN (CONTRIBUTING GUIDE)

## Đề tài 16: Xây dựng ứng dụng lưu nội dung trang Web để đọc lại và tìm kiếm (EduWiki / Wiki Archiver)
**Nhóm thực hiện:** Nhóm 9  
**Thành viên:**
- Phạm Duy Anh (Nhóm trưởng)
- Đỗ Nam Khánh
- Nguyễn Trung Đức

Học phần: *Phát triển phần mềm mã nguồn mở*

---

Chúng tôi hoan nghênh và đánh giá cao mọi sự đóng góp mã nguồn nhằm hoàn thiện và phát triển dự án Wiki Archiver. Dưới đây là các quy ước và hướng dẫn tiêu chuẩn khi tham gia đóng góp.

---

## 1. Quy trình Đóng góp (Workflow)

1. **Tạo nhánh làm việc (Branching):**
   * Phân nhánh từ nhánh chính `main`.
   * Đặt tên nhánh rõ ràng theo mục đích:
     - Tính năng mới: `feature/ten-tinh-nang` (ví dụ: `feature/export-pdf`)
     - Sửa lỗi: `fix/ten-loi` (ví dụ: `fix/timezone-issue`)
     - Cải thiện tài liệu: `docs/cap-nhat-readme`

2. **Quy chuẩn lập trình (Coding Standards):**
   * Tuân thủ chuẩn lập trình PHP hiện đại (**PSR-12**).
   * Sử dụng kiểu dữ liệu nghiêm ngặt `declare(strict_types=1);` ở đầu các tệp PHP logic trong `src/`.
   * Luôn sử dụng **Prepared Statements** (`PDO::prepare()`) cho mọi truy vấn cơ sở dữ liệu để chống triệt để tấn công SQL Injection.
   * Xử lý dữ liệu đầu ra hiển thị trên HTML bằng hàm `htmlspecialchars()` để ngăn chặn lỗ hổng XSS.

3. **Kiểm thử tự động trước khi Commit (Testing):**
   * Trước khi tạo Pull Request, bắt buộc phải chạy bộ kiểm thử toàn diện:
     ```bash
     bash lab.sh test
     ```
   * Yêu cầu bắt buộc: Toàn bộ kiểm thử phải đạt kết quả **50/50 PASS (LỖI = 0)**.

4. **Quy ước Commit (Commit Messages):**
   * Thông điệp commit viết bằng tiếng Việt rõ ràng hoặc tiếng Anh chuẩn:
     - `feat: Bổ sung tính năng phân quyền tải về cho thành viên Viewer`
     - `fix: Khắc phục lỗi hiển thị ảnh ngoại tuyến khi chia sẻ bài vào nhóm`
     - `test: Bổ sung 15 trang HTML mẫu phục vụ kiểm chứng`

5. **Tạo Pull Request (PR):**
   * Mô tả tóm tắt những thay đổi đã thực hiện.
   * Đính kèm kết quả chạy kiểm thử `lab.sh test`.
   * Yêu cầu ít nhất 01 thành viên trong nhóm xem xét (Review) và chấp thuận trước khi gộp vào nhánh `main`.

---

## 2. Nguyên tắc An toàn & Bảo mật Dữ liệu

- **TUYỆT ĐỐI KHÔNG** đưa thông tin mật khẩu, chuỗi kết nối CSDL cá nhân hoặc thư mục cấu hình cục bộ `.local/` lên Git.
- Mọi dữ liệu kiểm thử phải là dữ liệu giả định (mock data).
- Báo cáo lỗi bảo mật xin vui lòng gửi trực tiếp cho Nhóm trưởng qua kênh liên lạc của lớp học phần.

---

*Cảm ơn bạn đã đóng góp xây dựng dự án EduWiki!*
