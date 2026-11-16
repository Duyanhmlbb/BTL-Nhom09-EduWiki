<?php
declare(strict_types=1);

require_once __DIR__ . '/WebFetcher.php';

class AssetManager {
    private PDO $db;
    private string $storageDir;
    private string $publicUrlPrefix;

    /**
     * Các định dạng hình ảnh và âm thanh được hỗ trợ lưu trữ cục bộ (Offline)
     */
    private const SUPPORTED_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
    private const SUPPORTED_AUDIO_EXTENSIONS = ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac'];

    public function __construct(PDO $db, ?string $storageDir = null, string $publicUrlPrefix = '/assets/saved_media/') {
        $this->db = $db;
        $this->storageDir = $storageDir ?? dirname(__DIR__) . '/public/assets/saved_media';
        $this->publicUrlPrefix = rtrim($publicUrlPrefix, '/') . '/';

        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0755, true);
        }
    }

    /**
     * Tải và lưu các tệp đa phương tiện (Ảnh & Âm thanh/Nhạc) về máy chủ cục bộ,
     * thay thế đường dẫn trong HTML thành đường dẫn nội bộ để phát ngoại tuyến.
     */
    public function localizeMedia(int $snapshotId, int $savedPageId, string $html, string $pageUrl): array {
        $savedAssets = [];
        $modifiedHtml = $html;

        // 1. Xử lý các thẻ hình ảnh <img> (hỗ trợ cả lazy-load data-src, data-original, data-sv1, data-sv2, data-url)
        if (preg_match_all('/<img\b([^>]*)>/is', $modifiedHtml, $imgMatches, PREG_SET_ORDER)) {
            foreach ($imgMatches as $match) {
                $fullTag = $match[0];
                $attrs = $match[1];

                // Tìm URL ảnh từ data-original, data-src, data-sv1, data-sv2, data-lazy-src, data-url hoặc src
                $candidateUrls = [];
                if (preg_match('/\b(?:data-original|data-src|data-sv1|data-sv2|data-lazy-src|data-url)=([\'"])(.*?)\1/i', $attrs, $mAttr)) {
                    $candidateUrls[] = trim($mAttr[2]);
                }
                if (preg_match('/\bsrc=([\'"])(.*?)\1/i', $attrs, $mSrc)) {
                    $candidateUrls[] = trim($mSrc[2]);
                }

                $chosenUrl = null;
                foreach ($candidateUrls as $candidate) {
                    if ($candidate !== '' && !str_starts_with($candidate, 'data:')) {
                        $chosenUrl = $candidate;
                        break;
                    }
                }

                if (!$chosenUrl) {
                    continue;
                }

                // Nếu ảnh đã là file cục bộ có sẵn trên server (khi chia sẻ từ kho cá nhân sang nhóm)
                if (str_starts_with($chosenUrl, $this->publicUrlPrefix) || str_starts_with($chosenUrl, '/assets/saved_media/')) {
                    $existingLocalFile = $this->storageDir . '/' . basename($chosenUrl);
                    if (file_exists($existingLocalFile) && filesize($existingLocalFile) > 0) {
                        $orig = $chosenUrl;
                        if (preg_match('/\bdata-original-src=([\'"])(.*?)\1/i', $attrs, $mOrig)) {
                            $orig = trim($mOrig[2]);
                        }
                        $this->recordAsset($snapshotId, $orig, $chosenUrl);
                        $savedAssets[] = ['type' => 'image', 'url' => $chosenUrl];
                        continue;
                    }
                }

                $absoluteUrl = WebFetcher::resolveUrl($pageUrl, $chosenUrl);
                $ext = $this->getExtension($absoluteUrl, self::SUPPORTED_IMAGE_EXTENSIONS, 'jpg');

                $baseFileName = "img_{$savedPageId}_snap_{$snapshotId}_" . substr(md5($absoluteUrl), 0, 10);
                $fileName = $baseFileName . ".{$ext}";
                $localFilePath = $this->storageDir . '/' . $fileName;
                $localWebPath = $this->publicUrlPrefix . $fileName;

                if ($this->ensureLocalFile($absoluteUrl, $localFilePath, $pageUrl)) {
                    // Phát hiện file thực tế đã lưu (extension có thể đã thay đổi nếu Content-Type khác URL)
                    $actualFilePath = $localFilePath;
                    if (!file_exists($actualFilePath) || filesize($actualFilePath) === 0) {
                        // Thử tìm file với extension khác (svg, png, jpg, gif, webp)
                        foreach (self::SUPPORTED_IMAGE_EXTENSIONS as $tryExt) {
                            $candidate = $this->storageDir . '/' . $baseFileName . '.' . $tryExt;
                            if (file_exists($candidate) && filesize($candidate) > 0) {
                                $actualFilePath = $candidate;
                                break;
                            }
                        }
                    }
                    $actualFileName = basename($actualFilePath);
                    $localWebPath   = $this->publicUrlPrefix . $actualFileName;

                    $newTag = $fullTag;
                    
                    // Loại bỏ srcset và sizes từ web gốc để trình duyệt không bỏ qua ảnh cục bộ
                    $newTag = preg_replace('/\s+(?:srcset|sizes)=([\'"]).*?\1/is', '', $newTag);
                    $newTag = preg_replace('/\bloading=([\'"])lazy\1/i', 'loading="eager"', $newTag);

                    // Kiểm tra thuộc tính src thực sự (dùng negative lookbehind để KHÔNG nhầm với data-src)
                    if (preg_match('/(?<![a-zA-Z0-9_-])src=([\'"]).*?\1/i', $newTag)) {
                        $newTag = preg_replace(
                            '/(?<![a-zA-Z0-9_-])src=([\'"]).*?\1/i',
                            'src="' . htmlspecialchars($localWebPath, ENT_QUOTES) . '" data-original-src="' . htmlspecialchars($absoluteUrl, ENT_QUOTES) . '"',
                            $newTag,
                            1
                        );
                    } else {
                        // Thẻ lazy-load chỉ có data-src, bổ sung ngay thuộc tính src thực tế
                        $newTag = preg_replace('/<img\b/i', '<img src="' . htmlspecialchars($localWebPath, ENT_QUOTES) . '" data-original-src="' . htmlspecialchars($absoluteUrl, ENT_QUOTES) . '"', $newTag, 1);
                    }

                    // Đồng bộ hóa data-src / data-original về tệp cục bộ
                    if (preg_match('/\bdata-src=([\'"]).*?\1/i', $newTag)) {
                        $newTag = preg_replace('/\bdata-src=([\'"]).*?\1/i', 'data-src="' . htmlspecialchars($localWebPath, ENT_QUOTES) . '"', $newTag);
                    }
                    if (preg_match('/\bdata-original=([\'"]).*?\1/i', $newTag)) {
                        $newTag = preg_replace('/\bdata-original=([\'"]).*?\1/i', 'data-original="' . htmlspecialchars($localWebPath, ENT_QUOTES) . '"', $newTag);
                    }

                    $modifiedHtml = str_replace($fullTag, $newTag, $modifiedHtml);

                    $this->recordAsset($snapshotId, $absoluteUrl, $localWebPath);
                    $savedAssets[] = ['type' => 'image', 'url' => $localWebPath];
                } else {
                    // Nếu tải thất bại, đảm bảo thẻ vẫn có src trỏ tới link gốc (không để thẻ img bị rỗng src)
                    if (!preg_match('/(?<![a-zA-Z0-9_-])src=([\'"]).*?\1/i', $fullTag)) {
                        $fallbackTag = preg_replace('/<img\b/i', '<img src="' . htmlspecialchars($absoluteUrl, ENT_QUOTES) . '"', $fullTag, 1);
                        $modifiedHtml = str_replace($fullTag, $fallbackTag, $modifiedHtml);
                    }
                }
            }
        }

        // 2. Xử lý các tệp âm thanh/nhạc (<audio src="..."> hoặc <source src="...">)
        if (preg_match_all('/<(audio|source)\b[^>]*src=([\'"])(.*?)\2[^>]*>/is', $modifiedHtml, $audioMatches, PREG_SET_ORDER)) {
            foreach ($audioMatches as $match) {
                $fullTag = $match[0];
                $originalSrc = trim($match[3]);

                if ($originalSrc === '' || str_starts_with($originalSrc, 'data:')) {
                    continue;
                }

                // Nếu audio đã là file cục bộ có sẵn trên server
                if (str_starts_with($originalSrc, $this->publicUrlPrefix) || str_starts_with($originalSrc, '/assets/saved_media/')) {
                    $existingLocalFile = $this->storageDir . '/' . basename($originalSrc);
                    if (file_exists($existingLocalFile) && filesize($existingLocalFile) > 0) {
                        $orig = $originalSrc;
                        if (preg_match('/\bdata-original-src=([\'"])(.*?)\1/i', $fullTag, $mOrig)) {
                            $orig = trim($mOrig[2]);
                        }
                        $this->recordAsset($snapshotId, $orig, $originalSrc);
                        $savedAssets[] = ['type' => 'audio', 'url' => $originalSrc];
                        continue;
                    }
                }

                $absoluteUrl = WebFetcher::resolveUrl($pageUrl, $originalSrc);
                $ext = $this->getExtension($absoluteUrl, self::SUPPORTED_AUDIO_EXTENSIONS, 'mp3');

                $fileName = "audio_{$savedPageId}_snap_{$snapshotId}_" . substr(md5($absoluteUrl), 0, 10) . ".{$ext}";
                $localFilePath = $this->storageDir . '/' . $fileName;
                $localWebPath = $this->publicUrlPrefix . $fileName;

                if ($this->ensureLocalFile($absoluteUrl, $localFilePath)) {
                    $newTag = preg_replace(
                        '/\bsrc=([\'"]).*?\1/i',
                        'src="' . htmlspecialchars($localWebPath, ENT_QUOTES) . '" data-original-src="' . htmlspecialchars($absoluteUrl, ENT_QUOTES) . '"',
                        $fullTag
                    );
                    $modifiedHtml = str_replace($fullTag, $newTag, $modifiedHtml);

                    $this->recordAsset($snapshotId, $absoluteUrl, $localWebPath);
                    $savedAssets[] = ['type' => 'audio', 'url' => $localWebPath];
                }
            }
        }

        return [
            'content_html' => $modifiedHtml,
            'saved_assets' => $savedAssets
        ];
    }

    /**
     * Tương thích ngược với tên gọi cũ localizeImages
     */
    public function localizeImages(int $snapshotId, int $savedPageId, string $html, string $pageUrl): array {
        return $this->localizeMedia($snapshotId, $savedPageId, $html, $pageUrl);
    }

    private function getExtension(string $url, array $supported, string $default): string {
        $path = parse_url($url, PHP_URL_PATH);
        $ext = strtolower(pathinfo($path ?? '', PATHINFO_EXTENSION));
        // Loại bỏ query string dính vào extension (vd: .jpg?v=123 → jpg)
        $ext = preg_replace('/[^a-z0-9].*$/', '', $ext);
        return in_array($ext, $supported, true) ? $ext : $default;
    }

    /**
     * Phát hiện extension thực tế từ Content-Type header hoặc nội dung file.
     * Trả về extension đúng (svg, png, jpg, gif, webp) hoặc null nếu không xác định được.
     */
    private function detectRealExtension(string $data, array $responseHeaders): ?string {
        // 1. Kiểm tra Content-Type từ response header
        $contentType = '';
        foreach ($responseHeaders as $h) {
            if (stripos($h, 'content-type:') === 0) {
                $contentType = strtolower(trim(substr($h, 13)));
                break;
            }
        }
        $contentType = explode(';', $contentType)[0];

        $mimeMap = [
            'image/svg+xml'   => 'svg',
            'image/png'       => 'png',
            'image/jpeg'      => 'jpg',
            'image/gif'       => 'gif',
            'image/webp'      => 'webp',
            'image/avif'      => 'avif',
            'image/x-icon'    => 'ico',
            'image/bmp'       => 'bmp',
        ];
        if (isset($mimeMap[$contentType])) {
            return $mimeMap[$contentType];
        }

        // 2. Kiểm tra magic bytes (file signature)
        $header4 = substr($data, 0, 4);
        $header8 = substr($data, 0, 8);
        if (str_starts_with($data, '<svg') || str_starts_with($data, '<?xml') || str_contains(substr($data, 0, 100), '<svg')) {
            return 'svg';
        }
        if (str_starts_with($header8, "\x89PNG\r\n\x1a\n")) {
            return 'png';
        }
        if (str_starts_with($header4, "\xff\xd8\xff")) {
            return 'jpg';
        }
        if (str_starts_with($header4, 'GIF8')) {
            return 'gif';
        }
        if (str_starts_with($header4, 'RIFF') && str_contains(substr($data, 0, 12), 'WEBP')) {
            return 'webp';
        }

        return null;
    }

    private function ensureLocalFile(string $url, string $savePath, ?string $referer = null): bool {
        if (file_exists($savePath) && filesize($savePath) > 0) {
            return true;
        }
        return $this->downloadFile($url, $savePath, $referer);
    }

    /**
     * Tải file về và tự động sửa extension nếu Content-Type thực tế khác với đuôi file trong URL.
     * Trả về đường dẫn thực tế đã lưu (có thể khác $saveTo nếu extension bị đổi).
     */
    private function downloadFile(string $url, string $saveTo, ?string $referer = null): bool {
        $parsed = parse_url($url);
        if (!$parsed || !isset($parsed['host'])) {
            return false;
        }

        $headers = "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36\r\n";
        if ($referer !== null && $referer !== '') {
            $headers .= "Referer: {$referer}\r\n";
        } else {
            $origin = ($parsed['scheme'] ?? 'https') . '://' . $parsed['host'] . '/';
            $headers .= "Referer: {$origin}\r\n";
        }
        $headers .= "Accept: image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8\r\n" .
                    "Accept-Language: vi,en-US;q=0.9,en;q=0.8\r\n" .
                    "Connection: close\r\n";

        $options = [
            'http' => [
                'method' => 'GET',
                'header' => $headers,
                'timeout' => 12,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ]
        ];

        $context = stream_context_create($options);
        $data = @file_get_contents($url, false, $context);

        if ($data !== false && strlen($data) > 0) {
            // Không lưu nếu máy chủ trả về mã lỗi 4xx hoặc 5xx
            $responseHeaders = $http_response_header ?? [];
            $responseHeader  = $responseHeaders[0] ?? '';
            if (preg_match('/HTTP\/\d\.\d\s+([45]\d\d)/i', $responseHeader)) {
                return false;
            }

            // Tự động phát hiện extension thực tế từ Content-Type / magic bytes
            $realExt = $this->detectRealExtension($data, $responseHeaders);
            if ($realExt !== null) {
                $currentExt = strtolower(pathinfo($saveTo, PATHINFO_EXTENSION));
                if ($currentExt !== $realExt) {
                    // Đổi extension file lưu cho đúng với nội dung thực tế
                    $saveTo = preg_replace('/\.[^.]+$/', '.' . $realExt, $saveTo);
                }
            }

            return @file_put_contents($saveTo, $data) !== false;
        }

        return false;
    }

    private function recordAsset(int $snapshotId, string $originalUrl, string $localPath): void {
        try {
            $check = $this->db->prepare("SELECT 1 FROM assets WHERE snapshot_id = ? AND local_path = ? LIMIT 1");
            $check->execute([$snapshotId, $localPath]);
            if ($check->fetchColumn()) {
                return;
            }
            $stmt = $this->db->prepare("INSERT INTO assets (snapshot_id, original_url, local_path) VALUES (?, ?, ?)");
            $stmt->execute([$snapshotId, $originalUrl, $localPath]);
        } catch (Throwable $e) {}
    }

    public function getAssetsBySnapshot(int $snapshotId): array {
        $stmt = $this->db->prepare("SELECT original_url, local_path FROM assets WHERE snapshot_id = ?");
        $stmt->execute([$snapshotId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
