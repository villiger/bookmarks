<?php
require_once __DIR__ . '/../src/auth.php';
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
require_once __DIR__ . '/../src/view.php';
// JSON API for iOS/macOS Shortcuts etc.: add.php?format=json, params k (bookmarklet key), u (URL or text containing one), t (optional title)
$in = $_GET + $_POST;
$ct = $_SERVER['CONTENT_TYPE'] ?? '';
$body = trim((string)file_get_contents('php://input'));
$jsonBody = $body !== '' && ($body[0] === '{' || stripos($ct, 'json') !== false);
if ($jsonBody) $in = (array)(json_decode($body, true) ?: []) + $in;   // JSON body, with or without the header
$wantsJson = $jsonBody || ($in['format'] ?? '') === 'json' || stripos($ct, 'json') !== false || stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') === 0
    || preg_match('/Shortcuts|WorkflowKit|BackgroundShortcutRunner/i', $_SERVER['HTTP_USER_AGENT'] ?? '');
if ($wantsJson) {
    header('Content-Type: application/json; charset=utf-8');
    $out = function (int $code, array $b) { http_response_code($code); echo json_encode($b, JSON_UNESCAPED_UNICODE); exit; };
    $first = function ($v) use (&$first) { return is_array($v) ? $first(reset($v) ?: '') : (is_scalar($v) ? (string)$v : ''); };   // Shortcuts may send a URL object or an array
    if (!($user = user_by_key($first($in['k'] ?? '')))) $out(403, ['ok' => false, 'error' => 'Invalid key']);
    $raw = trim($first($in['u'] ?? ''));
    if ($raw === '') $out(400, ['ok' => false, 'error' => 'Missing u']);
    $url = extract_url($raw);
    $t = trim($first($in['t'] ?? ''));
    $res = $url === '' ? ['status' => 'invalid', 'error' => 'No URL found'] : add_bookmark((int)$user['id'], $url, $t);
    if ($res['status'] === 'invalid') $out(400, ['ok' => false, 'error' => $res['error']]);
    $out(200, ['ok' => true, 'status' => $res['status'] === 'added' ? 'saved' : 'duplicate', 'title' => $t !== '' ? $t : $url]);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {          // UI form (htmx), session + CSRF
    $user = require_login(true);
    check_csrf();
    $added = []; $err = '';
    foreach (preg_split('/[\s,]+/', trim((string)($_POST['urls'] ?? ''))) as $url) {
        if ($url === '') continue;
        $res = add_bookmark((int)$user['id'], $url);
        if ($res['status'] === 'added') $added[] = $res['id']; else $err = $res['error'];
    }
    header('Cache-Control: no-store');
    if ($added) header('HX-Trigger: bm-added');
    foreach ($added as $id) echo render_card(fetch_card($id, (int)$user['id']));
    echo '<div id="addmsg" class="err" hx-swap-oob="true">' . h($err) . '</div>';
    exit;
}
$k = (string)($_GET['k'] ?? '');
$u = trim((string)($_GET['u'] ?? ''));
$t = trim((string)($_GET['t'] ?? ''));
$msg = 'Saved ✓'; $code = 200;
// Auth: per-user bookmarklet key only. A session cookie must not authorize a GET (any other site could trigger it); the UI form uses POST + CSRF.
$user = user_by_key($k);
if (!$user) { $msg = 'Forbidden'; $code = 403; }
else {
    $client = ['client_title' => $t, 'client_desc' => $_GET['d'] ?? '', 'client_image' => $_GET['i'] ?? '', 'client_favicon' => $_GET['f'] ?? '',
               'client_canonical' => $_GET['c'] ?? '', 'client_keywords' => $_GET['kw'] ?? '', 'client_text' => $_GET['x'] ?? ''];
    foreach (['client_image', 'client_favicon', 'client_canonical'] as $f) if (!preg_match('~^https?://~i', (string)$client[$f])) $client[$f] = '';   // only http(s) URLs
    $res = add_bookmark((int)$user['id'], $u, $t, $client);
    if ($res['status'] === 'invalid') { $msg = 'Invalid URL'; $code = 400; }
    elseif ($res['status'] === 'dup') $msg = 'Already saved ✓';
}
http_response_code($code);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><link rel="icon" href="favicon.svg" type="image/svg+xml"><link rel="icon" href="favicon-96.png" type="image/png" sizes="96x96"><link rel="icon" href="favicon-32.png" type="image/png" sizes="32x32"><link rel="icon" href="favicon.ico" sizes="48x48"><link rel="apple-touch-icon" href="apple-touch-icon.png"><link rel="manifest" href="manifest.webmanifest"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Bookmark</title>
<style>body{font:600 22px system-ui,sans-serif;display:grid;place-items:center;height:90vh;margin:0;background:Canvas;color:CanvasText;color-scheme:light dark}</style></head>
<body><div><?= h($msg) ?></div>
<?php if ($code === 200): ?><script>setTimeout(function(){window.close()},1000)</script><?php endif; ?></body></html>
