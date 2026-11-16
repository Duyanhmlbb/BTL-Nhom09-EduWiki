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

## 2. Kiến trúc Mã nguồn & Bàn giao

Dự án tuân thủ mô hình phân lớp rõ ràng, tách bạch giữa xử lý logic, giao diện và cơ sở dữ liệu:

```
Ma_nguon/
├── config/              # Cấu hình kết nối CSDL PostgreSQL (hỗ trợ múi giờ Asia/Ho_Chi_Minh GMT+7)
├── database/            # Schema và các tệp migration CSDL quan hệ
│   └── schema.sql       # Cấu trúc các bảng: users, groups, libraries, saved_pages, snapshots, assets, notes...
├── public/              # Giao diện người dùng web (Web Root)
│   ├── assets/          # CSS, JavaScript và thư mục lưu ảnh ngoại tuyến (/assets/saved_media/)
│   ├── mock_pages/      # Danh mục 15 trang HTML mẫu kiểm chứng (/mock_pages/index.php)
│   ├── index.php        # Trang chủ: Tổng hợp bài viết, tìm kiếm, lưu link URL
│   ├── view.php         # Trình đọc bài viết ngoại tuyến, quản lý phiên bản, ghi chú, đánh giá, báo cáo
│   ├── libraries.php    # Quản lý kho thư viện cá nhân và thư viện nhóm
│   ├── group_detail.php # Chi tiết hội nhóm, phân quyền thành viên và chia sẻ tài liệu
│   ├── friends.php      # Hệ thống kết bạn và quản lý danh sách bạn bè
│   ├── history.php      # Lịch sử truy cập bài viết cá nhân
│   └── admin.php        # Trang quản trị: Thống kê hệ thống, kiểm duyệt và quản lý tài khoản
├── src/                 # Logic nghiệp vụ hướng đối tượng (Nhóm tự phát triển)
│   ├── ArchiveRepository.php # Quản lý lưu trữ, trích xuất, phiên bản, tìm kiếm và xuất file
│   ├── HtmlCleaner.php       # Bộ lọc làm sạch HTML, tách tiêu đề, thân bài, chống XSS
│   ├── WebFetcher.php        # Trình tải web an toàn, chống SSRF, kiểm tra DNS/Loopback
│   ├── AssetManager.php      # Tải và quản lý tệp hình ảnh/âm thanh ngoại tuyến
│   ├── GroupRepository.php   # Nghiệp vụ hội nhóm, phân quyền Drive (Viewer/Editor/Owner)
│   ├── FriendRepository.php  # Nghiệp vụ kết bạn
│   ├── AdminRepository.php   # Quản trị viên, dọn dẹp file rác mồ côi
│   └── Auth.php              # Xác thực người dùng, băm mật khẩu bcrypt
├── templates/           # Khung giao diện dùng chung (header.php, footer.php, modal dialogs)
├── tests/               # Bộ kiểm thử tự động toàn diện
│   └── archive_test.php # 50 bài kiểm thử tích hợp (đạt 50/50 PASS)
├── lab.sh               # Kịch bản dòng lệnh quản lý: check, run, test
├── CONTRIBUTING.md      # Quy chuẩn và hướng dẫn đóng góp mã nguồn mở
├── LICENSE              # Giấy phép mã nguồn mở MIT
└── README.md            # Tài liệu tổng quan đề tài và hướng dẫn sử dụng
```

### Bảng phân định mã nguồn:
| Thành phần | Nguồn gốc | Mô tả chi tiết |
| :--- | :---: | :--- |
| **Logic nghiệp vụ (`src/`)** | **Nhóm tự phát triển 100%** | Toàn bộ cơ chế bóc tách HTML, làm sạch mã độc, quản lý snapshot đa phiên bản, tải ảnh ngoại tuyến, thuật toán tìm kiếm và phân quyền Drive. |
| **Giao diện (`public/`, `templates/`)** | **Nhóm tự phát triển** | Thiết kế giao diện thân thiện chuẩn tiếng Việt, hệ thống Modal xác nhận tùy biến, thanh công cụ đọc bài và xuất file. |
| **Cơ sở dữ liệu (`database/`)** | **Nhóm tự thiết kế** | 12 bảng chuẩn hóa quan hệ PostgreSQL với các ràng buộc khóa ngoại `CASCADE`, chỉ mục tăng tốc tìm kiếm. |
| **Thư viện bên thứ ba** | Thư viện nguồn mở | Sử dụng mở rộng PDO PHP chuẩn (`pdo_pgsql`) và font chữ hệ thống. |

---

## 3. Hướng dẫn Cài đặt, Chạy Ứng dụng & Kiểm thử

### Yêu cầu môi trường
- Hệ điều hành: Linux / Ubuntu / WSL2 (Windows Subsystem for Linux).
- PHP $\ge$ 8.1 kèm các phần mở rộng: `php-cli`, `php-pgsql`, `php-mbstring`, `php-curl`.
- PostgreSQL $\ge$ 14.

