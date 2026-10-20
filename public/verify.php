<?php
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/_head.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
// GET only shows a confirm button (mail scanners/prefetchers must not burn the one-time link); POST consumes it.
$t = (string)($_REQUEST['t'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uid = consume_login_token($t);
    if ($uid) { start_session($uid); header('Location: ./'); exit; }
    http_response_code(400);
    page_head('Link expired'); echo '<main class="login"><h1>Link invalid or expired</h1><p><a href="login.php">Request a new one</a></p></main></body></html>'; exit;
}
page_head('Confirm login'); ?>
<main class="login"><h1>Bookmarks</h1>
<form method="post"><input type="hidden" name="t" value="<?= h($t) ?>"><button type="submit">Confirm login</button></form></main></body></html>
