<?php
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/_head.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
// GET only shows a page that submits itself (mail scanners/prefetchers fetch links without running JS and must not burn the one-time link); POST consumes it. The button is the no-JS fallback.
$t = (string)($_REQUEST['t'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uid = consume_login_token($t);
    if ($uid) { start_session($uid); header('Location: ./'); exit; }
    http_response_code(400);
    page_head('Link expired'); echo '<main class="login"><h1>Link invalid or expired</h1><p><a href="login.php">Request a new one</a></p></main></body></html>'; exit;
}
page_head('Confirm login'); ?>
<main class="login"><h1>Bookmarks</h1>
<form method="post" id="f"><input type="hidden" name="t" value="<?= h($t) ?>"><button type="submit">Continue</button></form>
<script>document.getElementById("f").submit()</script></main></body></html>
