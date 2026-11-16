<?php
declare(strict_types=1);

class WebFetcher {
    /**
     * Các cụm từ đặc trưng của trang lỗi hoặc thông báo chặn truy cập
     */
    private const ERROR_INDICATORS = [
        '404 not found',
        'page not found',
        'không tìm thấy trang',
        'trang không tồn tại',
        'access denied',
        '403 forbidden',
        '500 internal server error',
        'bad gateway',
        'service unavailable',
        'trang đang bảo trì',
        'under construction',
        'website is currently offline',
        'just a moment...',
        'attention required!',
        'cloudflare',
        'enable javascript and cookies to continue',
        'access restricted',
        'geo-blocked',
    ];

    /**
     * Chống SSRF: Kiểm tra host có trỏ về dải IP riêng tư (Private IP / Local loopback) hay không
     */
    public static function isSafeDestination(string $url, bool $allowLocalhost = false): bool {
        $parsed = parse_url($url);
        if (!$parsed || !isset($parsed['scheme']) || !in_array(strtolower($parsed['scheme']), ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower($parsed['host'] ?? '');
        if ($host === '') {
            return false;
        }

        if ($host === '127.0.0.1' || $host === 'localhost') {
            return $allowLocalhost;
        }

        $ip = gethostbyname($host);
        if ($ip === $host && !filter_var($ip, FILTER_VALIDATE_IP)) {
            // Không phân giải được DNS -> Web không tồn tại hoặc đã chết
            return false;
        }

        // Chặn IP thuộc dải Private (10.x, 192.168.x, 172.16-31.x) hoặc Reserved
        $isPrivate = !filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        return !$isPrivate;
    }

    /**
     * Kiểm tra và tải nội dung trang web:
     * - Cho phép bất kỳ trang web hợp lệ nào trên Internet
     * - Kiểm tra an toàn: trang còn sống hay đã chết, có bị chặn truy cập (fake IP/VPN), trang trắng hay trang lỗi
     */
    public static function fetch(string $url, int $maxRedirects = 5): array {
        // 1. Kiểm tra định dạng URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return [
                'success' => false,
                'status_code' => 0,
                'final_url' => $url,
                'error' => 'Địa chỉ liên kết (URL) không đúng định dạng hợp lệ. Vui lòng kiểm tra lại.'
            ];
        }

        $currentUrl = $url;
        $redirectCount = 0;

        while ($redirectCount <= $maxRedirects) {
            // Kiểm tra tính an toàn của địa chỉ (chống SSRF quét mạng nội bộ)
            $parsed = parse_url($currentUrl);
            $host = strtolower($parsed['host'] ?? '');

            // Cho phép localhost khi kiểm thử nội bộ nếu cần
            $isLocal = ($host === 'localhost' || $host === '127.0.0.1');
            if (!self::isSafeDestination($currentUrl, $isLocal)) {
                return [
                    'success' => false,
                    'status_code' => 0,
                    'final_url' => $currentUrl,
                    'error' => "Địa chỉ web '{$host}' không an toàn hoặc thuộc mạng nội bộ bị từ chối truy cập."
                ];
            }

            // Nhận diện nếu đây là liên kết YouTube (Xem video / Nghe nhạc trực tiếp)
            $ytVideoId = self::extractYouTubeVideoId($currentUrl);
            if ($ytVideoId !== null) {
                $videoTitle = 'Video YouTube';
                $authorName = 'YouTube Channel';

                // Lấy tiêu đề chính xác từ YouTube oEmbed API
                $oembedUrl = "https://www.youtube.com/oembed?url=" . urlencode("https://www.youtube.com/watch?v={$ytVideoId}") . "&format=json";
                $opt = [
                    'http' => [
                        'timeout' => 4,
                        'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) WebArchiver/1.0\r\n"
                    ]
                ];
                $ctx = stream_context_create($opt);
                $json = @file_get_contents($oembedUrl, false, $ctx);
                if ($json) {
                    $meta = json_decode($json, true);
                    if (!empty($meta['title'])) {
                        $videoTitle = (string)$meta['title'];
                    }
                    if (!empty($meta['author_name'])) {
                        $authorName = (string)$meta['author_name'];
                    }
                }

                $html = '
                    <div class="youtube-player-card" style="margin: 20px 0;">
                        <div style="position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); background: #000;">
                            <iframe style="position: absolute; top:0; left: 0; width: 100%; height: 100%; border: 0;"
                                    src="https://www.youtube.com/embed/' . htmlspecialchars($ytVideoId) . '?rel=0"
                                    title="' . htmlspecialchars($videoTitle) . '"
                                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                                    allowfullscreen>
                            </iframe>
                        </div>
                        <div style="padding: 16px; background: #f8f9fa; border: 1px solid #dadce0; border-radius: 8px; margin-top: 14px;">
                            <h2 style="margin: 0 0 8px 0; font-size: 19px; color: #1f1f1f;">' . htmlspecialchars($videoTitle) . '</h2>
                            <p style="margin: 0 0 8px 0; font-size: 14px; color: #5f6368;">Kênh phát hành: <strong>' . htmlspecialchars($authorName) . '</strong></p>
                            <p style="margin: 0; font-size: 13px; color: #70757a;">Xem video và nghe nhạc trực tiếp ngay trên trang lưu trữ.</p>
                        </div>
                    </div>
                ';

                return [
                    'success' => true,
                    'status_code' => 200,
                    'final_url' => $currentUrl,
                    'html' => $html,
                    'title' => '🎬 ' . $videoTitle,
                ];
            }

            // Nhận diện nếu đây là liên kết trực tiếp tới file âm thanh / bài hát (mp3, wav, ogg, m4a...)
            $path = parse_url($currentUrl, PHP_URL_PATH);
            $ext = strtolower(pathinfo($path ?? '', PATHINFO_EXTENSION));
            if (in_array($ext, ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac'], true)) {
                $filename = basename($path ?? 'Audio Track');
                $title = '🎵 ' . urldecode(pathinfo($filename, PATHINFO_FILENAME));

                $html = '
                    <div class="audio-player-card" style="padding: 24px; background: #f8f9fa; border: 1px solid #dadce0; border-radius: 8px; text-align: center; margin: 20px 0;">
                        <div style="font-size: 48px; margin-bottom: 12px;">🎵</div>
                        <h2 style="margin: 0 0 10px 0;">' . htmlspecialchars($title) . '</h2>
                        <p style="color: #5f6368; font-size: 14px; margin-bottom: 18px;">Tệp âm thanh được tải về và phát trực tiếp ngoại tuyến (không phụ thuộc vào link gốc).</p>
                        <audio controls src="' . htmlspecialchars($currentUrl) . '" style="width: 100%; max-width: 600px; outline: none;"></audio>
                        <p style="margin-top: 15px; font-size: 13px; color: #70757a;">Tệp nguồn: <a href="' . htmlspecialchars($currentUrl) . '" target="_blank">' . htmlspecialchars($currentUrl) . '</a></p>
                    </div>
                ';

                return [
                    'success' => true,
                    'status_code' => 200,
                    'final_url' => $currentUrl,
                    'html' => $html,
                    'title' => $title,
                ];
            }

            // Thiết lập HTTP stream với Headers giống trình duyệt thật
            $options = [
                'http' => [
                    'method' => 'GET',
                    'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36\r\n" .
                                "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8\r\n" .
                                "Accept-Language: vi,en-US;q=0.9,en;q=0.8\r\n" .
                                "Connection: close\r\n",
                    'follow_location' => 0,
                    'timeout' => 8,
                    'ignore_errors' => true,
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ]
            ];

            $context = stream_context_create($options);

            // Gửi request lấy nội dung trang
            $content = @file_get_contents($currentUrl, false, $context);
            $responseHeaders = $http_response_header ?? [];
            $statusCode = self::parseStatusCode($responseHeaders);

            // Không nhận được phản hồi (Web chết, DNS lỗi hoặc không có kết nối)
            if ($statusCode === 0) {
                return [
                    'success' => false,
                    'status_code' => 0,
                    'final_url' => $currentUrl,
                    'error' => "Không thể kết nối đến máy chủ web. Trang web có thể đã ngừng hoạt động (web chết) hoặc địa chỉ không tồn tại."
                ];
            }

            // Xử lý chuyển hướng (Redirects 301, 302, 303, 307, 308)
            if (in_array($statusCode, [301, 302, 303, 307, 308], true)) {
                $location = self::getHeaderValue($responseHeaders, 'Location');
                if (!$location) {
                    return [
                        'success' => false,
                        'status_code' => $statusCode,
                        'final_url' => $currentUrl,
                        'error' => 'Máy chủ chuyển hướng nhưng không cung cấp đường dẫn mới.'
                    ];
                }

                $nextUrl = self::resolveUrl($currentUrl, $location);
                $currentUrl = $nextUrl;
                $redirectCount++;
                continue;
            }

            // Kiểm tra các mã lỗi HTTP khi truy cập
            if ($statusCode === 404) {
                return [
                    'success' => false,
                    'status_code' => 404,
                    'final_url' => $currentUrl,
                    'error' => 'Trang web không tồn tại hoặc bài viết đã bị xóa (Mã phản hồi HTTP 404 Not Found).'
                ];
            }

            if ($statusCode === 403 || $statusCode === 401) {
                return [
                    'success' => false,
                    'status_code' => $statusCode,
                    'final_url' => $currentUrl,
                    'error' => "Trang web chặn truy cập (Mã lỗi HTTP {$statusCode} Forbidden/Unauthorized). Trang này có thể yêu cầu đăng nhập, chặn vùng lãnh thổ hoặc cần Fake IP / VPN mới xem được."
                ];
            }

            if ($statusCode >= 500) {
                return [
                    'success' => false,
                    'status_code' => $statusCode,
                    'final_url' => $currentUrl,
                    'error' => "Máy chủ trang web đang gặp sự cố nội bộ hoặc ngừng hoạt động (Mã lỗi HTTP {$statusCode})."
                ];
            }

            if ($statusCode !== 200) {
                return [
                    'success' => false,
                    'status_code' => $statusCode,
                    'final_url' => $currentUrl,
                    'error' => "Trang web phản hồi mã không thành công (HTTP {$statusCode})."
                ];
            }

            // Kiểm tra nội dung nhận về có bị rỗng (trang trắng) hay không
            if ($content === false || trim($content) === '') {
                return [
                    'success' => false,
                    'status_code' => 200,
                    'final_url' => $currentUrl,
                    'error' => 'Trang web không hiện ra gì (trang trắng / nội dung rỗng).'
                ];
            }

            // Kiểm tra xem trang có hợp lệ không (có phải chỉ là trang báo lỗi/chặn không)
            $validation = self::validateHtmlContent($content);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'status_code' => 200,
                    'final_url' => $currentUrl,
                    'error' => $validation['message']
                ];
            }

            return [
                'success' => true,
                'status_code' => 200,
                'final_url' => $currentUrl,
                'html' => $content,
                'title' => $validation['title'],
            ];
        }