### Các bước khởi chạy:

1. **Cấu hình cơ sở dữ liệu:**
   Chạy lệnh cấu hình CSDL tương tác để kết nối PostgreSQL:
   ```bash
   bash lab.sh db-config
   ```

2. **Khởi động máy chủ ứng dụng web:**
   ```bash
   bash lab.sh run
   ```
   Ứng dụng sẽ chạy tại địa chỉ: **`http://127.0.0.1:8105`**

3. **Chạy bộ kiểm thử tự động toàn diện (50 Phép thử):**
   Mở terminal thứ hai tại thư mục dự án và thực hiện:
   ```bash
   bash lab.sh test
   ```
   **Kết quả chuẩn:** `KẾT QUẢ KIỂM THỬ: TỔNG=50 | ĐẠT=50 | LỖI=0 (100% PASS)`.

---

## 4. Danh mục 15 Trang HTML Mẫu Kiểm Thử (Mock Pages)

Để phục vụ kiểm chứng các tình huống đặc thù theo yêu cầu của đề tài, nhóm đã xây dựng sẵn 15 trang HTML mẫu tại đường dẫn: **`http://127.0.0.1:8105/mock_pages/`**.

| STT | Tệp HTML mẫu | Mục đích kiểm thử theo Đề bài | Kết quả mong đợi khi lưu |
| :---: | :--- | :--- | :--- |
| 1 | `page01_oop_guide.html` | Bài viết kỹ thuật chuẩn có cấu trúc `<article>`, mã nguồn. | Bóc tách tiêu đề, nội dung sạch sẽ; lưu thành công. |
| 2 | `page02_database_guide.html` | Bài viết cơ sở dữ liệu có chứa code block và ảnh SVG. | Lưu bài và tải ảnh logo về máy chủ thành công. |
| 3 | `page03_clean_code.html` | Bài viết quy chuẩn Clean Code & Refactoring. | Tách nội dung chính, loại bỏ header/footer. |
| 4 | `page04_web_security.html` | Bài viết an toàn thông tin (SQLi, CSRF, bcrypt). | Bóc tách và hiển thị định dạng code an toàn. |
| 5 | `page05_duplicate_title_part1.html` | **Trùng tiêu đề (P1):** Tiêu đề *"Kiến trúc Phát triển Web Hiện đại"*, nội dung Frontend. | Lưu thành công vào thư viện độc lập. |
| 6 | `page06_duplicate_title_part2.html` | **Trùng tiêu đề (P2):** Tiêu đề *"Kiến trúc Phát triển Web Hiện đại"*, nội dung Backend. | Lưu độc lập, không bị ghi đè bài 5; tìm kiếm phân biệt rõ. |
| 7 | `page07_offline_images.html` | **Đa ảnh ngoại tuyến:** Chứa 3 hình ảnh minh họa trên web. | Tải đủ 3 ảnh về máy chủ; tắt mạng vẫn hiển thị đủ 3 ảnh. |
| 8 | `page08_youtube_embed.html` | **Video nhúng:** Chứa thẻ `<iframe>` nhúng YouTube. | Giữ lại iframe an toàn của YouTube, xem trực tiếp. |
| 9 | `page09_xss_malicious_script.html` | **Mã độc XSS:** Cố tình chèn `<script>`, `onclick`, iframe độc. | Lọc sạch toàn bộ mã độc; chữ vẫn lưu nhưng script bị vô hiệu hóa. |
| 10 | `page10_error_404_mock.html` | **Trang lỗi 404:** Mô phỏng trang không tồn tại. | Hệ thống **từ chối lưu** và hiển thị thông báo lỗi riêng. |
| 11 | `page11_short_notice.html` | **Trang quá ngắn:** Trang chỉ có 1 câu thông báo bảo trì. | Hệ thống **từ chối lưu** vì không có bài viết hợp lệ. |
| 12 | `page12_deep_keyword.html` | **Từ khóa sâu:** Chứa từ khóa *"cây đỏ đen"*, *"độ phức tạp logarit"*. | Tìm kiếm ra đúng bài và highlight từ khóa bằng `<mark>`. |
| 13 | `page13_version_original.html` | **Phiên bản 1.0:** Bản gốc hướng dẫn Docker cơ bản. | Lưu thành Snapshot phiên bản 1. |
| 14 | `page14_version_updated.html` | **Phiên bản 2.0:** Bản cập nhật bổ sung Docker Compose. | Lưu thành Snapshot phiên bản 2, giữ nguyên bản 1 & ghi chú. |
| 15 | `page15_long_academic.html` | **Bài học thuật dài:** Nhiều đề mục `<h2>`, `<h3>` và danh sách. | Định dạng giữ nguyên cấu trúc phân cấp tài liệu. |

---

## 5. Bảng 10 Truy Vấn Tìm Kiếm Mẫu Có Kết Quả Dự Kiến

Đáp ứng tiêu chí Mục 5 của đề tài: Chuẩn bị 10 truy vấn kiểm thử chức năng tìm kiếm:

