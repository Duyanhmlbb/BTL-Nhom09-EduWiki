</div><!-- /edu-container -->

<!-- MODAL XÁC NHẬN CHUẨN GIAO DIỆN HỆ THỐNG (THAY THẾ WINDOW.CONFIRM) -->
<div id="eduUniversalConfirmModal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(2px); z-index: 999999; align-items: center; justify-content: center;">
    <div style="background: #ffffff; border-radius: 12px; max-width: 460px; width: 90%; padding: 24px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.15), 0 10px 10px -5px rgba(0, 0, 0, 0.05); margin: auto;">
        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 14px;">
            <div id="eduConfirmIcon" style="width: 44px; height: 44px; border-radius: 50%; background: #fee2e2; color: #dc2626; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0;">
                🗑️
            </div>
            <div>
                <h3 id="eduConfirmTitle" style="margin: 0; font-size: 18px; font-weight: 700; color: #1e293b;">
                    Xác Nhận Thao Tác
                </h3>
                <div id="eduConfirmSubtitle" style="font-size: 12px; color: #64748b; margin-top: 2px;">Vui lòng kiểm tra kỹ trước khi tiếp tục</div>
            </div>
        </div>

        <p id="eduConfirmMessage" style="margin: 0 0 22px 0; font-size: 14px; color: #334155; line-height: 1.6; word-break: break-word;">
            Bạn có chắc chắn muốn thực hiện thao tác này?
        </p>

        <div style="display: flex; justify-content: flex-end; gap: 10px;">
            <button type="button" id="eduConfirmCancelBtn" class="btn btn-outline" style="padding: 7px 18px; font-size: 13.5px; border-radius: 6px; cursor: pointer;">
                Hủy bỏ
            </button>
            <button type="button" id="eduConfirmOkBtn" class="btn" style="padding: 7px 20px; font-size: 13.5px; border-radius: 6px; background: #dc2626; color: #ffffff; border: none; font-weight: 600; cursor: pointer;">
                Đồng ý thực hiện
            </button>
        </div>
    </div>
</div>

<!-- MODAL ĐỔI TÊN THƯ VIỆN CHUẨN GIAO DIỆN HỆ THỐNG (THAY THẾ WINDOW.PROMPT) -->
<div id="eduUniversalRenameModal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(2px); z-index: 999999; align-items: center; justify-content: center;">
    <div style="background: #ffffff; border-radius: 12px; max-width: 460px; width: 90%; padding: 24px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.15), 0 10px 10px -5px rgba(0, 0, 0, 0.05); margin: auto;">
        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 14px;">
            <div style="width: 44px; height: 44px; border-radius: 50%; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0;">
                ✏️
            </div>
            <div>
                <h3 id="eduRenameTitle" style="margin: 0; font-size: 18px; font-weight: 700; color: #1e293b;">
                    Đổi Tên Thư Viện
                </h3>
                <div style="font-size: 12px; color: #64748b; margin-top: 2px;">Nhập tên mới phù hợp cho thư viện</div>
            </div>
        </div>

        <form id="eduRenameInternalForm" onsubmit="return eduHandleRenameSubmit(event);">
            <div style="margin-bottom: 20px;">
                <label id="eduRenameLabel" style="display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 6px;">
                    Tên thư viện mới:
                </label>
                <input type="text" id="eduRenameInput" class="form-control" style="width: 100%; height: 40px; font-size: 14px;" required>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" onclick="closeEduRenameModal();" class="btn btn-outline" style="padding: 7px 18px; font-size: 13.5px; border-radius: 6px; cursor: pointer;">
                    Hủy bỏ
                </button>
                <button type="submit" class="btn btn-wiki" style="padding: 7px 20px; font-size: 13.5px; border-radius: 6px; color: #ffffff !important; font-weight: 600; cursor: pointer;">
                    Lưu thay đổi
                </button>
            </div>
        </form>
    </div>
</div>

<script>
let eduConfirmCallback = null;
let eduRenameCallback = null;

