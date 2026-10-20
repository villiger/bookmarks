<?php
const BM_UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
const BM_UA_ALT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
const BM_MAX_BYTES = 2000000;

/** @return array{body:string,url:string,type:string,code:int,error:?string} */
function http_get(string $url, int $timeout = 10, bool $alt = false): array {
    $body = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => $timeout, CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => $alt ? BM_UA_ALT : BM_UA, CURLOPT_ENCODING => '',
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => $alt
            ? ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8', 'Accept-Language: en-US,en;q=0.9,de;q=0.8', 'Upgrade-Insecure-Requests: 1', 'Sec-Fetch-Dest: document', 'Sec-Fetch-Mode: navigate', 'Sec-Fetch-Site: none']
            : ['Accept: text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.5', 'Accept-Language: en,de;q=0.8'],
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

/** description (800 chars), keywords[] and category from the watch page's ytInitialPlayerResponse */
function youtube_details(string $id): array {
    $out = ['desc' => '', 'keywords' => [], 'category' => ''];
    $r = http_get('https://www.youtube.com/watch?v=' . $id . '&hl=en');
    if ($r['error']) return $out;
    $b = $r['body'];
    if (preg_match('/"shortDescription":("(?:[^"\\\\]|\\\\.)*")/', $b, $m) && is_string($d = json_decode($m[1]))) $out['desc'] = mb_substr(clean_text($d), 0, 800);
    if (preg_match('/"keywords":(\[(?:"(?:[^"\\\\]|\\\\.)*",?)*\])/', $b, $m) && is_array($k = json_decode($m[1], true))) $out['keywords'] = array_slice(array_map('strval', $k), 0, 25);
    if (preg_match('/"category":("(?:[^"\\\\]|\\\\.)*")/', $b, $m) && is_string($c = json_decode($m[1]))) $out['category'] = $c;
    return $out;
}

/** Optional (config youtube_api_key): topicCategories (Wikipedia slugs) + snippet tags as extra keywords. */
function youtube_api_keywords(string $id): string {
    $key = cfg('youtube_api_key');
    if (!$key) return '';
    $r = http_get('https://www.googleapis.com/youtube/v3/videos?part=topicDetails,snippet&id=' . $id . '&key=' . rawurlencode($key));
    $j = $r['error'] ? null : json_decode($r['body'], true);
    $it = $j['items'][0] ?? [];
    $topics = array_map(fn($u) => str_replace('_', ' ', urldecode(basename($u))), $it['topicDetails']['topicCategories'] ?? []);
    return implode(', ', array_merge($topics, $it['snippet']['tags'] ?? []));
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
function crawl(string $url, bool $alt = false): array {
    $out = ['title' => '', 'description' => '', 'keywords' => '', 'image' => '', 'favicon' => '', 'body' => '', 'channel' => '', 'yt_category' => '', 'site_name' => '', 'author' => '', 'final_url' => $url];
    $origin = (($p = parse_url($url)) && isset($p['host'])) ? $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') : '';
    $yt = youtube_id($url);
    if ($yt) {
        $r = http_get('https://www.youtube.com/oembed?format=json&url=' . rawurlencode($url));
        $j = $r['error'] ? null : json_decode($r['body'], true);
        if (is_array($j)) {
            $out['title'] = clean_text($j['title'] ?? '');
            $out['description'] = isset($j['author_name']) ? 'YouTube video by ' . $j['author_name'] : '';
            $out['channel'] = $j['author_name'] ?? '';
            // Real video description (first ~500 chars) from the watch page's ytInitialPlayerResponse
            $vd = youtube_details($yt);
            $out['body'] = clean_text(($j['title'] ?? '') . ' ' . ($j['author_name'] ?? '') . ' ' . $vd['desc']);
            $out['yt_category'] = $vd['category'];
            $out['image'] = "https://i.ytimg.com/vi/$yt/hqdefault.jpg";
            $out['favicon'] = 'https://www.youtube.com/favicon.ico';
            $out['keywords'] = implode(', ', array_filter([implode(', ', $vd['keywords']), youtube_api_keywords($yt)]));
            $out['final_url'] = 'https://www.youtube.com/watch?v=' . $yt;
            return $out;
        }
        // oEmbed failed (private/removed video?) -> fall through to normal crawl, but keep thumbnail
        $out['image'] = "https://i.ytimg.com/vi/$yt/hqdefault.jpg";
    }
    $r = http_get($url, 10, $alt);
    if ($r['error']) throw new RuntimeException($r['error']);
    if ($r['type'] !== '' && !preg_match('~html|xml|text/plain~i', $r['type'])) {
        throw new RuntimeException('Not an HTML page: ' . $r['type']);
    }
    $final = $r['url'];
    $out['final_url'] = $final;
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
    $out['description'] = mb_substr($og('og:description') ?: $val("//meta[translate(@name,'DESCRIPTION','description')='description']") ?: $val("//meta[@name='DC.Description' or @name='DC.description']"), 0, 600);
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
    $sd = structured_data($xp, strtolower($fp['host'] ?? ''));                 // structured data first
    $out['site_name'] = $og('og:site_name');
    if ($sd['title'] && (preg_match('/(^|\.)amazon\./', $fp['host'] ?? '') || !$og('og:title'))) $out['title'] = mb_substr($sd['title'], 0, 300);
    if ($sd['description']) $out['description'] = mb_substr($sd['description'], 0, 600);
    elseif (mb_strtolower($out['description']) === mb_strtolower($out['title'])) $out['description'] = '';
    if ($sd['image'] && preg_match('~^https?://~i', $sd['image'])) $out['image'] = $sd['image'];
    $out['author'] = mb_substr($sd['author'], 0, 120);
    $metas = [];
    foreach ($xp->query('//meta[@name or @property]') as $m) $metas[] = strtolower($m->getAttribute('name') ?: $m->getAttribute('property'));
    $out['signals'] = ['types' => array_values(array_unique($sd['types'])), 'og_type' => strtolower($og('og:type')), 'metas' => array_values(array_unique($metas)),
        'isbn' => $sd['isbn'] || (bool)preg_match('/\bISBN(?:-1[03])?\s*[:#]?\s*(?:97[89][-\s]?)?\d[-\s\d]{8,15}[\dX]\b/i', substr($html, 0, 400000)), 'html' => substr($html, 0, 300000)];
    // main text
    foreach (['script', 'style', 'nav', 'header', 'footer', 'aside', 'noscript', 'svg', 'form', 'iframe', 'template'] as $tag) {
        foreach (iterator_to_array($doc->getElementsByTagName($tag)) as $n) $n->parentNode?->removeChild($n);
    }
    $noise = "//*[not(self::body or self::html or self::main or self::article)][@class or @id][contains(translate(concat(@class,' ',@id),'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'cookie') or contains(translate(concat(@class,' ',@id),'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'consent') or contains(translate(concat(@class,' ',@id),'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'banner') or contains(translate(concat(@class,' ',@id),'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'newsletter') or contains(translate(concat(@class,' ',@id),'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'sidebar') or contains(translate(concat(@class,' ',@id),'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'breadcrumb') or contains(translate(concat(@class,' ',@id),'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'popup') or contains(translate(concat(@class,' ',@id),'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'menu') or contains(translate(concat(@class,' ',@id),'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'footer') or contains(translate(concat(@class,' ',@id),'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'navbar') or @role='navigation' or @role='banner' or @role='dialog']";
    foreach (iterator_to_array($xp->query($noise) ?: []) as $n) $n->parentNode?->removeChild($n);
    $text = main_text($doc, $xp);
    $out['body'] = mb_substr(preg_match('/(^|\.)amazon\./', $fp['host'] ?? '') && $sd['description'] ? $sd['description'] : trim($sd['description'] . ' ' . $text), 0, 1500);
    if (preg_match('/just a moment|attention required|access denied|captcha|are you a robot|verify you are human|pardon our interruption/i', $out['title'])
        || preg_match('/enable javascript and cookies|checking your browser|cf-chl/i', $text . $html)) {
        throw new RuntimeException('Blocked: challenge page');
    }
    return $out;
}

/** URLs to try for a bookmark whose scheme was guessed: https, http, then the www. variants. */
function crawl_candidates(string $url, bool $guessed): array {
    if (!$guessed) return [$url];
    $p = parse_url($url);
    $host = $p['host'] ?? '';
    $rest = substr($url, strlen(($p['scheme'] ?? 'https') . '://' . $host));
    $hosts = [$host];
    if (!str_starts_with($host, 'www.')) $hosts[] = 'www.' . $host;
    $c = [];
    foreach ($hosts as $h) foreach (['https', 'http'] as $s) $c[] = "$s://$h$rest";
    return $c;
}

function crawl_any(array $candidates, bool $alt = false): array {
    $last = null;
    foreach ($candidates as $u) {
        try { return crawl($u, $alt); } catch (Throwable $e) { $last = $e; }
    }
    throw $last ?? new RuntimeException('no URL');
}

/** Last-resort reader proxy (title + text of the page). */
function crawl_jina(string $url): array {
    $r = http_get('https://r.jina.ai/' . $url, 25);
    if ($r['error'] || trim($r['body']) === '') throw new RuntimeException('jina: ' . ($r['error'] ?: 'empty'));
    $b = $r['body'];
    $title = preg_match('/^Title:\s*(.+)$/m', $b, $m) ? clean_text($m[1]) : '';
    $text = preg_match('/Markdown Content:\s*(.*)$/s', $b, $m) ? $m[1] : $b;
    $text = clean_text(preg_replace(['/!\[[^\]]*\]\([^)]*\)/', '/\[([^\]]*)\]\([^)]*\)/', '/[#*`>_]+/'], ['', '$1', ' '], $text));
    if (mb_strlen($text) < 40) throw new RuntimeException('jina: no content');
    return ['title' => mb_substr($title, 0, 300), 'description' => '', 'keywords' => '', 'image' => '', 'favicon' => '', 'body' => mb_substr($text, 0, 1500), 'channel' => '', 'yt_category' => '', 'final_url' => $url];
}

function crawl_thin(array $c): bool { return mb_strlen($c['body']) < 150 && $c['description'] === ''; }

/**
 * Crawl with fallbacks: direct -> desktop UA retry -> r.jina.ai -> data sent by the bookmarklet (client_*).
 * @param array $b bookmark row (client_* columns); returns [crawl result, source direct|client|jina]
 */
function crawl_resilient(array $b): array {
    $cands = crawl_candidates($b['url'], (bool)$b['scheme_guessed']);
    $best = null; $src = 'direct'; $err = null;
    $try = function (callable $f, string $name) use (&$best, &$src, &$err) {
        try { $c = $f(); if (!$best || mb_strlen($c['body']) > mb_strlen($best['body'])) { $best = $c; $src = $name; } } catch (Throwable $e) { $err = $e; }
    };
    $try(fn() => crawl_any($cands), 'direct');
    if (!$best || crawl_thin($best)) $try(fn() => crawl_any($cands, true), 'direct');
    if (!$best || crawl_thin($best)) $try(fn() => crawl_jina($b['url']), 'jina');
    $ct = trim((string)($b['client_text'] ?? ''));
    if (trim((string)($b['client_title'] ?? '')) !== '' || $ct !== '') {
        $best ??= ['title' => '', 'description' => '', 'keywords' => '', 'image' => '', 'favicon' => '', 'body' => '', 'channel' => '', 'yt_category' => '', 'final_url' => $b['url']];
        if (!$best['body'] && !$best['title']) $src = 'client';
        if (mb_strlen($ct) > mb_strlen($best['body'])) { $best['body'] = mb_substr($ct, 0, 1500); $src = 'client'; }
        if ($b['client_title']) $best['title'] = $b['client_title'];
        foreach (['description' => 'client_desc', 'image' => 'client_image', 'favicon' => 'client_favicon'] as $k => $f) if (!$best[$k] && $b[$f]) $best[$k] = $b[$f];
        if ($b['client_keywords']) $best['keywords'] = trim($best['keywords'] . ', ' . $b['client_keywords'], ', ');
    }
    if (!$best) throw $err ?? new RuntimeException('crawl failed');
    $best += ['site_name' => '', 'author' => '', 'signals' => []];
    if (!$best['favicon'] || str_ends_with($best['favicon'], '/favicon.ico') && $src !== 'direct') {
        $best['favicon'] = 'https://www.google.com/s2/favicons?domain=' . rawurlencode(parse_url($b['url'], PHP_URL_HOST) ?: '') . '&sz=64';
    }
    return [$best, $src];
}

/** Plain text, whitespace collapsed, cut at a sentence end within ~220 chars, else at a word boundary with an ellipsis. */
function clean_cut(string $s, int $max = 220): string {
    $s = clean_text(html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if (mb_strlen($s) <= $max) return $s;
    $head = mb_substr($s, 0, $max);
    if (preg_match('/^(.{40,}[.!?])(?=\s|$)/us', $head, $m)) return $m[1];       // last sentence end inside the limit
    $cut = mb_strrpos($head, ' ');
    return rtrim(mb_substr($head, 0, $cut > 40 ? $cut : $max), " ,;:-–—") . '…';
}

/** Readability-style main text: drop link-dense blocks, prefer main/article, else the block with the most non-link text. */
function main_text(DOMDocument $doc, DOMXPath $xp): string {
    $len = fn(DOMNode $n) => mb_strlen(clean_text($n->textContent));
    $links = function (DOMNode $n) use ($xp, $len) { $s = 0; foreach ($xp->query('.//a', $n) as $a) $s += $len($a); return $s; };
    $blocks = iterator_to_array($xp->query('//ul | //ol | //table | //dl | //div | //p | //td | //li'));
    foreach (array_reverse(array_slice($blocks, 0, 1500)) as $n) {            // innermost first
        $t = $len($n);
        if ($t > 15 && $links($n) / $t > 0.5) $n->parentNode?->removeChild($n);
    }
    foreach (['//main', '//article', '//*[@role="main"]'] as $q) {
        $n = $xp->query($q)->item(0);
        if ($n && $len($n) > 200) return clean_text($n->textContent);
    }
    $best = null; $top = 0;
    foreach (array_slice(iterator_to_array($xp->query('//body | //div | //td | //section | //blockquote')), 0, 600) as $n) {
        $t = $len($n); $l = $links($n);
        $score = ($t - $l) * (1 - $l / max(1, $t)) ** 2;
        if ($score > $top) { $top = $score; $best = $n; }
    }
    return $best ? clean_text($best->textContent) : '';
}

/** First sentences of the page text, but only if it reads like prose (not navigation); '' otherwise. */
function prose_excerpt(string $body, int $max = 200): string {
    $b = clean_text($body);
    $head = mb_substr($b, 0, 400);
    $words = preg_split('/\s+/u', $head, -1, PREG_SPLIT_NO_EMPTY);
    if (count($words) < 12 || !preg_match('/[.!?](\s|$)/u', $head)) return '';
    $short = count(array_filter($words, fn($w) => mb_strlen($w) <= 2)) / count($words);
    $digits = preg_match_all('/\d/', $head) / max(1, mb_strlen($head));
    $caps = count(array_filter($words, fn($w) => preg_match('/^\p{Lu}/u', $w))) / count($words);
    return ($short > 0.4 || $digits > 0.08 || $caps > 0.6) ? '' : clean_cut($b, $max);
}

/** schema.org JSON-LD (Book/Product/Article/Video/Recipe...) first, then a small Amazon extractor. */
function structured_data(DOMXPath $xp, string $host): array {
    $o = ['title' => '', 'description' => '', 'image' => '', 'author' => '', 'types' => [], 'isbn' => false];
    $str = fn($v) => is_array($v) ? (isset($v['name']) ? (string)$v['name'] : (isset($v['url']) ? (string)$v['url'] : (is_string(reset($v)) ? reset($v) : (string)(reset($v)['name'] ?? reset($v)['url'] ?? '')))) : (string)$v;
    foreach ($xp->query('//script[@type="application/ld+json"]') as $sc) {
        $j = json_decode(trim($sc->textContent), true);
        if (!is_array($j)) continue;
        foreach (isset($j['@graph']) ? $j['@graph'] : (array_is_list($j) ? $j : [$j]) as $it) {
            if (is_array($it)) { $o['types'] = array_merge($o['types'], (array)($it['@type'] ?? [])); $o['isbn'] = $o['isbn'] || isset($it['isbn']); }
            if (!is_array($it) || !array_intersect((array)($it['@type'] ?? []), ['Book', 'Product', 'Article', 'NewsArticle', 'BlogPosting', 'VideoObject', 'Recipe', 'Movie', 'Course', 'SoftwareApplication', 'MusicRecording'])) continue;
            $o['title'] = $o['title'] ?: clean_text($str($it['name'] ?? $it['headline'] ?? ''));
            $o['description'] = $o['description'] ?: clean_text(strip_tags($str($it['description'] ?? '')));
            $o['image'] = $o['image'] ?: $str($it['image'] ?? '');
            $o['author'] = $o['author'] ?: clean_text($str($it['author'] ?? ''));
        }
    }
    foreach ($xp->query('//*[@itemtype]') as $n) $o['types'][] = basename($n->getAttribute('itemtype'));
    if (preg_match('/(^|\.)amazon\./', $host)) {
        $q = fn($x) => ($n = $xp->query($x)->item(0)) ? clean_text($n->textContent) : '';
        $o['title'] = $q('//*[@id="productTitle"]') ?: $o['title'];
        $o['author'] = implode(', ', array_slice(array_unique(array_filter(array_map(fn($a) => clean_text($a->textContent), iterator_to_array($xp->query('//*[@id="bylineInfo"]//a[contains(@class,"author") or contains(@href,"/e/")]'))))), 0, 3)) ?: $o['author'];
        $d = $xp->query('//*[@id="bookDescription_feature_div"]//noscript | //*[@id="bookDescription_feature_div"]')->item(0);
        $dt = '';
        if ($d) { $dt = clean_text(strip_tags(html_entity_decode($d->textContent))); }
        $o['description'] = preg_replace('/\s*Read more\s*$/i', '', $dt) ?: $o['description'];
        $img = $xp->query('//*[@id="landingImage" or @id="imgBlkFront" or @id="ebooksImgBlkFront"]')->item(0);
        if ($img) {
            $dyn = json_decode($img->getAttribute('data-a-dynamic-image') ?: '{}', true);
            $o['image'] = $img->getAttribute('data-old-hires') ?: ($dyn ? array_key_first($dyn) : '') ?: $img->getAttribute('src') ?: $o['image'];
        }
    }
    return $o;
}

/** Brand words (host labels, site name) that must not become tags or stay in titles. */
function brand_tokens(string $url, string $site = ''): array {
    $labels = array_slice(explode('.', preg_replace('/^www\./', '', strtolower(parse_url($url, PHP_URL_HOST) ?: ''))), 0, -1);
    $words = array_merge($labels, preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($site), -1, PREG_SPLIT_NO_EMPTY));
    return array_values(array_unique(array_filter($words, fn($w) => mb_strlen($w) >= 4 && !in_array($w, ['news', 'blog', 'home', 'online', 'shop', 'store'], true))));
}

/** Strip site-name parts ("... : Amazon.it: Books", "The Avalon Project : X") from a page title. */
function clean_title(string $title, string $url, string $site = ''): string {
    $title = clean_text($title);
    $brands = brand_tokens($url, $site);
    if (!$brands) return $title;
    $parts = preg_split('/\s+[|\-–—·•»]\s+|\s+:\s+/u', $title);
    $keep = array_values(array_filter($parts, fn($p) => !array_filter($brands, fn($b) => str_contains(preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($p)), $b))));
    if (!$keep || count($keep) === count($parts)) return $title;
    $out = implode(' – ', $keep);
    if (count(preg_split('/\s+/u', $out)) < 3 && count(preg_split('/\s+/u', $title)) >= 3) {
        $out = array_reduce($keep, fn($a, $p) => mb_strlen($p) > mb_strlen((string)$a) ? $p : $a, '');   // keep the longest remaining part
        if (count(preg_split('/\s+/u', $out)) < 3) return $title;
    }
    return $out;
}
