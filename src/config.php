<?php
function cfg(?string $key = null) {
    static $c = null;
    if ($c === null) {
        $f = getenv('BM_CONFIG') ?: __DIR__ . '/../config.php';   // BM_CONFIG: alternative config file (tests)
        if (!is_file($f)) { http_response_code(500); exit("config.php missing\n"); }
        $c = require $f;
    }
    return $key === null ? $c : ($c[$key] ?? null);
}

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function cookie_path(): string { return rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/'; }

function base_url(): string {
    $b = cfg('base_url');
    if ($b) return rtrim($b, '/');
    return (!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
}