function eduConfirm(options) {
    if (typeof options === 'string') {
        options = { message: options };
    }
    const modal = document.getElementById('eduUniversalConfirmModal');
    if (!modal) return;

    document.getElementById('eduConfirmTitle').textContent = options.title || 'Xác Nhận Thao Tác';
    document.getElementById('eduConfirmSubtitle').textContent = options.subtitle || 'Vui lòng kiểm tra kỹ trước khi tiếp tục';
    document.getElementById('eduConfirmMessage').innerHTML = options.message || 'Bạn có chắc chắn muốn thực hiện thao tác này?';
    
    const iconEl = document.getElementById('eduConfirmIcon');
    iconEl.textContent = options.icon || '🗑️';
    if (options.isDanger === false) {
        iconEl.style.background = '#e0f2fe';
        iconEl.style.color = '#0284c7';
    } else {
        iconEl.style.background = '#fee2e2';
        iconEl.style.color = '#dc2626';
    }

    const okBtn = document.getElementById('eduConfirmOkBtn');
    okBtn.textContent = options.okText || (options.isDanger === false ? 'Đồng ý' : 'Đồng ý xóa');
    if (options.isDanger === false) {
        okBtn.style.background = '#0f4c81';
    } else {
        okBtn.style.background = '#dc2626';
    }

    const cancelBtn = document.getElementById('eduConfirmCancelBtn');
    cancelBtn.textContent = options.cancelText || 'Hủy bỏ';

    eduConfirmCallback = options.onConfirm || null;
    modal.style.display = 'flex';
}

function closeEduConfirmModal() {
    const modal = document.getElementById('eduUniversalConfirmModal');
    if (modal) modal.style.display = 'none';
    eduConfirmCallback = null;
}

document.addEventListener('DOMContentLoaded', function() {
    const cancelBtn = document.getElementById('eduConfirmCancelBtn');
    if (cancelBtn) cancelBtn.onclick = closeEduConfirmModal;

    const okBtn = document.getElementById('eduConfirmOkBtn');
    if (okBtn) {
        okBtn.onclick = function() {
            const cb = eduConfirmCallback;
            closeEduConfirmModal();
            if (typeof cb === 'function') {
                cb();
            }
        };
    }
});

// Helper xác nhận cho form
function eduConfirmSubmit(form, event, message, title = 'Xác Nhận Thao Tác', okText = 'Xác nhận xóa', isDanger = true) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    eduConfirm({
        title: title,
        message: message,
        okText: okText,
        isDanger: isDanger,
        onConfirm: function() {
            form.submit();
        }
    });
    return false;
}

// Helper đổi tên (thay thế window.prompt)
function eduPrompt(options) {
    const modal = document.getElementById('eduUniversalRenameModal');
    if (!modal) return;

    document.getElementById('eduRenameTitle').textContent = options.title || 'Đổi Tên Thư Viện';
    document.getElementById('eduRenameLabel').textContent = options.label || 'Tên mới:';
    const input = document.getElementById('eduRenameInput');
    input.value = options.currentValue || '';
    eduRenameCallback = options.onConfirm || null;

    modal.style.display = 'flex';
    setTimeout(function() { input.focus(); input.select(); }, 50);
}

function closeEduRenameModal() {
    const modal = document.getElementById('eduUniversalRenameModal');
    if (modal) modal.style.display = 'none';
    eduRenameCallback = null;
}

function eduHandleRenameSubmit(e) {
    if (e) e.preventDefault();
    const val = document.getElementById('eduRenameInput').value.trim();
    if (val === '') return false;
    const cb = eduRenameCallback;
    closeEduRenameModal();
    if (typeof cb === 'function') {
        cb(val);
    }
    return false;
}
</script>

<footer style="background: #ffffff; border-top: 1px solid var(--wiki-border-light); padding: 24px 20px; font-size: 13px; color: var(--text-muted); text-align: center; margin-top: auto; flex-shrink: 0; width: 100%;">
    <div style="max-width: 1240px; margin: 0 auto;">
        <div style="display: flex; justify-content: center; align-items: center; gap: 8px; margin-bottom: 8px; font-weight: 700; color: var(--wiki-blue); font-size: 15px;">
            <span>📁</span> Wiki Archiver — Hệ Thống Lưu Trữ & Tra Cứu Tư Liệu Web Ngoại Tuyến
        </div>
        <p style="margin: 0 0 6px 0; max-width: 780px; margin-left: auto; margin-right: auto; line-height: 1.6;">
            Nền tảng lưu trữ và tra cứu tư liệu số: Tự động sao lưu bài viết, tin tức và trang web kèm toàn bộ hình ảnh về máy chủ nội bộ để đọc lại mọi lúc, không lo mất dữ liệu khi liên kết gốc bị xóa hoặc ngừng hoạt động.
        </p>
        <div style="font-size: 12px; opacity: 0.8; margin-top: 8px;">
            Lưu trữ tư liệu web • Tra cứu nội dung độc lập & Xuất file đọc ngoại tuyến (HTML / PDF / Word)
            • <a href="/mock_pages/" target="_blank" style="color: var(--wiki-blue); font-weight: 600; text-decoration: underline;">📄 15 Trang Mẫu Kiểm Thử</a>
        </div>
    </div>
</footer>

</body>
</html>
