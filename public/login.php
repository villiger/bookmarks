<?php
require_once __DIR__ . '/../src/db.php';
header('Cache-Control: no-store');
$err = '';
$ip = $_SERVER['REMOTE_ADDR'] ?? '?';
$pdo = db();
$pdo->prepare('DELETE FROM login_failures WHERE ts < ?')->execute([time() - 900]);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $n = $pdo->prepare('SELECT COUNT(*) FROM login_failures WHERE ip=? AND ts >= ?');
    $n->execute([$ip, time() - 900]);
    if ((int)$n->fetchColumn() >= 10) {
        http_response_code(429);
        $err = 'Too many failed attempts. Try again in 15 minutes.';
    } else {
        $hash = (string)cfg('password_hash');
        if ($hash !== '' && password_verify((string)($_POST['password'] ?? ''), $hash)) {
            $pdo->prepare('DELETE FROM login_failures WHERE ip=?')->execute([$ip]);
            setcookie('bm_auth', session_value(), [
                'expires' => time() + 86400 * 30, 'path' => cookie_path(),
                'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax',
            ]);
            header('Location: ./'); exit;
        }
        $pdo->prepare('INSERT INTO login_failures(ip, ts) VALUES(?,?)')->execute([$ip, time()]);
        sleep(1);
        $err = 'Wrong password.';
    }
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex"><title>Login · Bookmarks</title><link rel="stylesheet" href="style.css"></head>
<body><main class="login"><h1>Bookmarks</h1>
<form method="post"><input type="password" name="password" placeholder="Password" autofocus required>
<button type="submit">Log in</button></form>
<?php if ($err): ?><p class="err"><?= h($err) ?></p><?php endif; ?></main></body></html>
