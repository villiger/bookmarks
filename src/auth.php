<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mail.php';

const SESSION_DAYS = 90;
const LINK_MINUTES = 15;

function client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? 'cli'; }
function valid_email(string $e): bool { return strlen($e) <= 200 && filter_var($e, FILTER_VALIDATE_EMAIL) !== false; }

function current_user(): ?array {
    static $u = false;
    if ($u !== false) return $u;
    $u = null;
    $c = $_COOKIE['bm_sess'] ?? '';
    if (!is_string($c) || !preg_match('/^[0-9a-f]{64}$/', $c)) return null;
    $st = db()->prepare("SELECT u.* FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.hash=? AND s.expires>? AND u.status='active'");
    $st->execute([hash('sha256', $c), time()]);
    return $u = ($st->fetch() ?: null);
}

function require_login(bool $partial = false): array {
    $u = current_user();
    if ($u) return $u;
    if ($partial) { header('HX-Redirect: login.php'); http_response_code(401); exit; }
    header('Location: login.php'); exit;
}

function require_admin(bool $partial = false): array {
    $u = require_login($partial);
    if (!$u['is_admin']) { http_response_code(403); exit('Forbidden'); }
    return $u;
}

function start_session(int $uid): void {
    $raw = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO sessions(hash,user_id,created_at,expires) VALUES(?,?,?,?)')
        ->execute([hash('sha256', $raw), $uid, time(), time() + 86400 * SESSION_DAYS]);
    setcookie('bm_sess', $raw, ['expires' => time() + 86400 * SESSION_DAYS, 'path' => cookie_path(),
        'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax']);
}

function end_session(): void {
    $c = $_COOKIE['bm_sess'] ?? '';
    if (is_string($c)) db()->prepare('DELETE FROM sessions WHERE hash=?')->execute([hash('sha256', $c)]);
    setcookie('bm_sess', '', ['expires' => 1, 'path' => cookie_path(), 'httponly' => true, 'samesite' => 'Lax']);
}

/** Returns true if the action is over the limit; otherwise records it. */
function rate_limited(string $kind, string $key, int $max, int $window = 3600): bool {
    $pdo = db();
    $pdo->prepare('DELETE FROM rate_events WHERE ts < ?')->execute([time() - 86400]);
    $st = $pdo->prepare('SELECT COUNT(*) FROM rate_events WHERE kind=? AND key=? AND ts>=?');
    $st->execute([$kind, $key, time() - $window]);
    if ((int)$st->fetchColumn() >= $max) return true;
    $pdo->prepare('INSERT INTO rate_events(kind,key,ts) VALUES(?,?,?)')->execute([$kind, $key, time()]);
    return false;
}

function new_login_link(int $uid): string {
    $raw = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO login_tokens(hash,user_id,expires) VALUES(?,?,?)')->execute([hash('sha256', $raw), $uid, time() + 60 * LINK_MINUTES]);
    return base_url() . '/verify.php?t=' . $raw;
}

function email_login_link(array $user): void {
    $link = new_login_link((int)$user['id']);
    send_mail($user['email'], 'Your Bookmarks login link', "Click to log in (valid " . LINK_MINUTES . " minutes, single use):\n\n$link\n\nIf you did not request this, ignore this mail.\n");
}

/** Consume token; returns user id or null. Atomic single-use. */
function consume_login_token(string $raw): ?int {
    if (!preg_match('/^[0-9a-f]{64}$/', $raw)) return null;
    $pdo = db();
    $h = hash('sha256', $raw);
    $st = $pdo->prepare('DELETE FROM login_tokens WHERE hash=? AND expires>? RETURNING user_id');
    $st->execute([$h, time()]);
    $uid = $st->fetchColumn();
    if (!$uid) return null;
    $a = $pdo->prepare("SELECT 1 FROM users WHERE id=? AND status='active'"); $a->execute([$uid]);
    return $a->fetchColumn() ? (int)$uid : null;
}

function create_user(string $email, bool $admin, string $status): int {
    db()->prepare('INSERT INTO users(email,is_admin,status,bookmarklet_key) VALUES(?,?,?,?)')
        ->execute([strtolower($email), $admin ? 1 : 0, $status, bin2hex(random_bytes(32))]);
    return (int)db()->lastInsertId();
}

function find_user_by_email(string $email): ?array {
    $st = db()->prepare('SELECT * FROM users WHERE email=?');
    $st->execute([strtolower($email)]);
    return $st->fetch() ?: null;
}
