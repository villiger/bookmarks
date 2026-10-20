<?php
require_once __DIR__ . '/../src/auth.php';
$u = require_login();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    db()->prepare('UPDATE users SET bookmarklet_key=? WHERE id=?')->execute([bin2hex(random_bytes(32)), $u['id']]);
}
header('Location: ./');
