<?php
require_once __DIR__ . '/../src/config.php';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (hash_equals((string)cfg('token'), (string)($_POST['password'] ?? ''))) {
        setcookie('bm_auth', session_value(), [
            'expires' => time() + 86400 * 365, 'path' => rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/',
            'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax',
        ]);
        header('Location: ./'); exit;
    }
    sleep(1);
    $err = 'Wrong password.';
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex"><title>Login · Bookmarks</title><link rel="stylesheet" href="style.css"></head>
<body><main class="login"><h1>Bookmarks</h1>
<form method="post"><input type="password" name="password" placeholder="Token / password" autofocus required>
<button type="submit">Log in</button></form>
<?php if ($err): ?><p class="err"><?= h($err) ?></p><?php endif; ?></main></body></html>
