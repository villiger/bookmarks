<?php
require_once __DIR__ . '/config.php';

/** @return array<array{0:string,1:bool,2:string[]}> [name-or-prefix, isPrefix, hosts] */
function strip_rules(): array {
    static $rules = null;
    if ($rules !== null) return $rules;
    $rules = [];
    $f = cfg('url_strip_file') ?: __DIR__ . '/../url_strip_params.txt';
    foreach (is_file($f) ? file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $l) {
        $l = strtolower(trim($l));
        if ($l === '' || $l[0] === '#') continue;
        [$n, $h] = array_pad(explode('@', $l, 2), 2, '');
        $pre = str_ends_with($n, '*');
        $rules[] = [rtrim($n, '*'), $pre, $h === '' ? [] : array_map('trim', explode(',', $h))];
    }
    return $rules;
}

function host_matches(string $host, array $hosts): bool {
    if (!$hosts) return true;
    foreach ($hosts as $h) if ($host === $h || str_ends_with($host, '.' . $h)) return true;
    return false;
}

function is_strip_param(string $name, string $host): bool {
    $name = strtolower($name);
    foreach (strip_rules() as [$n, $pre, $hosts]) {
        if (!host_matches($host, $hosts)) continue;
        if ($pre ? str_starts_with($name, $n) : $name === $n) return true;
    }
    return false;
}

function query_get(string $query, string $key): ?string {
    foreach (explode('&', $query) as $p) {
        [$k, $v] = array_pad(explode('=', $p, 2), 2, '');
        if (urldecode($k) === $key) return urldecode($v);
    }
    return null;
}

/** Unwrap known redirect wrappers (google, facebook, reddit, youtube). */
function unwrap_redirect(string $url): string {
    for ($i = 0; $i < 3; $i++) {
        $p = parse_url($url);
        if (!$p || empty($p['host'])) return $url;
        $h = strtolower(preg_replace('/^www\./', '', $p['host']));
        $path = $p['path'] ?? '/'; $q = $p['query'] ?? '';
        $t = null;
        if (preg_match('/^google\.[a-z.]+$/', $h) && $path === '/url') $t = query_get($q, 'q') ?? query_get($q, 'url');
        elseif (in_array($h, ['l.facebook.com', 'lm.facebook.com'], true) && $path === '/l.php') $t = query_get($q, 'u');
        elseif ($h === 'out.reddit.com') $t = query_get($q, 'url');
        elseif (($h === 'youtube.com' || $h === 'm.youtube.com') && $path === '/redirect') $t = query_get($q, 'q');
        if (!$t || !preg_match('~^https?://~i', $t)) return $url;
        $url = $t;
    }
    return $url;
}

function clean_url(string $url): string {
    $url = unwrap_redirect(trim($url));
    $p = parse_url($url);
    if (!$p || empty($p['host'])) return $url;
    $scheme = strtolower($p['scheme'] ?? 'https');
    $host = strtolower($p['host']);
    $port = isset($p['port']) ? ':' . $p['port'] : '';
    $path = preg_replace('/;jsessionid=[^\/?#]*/i', '', $p['path'] ?? '');
    $query = $p['query'] ?? '';
    $frag = $p['fragment'] ?? '';
    $bare = preg_replace('/^www\./', '', $host);

    // YouTube: one canonical form
    $vid = null;
    if ($bare === 'youtu.be' && preg_match('~^/([\w-]{11})~', $path, $m)) $vid = $m[1];
    elseif (in_array($bare, ['youtube.com', 'm.youtube.com'], true) && preg_match('~^/shorts/([\w-]{11})~', $path, $m)) $vid = $m[1];
    if ($vid) { $host = 'www.youtube.com'; $path = '/watch'; $query = 'v=' . $vid . ($query !== '' ? '&' . $query : ''); $bare = 'youtube.com'; }
    elseif (in_array($bare, ['youtube.com', 'm.youtube.com'], true) && $path === '/watch') { $host = 'www.youtube.com'; $bare = 'youtube.com'; }

    // Amazon: /dp/ASIN only
    if (preg_match('/^amazon\.([a-z.]+)$/', $bare, $m) && preg_match('~/(?:dp|gp/product|gp/aw/d)/([A-Z0-9]{10})~i', $path, $a)) {
        return 'https://www.amazon.' . $m[1] . '/dp/' . strtoupper($a[1]);
    }

    $keep = [];
    foreach (explode('&', $query) as $part) {
        if ($part === '') continue;
        $name = urldecode(explode('=', $part, 2)[0]);
        if (is_strip_param($name, $bare)) continue;
        $keep[] = $part;
    }
    if ($frag !== '' && (str_starts_with($frag, ':~:') || preg_match('/^(xtor=|utm_|fbclid|gclid|ref=)/i', $frag))) $frag = '';
    return $scheme . '://' . (isset($p['user']) ? $p['user'] . (isset($p['pass']) ? ':' . $p['pass'] : '') . '@' : '') . $host . $port . $path
        . ($keep ? '?' . implode('&', $keep) : '') . ($frag !== '' ? '#' . $frag : '');
}

/** Dedupe key: ignore scheme, leading www., trailing slash, fragment. */
function url_key(string $url): string {
    $p = parse_url($url);
    if (!$p || empty($p['host'])) return strtolower($url);
    $host = preg_replace('/^www\./', '', strtolower($p['host']));
    return $host . (isset($p['port']) ? ':' . $p['port'] : '') . rtrim($p['path'] ?? '', '/') . (isset($p['query']) ? '?' . $p['query'] : '');
}

/**
 * Lenient user input -> ['url'=>cleaned, 'guessed'=>bool] or ['error'=>msg].
 * Accepts bla.com, www.bla.com/path, //bla.com, with/without http(s).
 */
function normalize_input(string $raw): array {
    $raw = trim($raw);
    if ($raw === '' || strlen($raw) > 2000) return ['error' => 'Empty or too long'];
    if (preg_match('/\s/', $raw)) return ['error' => 'URL must not contain spaces'];
    $guessed = false;
    if (str_starts_with($raw, '//')) { $raw = 'https:' . $raw; $guessed = true; }
    elseif (preg_match('~^([a-z][a-z0-9+.-]*)://~i', $raw, $m)) {
        if (!in_array(strtolower($m[1]), ['http', 'https'], true)) return ['error' => 'Only http(s) URLs are supported'];
    } elseif (preg_match('/^(javascript|data|mailto|file|ftp|about|vbscript|tel|blob):/i', $raw)) {
        return ['error' => 'Only http(s) URLs are supported'];
    } else { $raw = 'https://' . $raw; $guessed = true; }
    $p = parse_url($raw);
    $host = $p['host'] ?? '';
    if ($host === '' || !str_contains($host, '.') || !preg_match('/^[\p{L}\p{N}.-]+$/u', $host) || str_starts_with($host, '.') || str_ends_with($host, '.') || str_contains($host, '..')) {
        return ['error' => 'Not a valid host: ' . $host];
    }
    return ['url' => clean_url($raw), 'guessed' => $guessed];
}

/** Lowercase host without a leading www. ('' if the URL has none). */
function host_of(string $url): string { return preg_replace('/^www\./', '', strtolower(parse_url($url, PHP_URL_HOST) ?: '')); }