| STT | Từ khóa tìm kiếm | Phạm vi tìm kiếm (Scope) | Thư viện chỉ định | Kết quả dự kiến |
| :---: | :--- | :---: | :---: | :--- |
| **Q01** | `đa hình` | Toàn bộ bài (`all`) | Tất cả thư viện | Tìm thấy bài *"Hướng dẫn Lập trình Hướng đối tượng OOP"*, trích đoạn highlight từ khóa. |
| **Q02** | `cây đỏ đen` | Thân bài (`content`) | Tất cả thư viện | Tìm thấy bài *"Cấu trúc Dữ liệu Nâng cao"*, đoạn trích hiển thị `<mark>cây đỏ đen</mark>`. |
| **Q03** | `PostgreSQL` | Tiêu đề (`title`) | Thư viện Công nghệ | Trả về các bài có chữ PostgreSQL trong tiêu đề. |
| **Q04** | `độ phức tạp logarit` | Thân bài (`content`) | Thư viện cá nhân | Tìm thấy chính xác bài viết số 12 dù từ khóa nằm sâu trong thân bài. |
| **Q05** | `Docker Compose` | Toàn bộ bài (`all`) | Thư viện DevOps | Chỉ tìm thấy ở Phiên bản 2.0 của bài Docker (bản 1.0 không có cụm từ này). |
| **Q06** | `Single Responsibility` | Toàn bộ bài (`all`) | Tất cả thư viện | Tìm thấy bài *"Clean Code và Nghệ thuật Tái cấu trúc"*. |
| **Q07** | `Argon2` | Thân bài (`content`) | Tất cả thư viện | Tìm thấy bài *"Bảo mật Ứng dụng Web"*. |
| **Q08** | `Kiến trúc Phát triển Web` | Tiêu đề (`title`) | Tất cả thư viện | Trả về cả 2 bài viết (Frontend & Backend) chứng minh không bị ghi đè. |
| **Q09** | Nhãn tag `#oop` | Lọc theo thẻ (Tag) | Tất cả thư viện | Liệt kê toàn bộ các bài viết được gắn thẻ `oop`. |
| **Q10** | `từ khóa chắc chắn không có 123xyz` | Toàn bộ bài (`all`) | Tất cả thư viện | Trả về danh sách rỗng (0 bài viết) kèm thông báo không tìm thấy kết quả. |

---

## 6. Kịch Bản Trình Diễn (Demo Khi Bảo Vệ)

### Kịch bản 1: Tiếp nhận, Bóc tách, Gắn nhãn, Đọc an toàn & Tìm kiếm
1. Mở trang chủ ứng dụng tại `http://127.0.0.1:8105`.
2. Mở trang danh mục mẫu `http://127.0.0.1:8105/mock_pages/`, bấm sao chép URL của bài viết có ảnh ngoại tuyến hoặc mã độc XSS.
3. Dán link vào ô **"Lưu bài viết mới"** $\rightarrow$ Chọn thư viện $\rightarrow$ Nhập nhãn tag `#cntt` $\rightarrow$ Bấm **Lưu trữ**.
4. Hệ thống tự động chuyển đến trang đọc bài ngoại tuyến:
   - Toàn bộ nội dung sạch sẽ, các mã script bị loại bỏ hoàn toàn.
   - Các ảnh minh họa đã được lưu trữ cục bộ, mở đọc độc lập.
5. Quay lại trang tìm kiếm, gõ từ khóa trong thân bài $\rightarrow$ Hệ thống hiển thị bài viết kèm đoạn trích dẫn ngữ cảnh được tô sáng bằng thẻ `<mark>`.

### Kịch bản 2: Quản lý Đa phiên bản, Đối chiếu, Ghi chú & Xuất tài liệu
1. Giả lập trang web gốc cập nhật bài viết mới hoặc bị thay đổi nội dung.
2. Tại trang chi tiết bài viết, thêm một **Ghi chú nghiên cứu cá nhân** (ví dụ: *"Đã kiểm tra ngày 17/11/2026"*).
3. Bấm nút **"Cập nhật phiên bản mới"** $\rightarrow$ Hệ thống tạo Snapshot phiên bản 2, ghi nhận thời điểm lấy mới.
4. Mở danh sách **Lịch sử các phiên bản**:
   - Dễ dàng bấm xem lại nội dung của Phiên bản 1 (bản cũ).
   - Xem nội dung Phiên bản 2 (bản mới nhất).
   - Mọi ghi chú của các phiên bản vẫn được bảo toàn nguyên vẹn.
5. Thử nghiệm xuất tài liệu: Bấm tải về tệp **HTML tĩnh độc lập**, **PDF** hoặc **Word (.docx)** có đầy đủ thông tin nguồn và bài viết.

---

## 7. Giấy phép Bản quyền (License)

Dự án được phân phối dưới giấy phép mã nguồn mở **MIT License**. Xem chi tiết tại tệp [LICENSE](LICENSE).
