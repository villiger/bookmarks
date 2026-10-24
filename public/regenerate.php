<?php
require __DIR__ . '/../src/app.php';
$u = require_login();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    q('UPDATE users SET bookmarklet_key=? WHERE id=?', [bin2hex(random_bytes(32)), $u['id']]);
}
header('Location: ./');
