<?php
function cfg(?string $key = null) {
    static $c = null;
    if ($c === null) {
        $f = __DIR__ . '/../config.php';
        if (!is_file($f)) { http_response_code(500); exit("config.php missing\n"); }
        $c = require $f;
    }
    return $key === null ? $c : ($c[$key] ?? null);
}

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function session_value(): string { return hash_hmac('sha256', 'bookmarks-session', cfg('token') . '|' . cfg('password_hash')); }

function cookie_path(): string { return rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/'; }

function is_logged_in(): bool {
    return isset($_COOKIE['bm_auth']) && hash_equals(session_value(), (string)$_COOKIE['bm_auth']);
}

function require_login(bool $partial = false): void {
    if (is_logged_in()) return;
    if ($partial) { header('HX-Redirect: login.php'); http_response_code(401); exit; }
    header('Location: login.php'); exit;
}
