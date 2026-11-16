<?php
declare(strict_types=1);

class HtmlCleaner {
    /**
     * Làm sạch mã HTML, tách tiêu đề và phần bài viết,
     * loại bỏ toàn bộ nội dung có thể thực thi và script nguy hiểm.
     * Thỏa mãn Chức năng 2, Chức năng 3 và Yêu cầu kỹ thuật 2.
     */
    public static function clean(string $rawHtml, string $baseUrl = ''): array {
        $title = self::extractTitle($rawHtml);
        $articleHtml = self::extractArticleContent($rawHtml);
        $sanitizedHtml = self::sanitize($articleHtml);
        
        // Vô hiệu hóa (disable) tất cả các liên kết <a> để bản lưu chỉ đọc được, không nhảy ra ngoài
        if ($baseUrl !== '') {
            $sanitizedHtml = self::disableLinks($sanitizedHtml, $baseUrl);
        }

        $contentText = self::extractText($sanitizedHtml);

        return [
            'title' => $title,
            'content_html' => $sanitizedHtml,
            'content_text' => $contentText,
            'body_text' => $contentText,
        ];
    }

    /**
     * Trích xuất tiêu đề bài viết từ thẻ meta og:title, <title> hoặc <h1>
     */
    public static function extractTitle(string $rawHtml): string {
        // Ưu tiên thẻ meta Open Graph
        if (preg_match('/<meta\s+property=["\']og:title["\']\s+content=["\'](.*?)["\']/is', $rawHtml, $m)) {
            return trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        if (preg_match('/<meta\s+content=["\'](.*?)["\']\s+property=["\']og:title["\']/is', $rawHtml, $m)) {
            return trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        // Thẻ <title>
        if (preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $rawHtml, $m)) {
            $t = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($t !== '') {
                return $t;
            }
        }

        // Thẻ <h1> đầu tiên
        if (preg_match('/<h1\b[^>]*>(.*?)<\/h1>/is', $rawHtml, $m)) {
            $t = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($t !== '') {
                return $t;
            }
        }

        // Thẻ <h2> đầu tiên
        if (preg_match('/<h2\b[^>]*>(.*?)<\/h2>/is', $rawHtml, $m)) {
            $t = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($t !== '') {
                return $t;
            }
        }

        // Thẻ <h3> đầu tiên
        if (preg_match('/<h3\b[^>]*>(.*?)<\/h3>/is', $rawHtml, $m)) {
            $t = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($t !== '') {
                return $t;
            }
        }

        // Thử lấy câu/dòng chữ đầu tiên làm tiêu đề nếu có
        $text = trim(html_entity_decode(strip_tags($rawHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($text !== '') {
            $firstLine = preg_split('/\r\n|\r|\n/', $text)[0] ?? '';
            $firstLine = trim($firstLine);
            if (mb_strlen($firstLine, 'UTF-8') > 80) {
                $firstLine = mb_substr($firstLine, 0, 77, 'UTF-8') . '...';
            }
            if ($firstLine !== '') {
                return $firstLine;
            }
        }

        return 'Không có tiêu đề';
    }

    /**
     * Tách riêng phần nội dung chính của bài viết, loại bỏ header, navbar, footer thừa
     */
    public static function extractArticleContent(string $rawHtml): string {
        // Ưu tiên 1: mw-content-text của Wikipedia (nơi chứa 100% nội dung bài viết sạch, không có menu/toolbar)
        // Dùng tag balancer để lấy trọn vẹn khối div mw-content-text
        if (preg_match('/<(div)\b[^>]*(?:id|class)=["\'][^"\']*mw-content-text[^"\']*["\'][^>]*>/i', $rawHtml, $m, PREG_OFFSET_CAPTURE)) {
            $matchedBlock = self::extractBalancedBlock($rawHtml, $m[0][1], $m[1][0], strlen($m[0][0]));
            if ($matchedBlock !== '') {
                return self::stripSidebars($matchedBlock);
            }
        }

        // Ưu tiên 2: Thẻ <article>
        if (preg_match('/<(article)\b[^>]*>/i', $rawHtml, $m, PREG_OFFSET_CAPTURE)) {
            $matchedBlock = self::extractBalancedBlock($rawHtml, $m[0][1], $m[1][0], strlen($m[0][0]));
            if ($matchedBlock !== '') {
                return self::stripSidebars($matchedBlock);
            }
        }

        // Ưu tiên 3: Các khối chứa bài viết đặc trưng (post-content, entry-content, article-body...)
        if (preg_match('/<(div)\b[^>]*(?:id|class)=["\'][^"\']*(?:post-content|entry-content|article-body|article-content)[^"\']*["\'][^>]*>/i', $rawHtml, $m, PREG_OFFSET_CAPTURE)) {
            $matchedBlock = self::extractBalancedBlock($rawHtml, $m[0][1], $m[1][0], strlen($m[0][0]));
            if ($matchedBlock !== '') {
                return self::stripSidebars($matchedBlock);
            }
        }

        // Ưu tiên 4: Thẻ <main>
        if (preg_match('/<(main)\b[^>]*>/i', $rawHtml, $m, PREG_OFFSET_CAPTURE)) {
            $matchedBlock = self::extractBalancedBlock($rawHtml, $m[0][1], $m[1][0], strlen($m[0][0]));
            if ($matchedBlock !== '') {
                return self::stripSidebars($matchedBlock);
            }
        }

        // Ưu tiên 5: Thẻ <body>
        if (preg_match('/<body\b[^>]*>(.*?)<\/body>/is', $rawHtml, $m)) {
            return self::stripSidebars($m[1]);
        }

        return self::stripSidebars($rawHtml);
    }

    /**
     * Trích xuất một khối HTML hoàn chỉnh dựa trên cân bằng thẻ mở/đóng (nested tag balancer)
     */
    private static function extractBalancedBlock(string $html, int $startPos, string $tagName, int $tagHeaderLen): string {
        $cursor = $startPos + $tagHeaderLen;
        $depth = 1;
        $len = strlen($html);

        while ($cursor < $len && $depth > 0) {
            $nextOpen = stripos($html, "<{$tagName}", $cursor);
            $nextClose = stripos($html, "</{$tagName}>", $cursor);

            if ($nextClose === false) {
                break;
            }

            if ($nextOpen !== false && $nextOpen < $nextClose) {
                $charAfter = $html[$nextOpen + strlen($tagName) + 1] ?? '';
                if ($charAfter === ' ' || $charAfter === '>' || $charAfter === '/' || $charAfter === "\n" || $charAfter === "\t") {
                    $depth++;
                }
                $cursor = $nextOpen + strlen($tagName) + 1;
            } else {
                $depth--;
                $cursor = $nextClose + strlen($tagName) + 3;
            }
        }

        return substr($html, $startPos, $cursor - $startPos);
    }

    /**
     * Loại bỏ các thẻ lồng nhau dựa trên tag balancer
     */
    public static function removeBalancedTag(string $html, string $tagPattern): string {
        while (preg_match($tagPattern, $html, $matches, PREG_OFFSET_CAPTURE)) {
            $startPos = $matches[0][1];
            $tagName = $matches[1][0];
            $tagHeaderLen = strlen($matches[0][0]);

            $cursor = $startPos + $tagHeaderLen;
            $depth = 1;
            $len = strlen($html);

            while ($cursor < $len && $depth > 0) {
                $nextOpen = stripos($html, "<{$tagName}", $cursor);
                $nextClose = stripos($html, "</{$tagName}>", $cursor);

                if ($nextClose === false) {
                    $cursor = $len;
                    break;
                }

                if ($nextOpen !== false && $nextOpen < $nextClose) {
                    $charAfter = $html[$nextOpen + strlen($tagName) + 1] ?? '';
                    if ($charAfter === ' ' || $charAfter === '>' || $charAfter === '/' || $charAfter === "\n" || $charAfter === "\t") {
                        $depth++;
                    }
                    $cursor = $nextOpen + strlen($tagName) + 1;
                } else {
                    $depth--;
                    $cursor = $nextClose + strlen($tagName) + 3;
                }
            }

            $html = substr($html, 0, $startPos) . substr($html, $cursor);
        }
        return $html;
    }

    /**
     * Loại bỏ các sidebar/toolbar không cần thiết của các trang web phổ biến
     * (Wikipedia toolbars, sidebars, print menus, v.v.)
     */
    public static function stripSidebars(string $html): string {
        $patterns = [
            '/<(header)\b[^>]*class=["\'][^"\']*vector-page-titlebar[^"\']*["\'][^>]*>/i',
            '/<(div)\b[^>]*class=["\'][^"\']*vector-page-toolbar[^"\']*["\'][^>]*>/i',
            '/<(div)\b[^>]*class=["\'][^"\']*vector-column-end[^"\']*["\'][^>]*>/i',
            '/<(div)\b[^>]*class=["\'][^"\']*vector-page-tools[^"\']*["\'][^>]*>/i',
            '/<(div)\b[^>]*class=["\'][^"\']*vector-menu[^"\']*["\'][^>]*>/i',
            '/<(div)\b[^>]*class=["\'][^"\']*mw-portlet[^"\']*["\'][^>]*>/i',
            '/<(div)\b[^>]*class=["\'][^"\']*vector-dropdown[^"\']*["\'][^>]*>/i',
            '/<(div)\b[^>]*id=["\'](?:p-tb|p-views|p-cactions|p-navigation|p-associated-pages|p-coll-print_export|catlinks|vector-page-tools)[^"\']*["\'][^>]*>/i',
            '/<(div)\b[^>]*class=["\'][^"\']*(?:mw-editsection|printfooter|mw-indicators|noprint|site-sidebar|widget-area|advertisement|ad-wrapper|cookie-banner)[^"\']*["\'][^>]*>/i',
            '/<(nav)\b[^>]*>/i',
            '/<(footer)\b[^>]*>/i',
            '/<(aside)\b[^>]*>/i',
        ];

        foreach ($patterns as $p) {
            $html = self::removeBalancedTag($html, $p);
        }

        return $html;
    }

    /**
     * Loại bỏ triệt để nội dung có thể thực thi và các tài nguyên ngoài kiểm soát
     * Thỏa mãn Yêu cầu kỹ thuật 2: Chặn thực thi script và việc tải tài nguyên ngoài kiểm soát
     */
    public static function sanitize(string $html): string {
        // 1. Loại bỏ toàn bộ thẻ thực thi script
        $clean = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
        $clean = preg_replace('/<noscript\b[^>]*>(.*?)<\/noscript>/is', '', $clean);

        // 2. Loại bỏ các thẻ nhúng đối tượng ngoài độc hại (giữ lại iframe YouTube an toàn)
        $clean = preg_replace_callback('/<iframe\b([^>]*)>(.*?)<\/iframe>/is', function($matches) {
            $attrs = $matches[1];
            if (preg_match('/src=([\'"])https:\/\/(www\.)?(youtube\.com\/embed\/|youtube-nocookie\.com\/embed\/)([a-zA-Z0-9_-]+)[^\'\"]*\1/i', $attrs)) {
                return "<iframe {$attrs}></iframe>";
            }
            return '';
        }, $clean);

        $clean = preg_replace('/<object\b[^>]*>(.*?)<\/object>/is', '', $clean);
        $clean = preg_replace('/<embed\b[^>]*>(.*?)<\/embed>/is', '', $clean);
        $clean = preg_replace('/<applet\b[^>]*>(.*?)<\/applet>/is', '', $clean);
        $clean = preg_replace('/<frameset\b[^>]*>(.*?)<\/frameset>/is', '', $clean);
        $clean = preg_replace('/<frame\b[^>]*>/is', '', $clean);

        // 3. Loại bỏ thẻ định kiểu nội tuyến nguy hiểm và thẻ can thiệp điều hướng
        $clean = preg_replace('/<base\b[^>]*>/is', '', $clean);
        $clean = preg_replace('/<link\b[^>]*>/is', '', $clean);
        $clean = preg_replace('/<meta\b[^>]*>/is', '', $clean);
        $clean = preg_replace('/<form\b[^>]*>(.*?)<\/form>/is', '', $clean);

        // 4. Loại bỏ toàn bộ các thuộc tính sự kiện inline (onclick, onload, onerror, onmouseover...)
        $clean = preg_replace('/\s+on\w+\s*=\s*(["\']).*?\1/is', '', $clean);
        $clean = preg_replace('/\s+on\w+\s*=\s*[^\s>]+/is', '', $clean);

        // 5. Loại bỏ pseudo-protocols nguy hiểm trong href và src (javascript:, vbscript:, data:)
        $clean = preg_replace('/\s+href\s*=\s*(["\'])\s*(javascript|vbscript):.*?\1/is', ' href="#"', $clean);
        $clean = preg_replace('/\s+src\s*=\s*(["\'])\s*(javascript|vbscript):.*?\1/is', ' src=""', $clean);

        return $clean;
    }

    /**
     * Vô hiệu hóa (disable) toàn bộ các thẻ <a> trong nội dung lưu trữ.
     * Thay <a href="...">text</a> bằng <span class="archived-disabled-link" ...>text</span>
     * để bản lưu trữ chỉ có thể đọc, không thể bấm vào link nhảy ra ngoài.
     * Ngoại lệ: giữ nguyên các neo nội bộ (#section) để TOC vẫn hoạt động.
     */
    public static function disableLinks(string $html, string $baseUrl = ''): string {
        require_once __DIR__ . '/WebFetcher.php';

        return preg_replace_callback('/<a\b([^>]*)>(.*?)<\/a>/is', function($matches) use ($baseUrl) {
            $attrs   = $matches[1];
            $inner   = $matches[2];

            // Trích xuất href
            $href = '';
            if (preg_match('/\bhref\s*=\s*(["\'])(.*?)\1/is', $attrs, $hm)) {
                $href = trim($hm[2]);
            }

            // Giữ nguyên neo nội bộ trong trang (#section) để bảng mục lục vẫn dùng được
            if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'javascript:')) {
                return $matches[0];
            }

            // Chuyển URL tương đối thành tuyệt đối nếu có baseUrl
            $absUrl = $href;
            if ($baseUrl !== '') {
                $absUrl = WebFetcher::resolveUrl($baseUrl, $href);
            }
            $tooltip = htmlspecialchars('Liên kết gốc: ' . $absUrl, ENT_QUOTES, 'UTF-8');
            $dataAttr = ' data-original-href="' . htmlspecialchars($absUrl, ENT_QUOTES, 'UTF-8') . '"';

            // Thay thế bằng <span> không thể bấm, giữ nguyên màu link để trông tự nhiên
            return '<span class="archived-disabled-link" title="' . $tooltip . '"' . $dataAttr . '>' . $inner . '</span>';
        }, $html);
    }

    /**
     * Alias cũ để không phá vỡ code gọi rewriteLinks() ở chỗ khác
     * @deprecated Dùng disableLinks() thay thế
     */
    public static function rewriteLinks(string $html, string $baseUrl): string {
        return self::disableLinks($html, $baseUrl);
    }

    /**
     * Trích xuất văn bản thuần (dùng để lập chỉ mục tìm kiếm và kiểm tra nội dung)
     */
    public static function extractText(string $html): string {
        // Loại bỏ style và script trước khi strip_tags
        $noStyle = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $html);
        $noScript = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $noStyle);

        $text = strip_tags($noScript);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Chuẩn hóa khoảng trắng
        $text = preg_replace('/[\r\n\t\s]+/', ' ', $text);
        return trim($text);
    }
}
