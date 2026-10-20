<?php
const BM_UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
const BM_MAX_BYTES = 2000000;

/** @return array{body:string,url:string,type:string,code:int,error:?string} */
function http_get(string $url, int $timeout = 10): array {
    $body = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => $timeout, CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => BM_UA, CURLOPT_ENCODING => '',
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.5', 'Accept-Language: en,de;q=0.8'],
        CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$body) {
            $body .= $data;
            return strlen($body) > BM_MAX_BYTES ? -1 : strlen($data);   // abort when too large
        },
    ]);
    $ok = curl_exec($ch);
    $err = $ok ? null : curl_error($ch);
    $truncated = strlen($body) > BM_MAX_BYTES;
    if ($truncated) { $err = null; $body = substr($body, 0, BM_MAX_BYTES); }
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $res = ['body' => $body, 'url' => curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url,
            'type' => (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE), 'code' => $code, 'error' => $err];
    if (!$res['error'] && $code >= 400) $res['error'] = "HTTP $code";
    return $res;
}

function resolve_url(string $href, string $base): string {
    $href = trim($href);
    if ($href === '') return '';
    if (preg_match('~^https?://~i', $href)) return $href;
    $p = parse_url($base);
    if (!$p || empty($p['host'])) return '';
    $origin = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    if (str_starts_with($href, '//')) return $p['scheme'] . ':' . $href;
    if ($href[0] === '/') return $origin . $href;
    if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $href)) return '';   // data:, javascript: ...
    $path = $p['path'] ?? '/';
    $dir = substr($path, 0, (int)strrpos($path, '/') + 1);
    $full = $dir . $href;
    $out = [];
    foreach (explode('/', $full) as $seg) {
        if ($seg === '..') array_pop($out); elseif ($seg !== '.') $out[] = $seg;
    }
    return $origin . implode('/', $out);
}

function youtube_id(string $url): ?string {
    $p = parse_url($url);
    $host = strtolower(preg_replace('/^(www|m)\./', '', $p['host'] ?? ''));
    $path = $p['path'] ?? '';
    if ($host === 'youtu.be') return preg_match('~^/([\w-]{11})~', $path, $m) ? $m[1] : null;
    if ($host === 'youtube.com' || $host === 'music.youtube.com') {
        if ($path === '/watch') { parse_str($p['query'] ?? '', $q); return isset($q['v']) && preg_match('/^[\w-]{11}$/', $q['v']) ? $q['v'] : null; }
        if (preg_match('~^/(?:shorts|embed|live)/([\w-]{11})~', $path, $m)) return $m[1];
    }
    return null;
}

function clean_text(string $s): string {
    return trim(preg_replace('/\s+/u', ' ', $s));
}

function to_utf8(string $html, string $type): string {
    $cs = null;
    if (preg_match('/charset=["\']?([\w-]+)/i', $type, $m)) $cs = $m[1];
    elseif (preg_match('/<meta[^>]+charset=["\']?([\w-]+)/i', substr($html, 0, 4096), $m)) $cs = $m[1];
    if ($cs && !preg_match('/^utf-?8$/i', $cs)) {
        $c = @mb_convert_encoding($html, 'UTF-8', $cs);
        if ($c !== false) return $c;
    }
    return mb_convert_encoding($html, 'UTF-8', 'UTF-8');   // scrub invalid bytes
}

/** Crawl one URL. Returns fields: title, description, keywords, image, favicon, body. Throws RuntimeException on failure. */
function crawl(string $url): array {
    $out = ['title' => '', 'description' => '', 'keywords' => '', 'image' => '', 'favicon' => '', 'body' => ''];
    $origin = (($p = parse_url($url)) && isset($p['host'])) ? $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') : '';
    $yt = youtube_id($url);
    if ($yt) {
        $r = http_get('https://www.youtube.com/oembed?format=json&url=' . rawurlencode($url));
        $j = $r['error'] ? null : json_decode($r['body'], true);
        if (is_array($j)) {
            $out['title'] = clean_text($j['title'] ?? '');
            $out['description'] = isset($j['author_name']) ? 'YouTube video by ' . $j['author_name'] : '';
            $out['body'] = clean_text(($j['title'] ?? '') . ' ' . ($j['author_name'] ?? ''));
            $out['image'] = "https://i.ytimg.com/vi/$yt/hqdefault.jpg";
            $out['favicon'] = 'https://www.youtube.com/favicon.ico';
            $out['keywords'] = 'youtube video';
            return $out;
        }
        // oEmbed failed (private/removed video?) -> fall through to normal crawl, but keep thumbnail
        $out['image'] = "https://i.ytimg.com/vi/$yt/hqdefault.jpg";
    }
    $r = http_get($url);
    if ($r['error']) throw new RuntimeException($r['error']);
    if ($r['type'] !== '' && !preg_match('~html|xml|text/plain~i', $r['type'])) {
        throw new RuntimeException('Not an HTML page: ' . $r['type']);
    }
    $final = $r['url'];
    $html = to_utf8($r['body'], $r['type']);
    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
    libxml_clear_errors();
    $xp = new DOMXPath($doc);
    $val = function (string $q, string $attr = 'content') use ($xp): string {
        $n = $xp->query($q);
        return $n && $n->length ? clean_text($n->item(0)->getAttribute($attr)) : '';
    };
    $og = fn(string $prop) => $val("//meta[@property='$prop' or @name='$prop']");
    $t = $xp->query('//title');
    $title = $og('og:title') ?: ($t && $t->length ? clean_text($t->item(0)->textContent) : '');
    $out['title'] = mb_substr($title, 0, 300);
    $out['description'] = mb_substr($og('og:description') ?: $val("//meta[translate(@name,'DESCRIPTION','description')='description']"), 0, 600);
    $out['keywords'] = mb_substr($val("//meta[translate(@name,'KEYWORDS','keywords')='keywords']"), 0, 300);
    $img = $og('og:image') ?: $og('twitter:image');
    $out['image'] = $img ? resolve_url($img, $final) : $out['image'];
    $fav = '';
    foreach (["//link[contains(concat(' ',normalize-space(@rel),' '),' icon ')]", "//link[@rel='shortcut icon']", "//link[contains(@rel,'apple-touch-icon')]"] as $q) {
        $fav = $val($q, 'href');
        if ($fav) break;
    }
    $fp = parse_url($final);
    $finalOrigin = isset($fp['host']) ? $fp['scheme'] . '://' . $fp['host'] . (isset($fp['port']) ? ':' . $fp['port'] : '') : $origin;
    $out['favicon'] = ($fav ? resolve_url($fav, $final) : '') ?: $finalOrigin . '/favicon.ico';
    // main text
    foreach (['script', 'style', 'nav', 'header', 'footer', 'aside', 'noscript', 'svg', 'form', 'iframe', 'template'] as $tag) {
        foreach (iterator_to_array($doc->getElementsByTagName($tag)) as $n) $n->parentNode?->removeChild($n);
    }
    $root = null;
    foreach (['//main', '//article', '//body'] as $q) {
        $n = $xp->query($q);
        if ($n && $n->length && mb_strlen(clean_text($n->item(0)->textContent)) > 200) { $root = $n->item(0); break; }
        if ($n && $n->length && !$root) $root = $n->item(0);
    }
    $text = $root ? clean_text($root->textContent) : '';
    $out['body'] = mb_substr($text, 0, 1500);
    return $out;
}