        return [
            'success' => false,
            'status_code' => 0,
            'final_url' => $currentUrl,
            'error' => 'Trang web chuyển hướng vòng lặp quá nhiều lần.'
        ];
    }

    /**
     * Kiểm tra nội dung HTML có hợp lệ và đầy đủ để lưu trữ không
     */
    public static function validateHtmlContent(string $html): array {
        $title = '';
        if (preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $html, $matches)) {
            $title = trim(strip_tags($matches[1]));
        }

        $cleanText = strip_tags($html);
        $cleanText = preg_replace('/\s+/', ' ', $cleanText);
        $cleanText = trim($cleanText);

        // Nếu nội dung quá ngắn (< 40 ký tự) -> Không phải bài viết hợp lệ
        if (mb_strlen($cleanText) < 40) {
            return [
                'valid' => false,
                'title' => $title,
                'message' => 'Trang web không có nội dung văn bản hoặc chỉ có trang trắng, không đủ điều kiện để lưu trữ.'
            ];
        }

        // Kiểm tra dấu hiệu trang lỗi hoặc chặn bot/captcha
        $lowerTitle = mb_strtolower($title);
        $lowerText = mb_strtolower(mb_substr($cleanText, 0, 500));

        foreach (self::ERROR_INDICATORS as $indicator) {
            if (str_contains($lowerTitle, $indicator)) {
                return [
                    'valid' => false,
                    'title' => $title,
                    'message' => "Trang web chỉ hiển thị thông báo lỗi ('{$indicator}'), không có bài viết thực tế để lưu."
                ];
            }
        }

        if (mb_strlen($cleanText) < 150) {
            foreach (self::ERROR_INDICATORS as $indicator) {
                if (str_contains($lowerText, $indicator)) {
                    return [
                        'valid' => false,
                        'title' => $title,
                        'message' => "Trang web chặn truy cập hoặc chỉ chứa thông báo lỗi ('{$indicator}')."
                    ];
                }
            }
        }

        return [
            'valid' => true,
            'title' => $title !== '' ? $title : 'Không có tiêu đề',
            'message' => 'Hợp lệ'
        ];
    }

    private static function parseStatusCode(array $headers): int {
        if (empty($headers)) {
            return 0;
        }
        $statusLine = $headers[0];
        if (preg_match('/HTTP\/\d\.\d\s+(\d{3})/', $statusLine, $matches)) {
            return (int)$matches[1];
        }
        return 0;
    }

    private static function getHeaderValue(array $headers, string $key): ?string {
        $prefix = strtolower($key) . ':';
        foreach ($headers as $header) {
            if (str_starts_with(strtolower(trim($header)), $prefix)) {
                return trim(substr($header, strlen($prefix)));
            }
        }
        return null;
    }

    /**
     * Nhận diện và trích xuất YouTube Video ID từ liên kết
     */
    public static function extractYouTubeVideoId(string $url): ?string {
        if (preg_match('/(?:youtube\.com\/(?:watch\?.*?v=|embed\/|shorts\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/i', $url, $matches)) {
            return $matches[1];
        }
        return null;
    }

    public static function resolveUrl(string $baseUrl, string $relativeUrl): string {
        if (parse_url($relativeUrl, PHP_URL_SCHEME) != '') {
            return $relativeUrl;
        }

        $base = parse_url($baseUrl);
        $scheme = $base['scheme'] ?? 'http';
        $host = $base['host'] ?? '';
        $port = isset($base['port']) ? ':' . $base['port'] : '';

        if (str_starts_with($relativeUrl, '//')) {
            return $scheme . ':' . $relativeUrl;
        }

        if (str_starts_with($relativeUrl, '/')) {
            return "{$scheme}://{$host}{$port}{$relativeUrl}";
        }

        $path = $base['path'] ?? '/';
        $dir = preg_replace('#/[^/]*$#', '', $path);
        return "{$scheme}://{$host}{$port}" . rtrim($dir, '/') . '/' . ltrim($relativeUrl, '/');
    }
}
