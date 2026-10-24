<?php
// One URL, three callers: iOS/macOS Shortcuts and scripts (JSON), the add field in the UI (htmx POST, session + CSRF) and the bookmarklet popup (GET with the user's key).
require __DIR__ . '/../src/app.php';
header('Referrer-Policy: no-referrer');

/** JSON API: params k (bookmarklet key), u (URL or text containing one), t (optional title); from the query, a form or a JSON body. */
function add_json(array $in): never {
    $out = function (int $code, array $b): never { http_response_code($code); echo json_encode($b, JSON_UNESCAPED_UNICODE); exit; };
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

/** The add field on the index page: one or more URLs, answered with the new cards. */
function add_form(): never {
    $user = endpoint(true);
    $added = []; $err = '';
    foreach (preg_split('/[\s,]+/', trim((string)($_POST['urls'] ?? ''))) as $url) {
        if ($url === '') continue;
        $res = add_bookmark((int)$user['id'], $url);
        if ($res['status'] === 'added') $added[] = $res['id']; else $err = $res['error'];
    }
    if ($added) header('HX-Trigger: bm-added');
    foreach ($added as $id) echo render_card(fetch_card($id, (int)$user['id']));
    echo '<div id="addmsg" class="err" hx-swap-oob="true">' . h($err) . '</div>';
    exit;
}

/** Bookmarklet popup: only the per-user key authorizes it. A session cookie must not authorize a GET (any other site could trigger it). */
function add_popup(): void {
    $t = trim((string)($_GET['t'] ?? ''));
    $msg = 'Saved ✓'; $code = 200;
    if (!($user = user_by_key((string)($_GET['k'] ?? '')))) { $msg = 'Forbidden'; $code = 403; }
    else {
        $client = ['client_title' => $t, 'client_desc' => $_GET['d'] ?? '', 'client_image' => $_GET['i'] ?? '', 'client_favicon' => $_GET['f'] ?? '',
                   'client_canonical' => $_GET['c'] ?? '', 'client_keywords' => $_GET['kw'] ?? '', 'client_text' => $_GET['x'] ?? ''];
        foreach (['client_image', 'client_favicon', 'client_canonical'] as $f) if (!preg_match('~^https?://~i', (string)$client[$f])) $client[$f] = '';   // only http(s) URLs
        $res = add_bookmark((int)$user['id'], trim((string)($_GET['u'] ?? '')), $t, $client);
        if ($res['status'] === 'invalid') { $msg = 'Invalid URL'; $code = 400; }
        elseif ($res['status'] === 'dup') $msg = 'Already saved ✓';
    }
    http_response_code($code);
    echo render('popup', ['msg' => $msg, 'close' => $code === 200]);
}

$in = $_GET + $_POST;
$ct = $_SERVER['CONTENT_TYPE'] ?? '';
$body = trim((string)file_get_contents('php://input'));
$jsonBody = $body !== '' && ($body[0] === '{' || stripos($ct, 'json') !== false);
if ($jsonBody) $in = (array)(json_decode($body, true) ?: []) + $in;   // JSON body, with or without the header
$wantsJson = $jsonBody || ($in['format'] ?? '') === 'json' || stripos($ct, 'json') !== false || stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') === 0
    || preg_match('/Shortcuts|WorkflowKit|BackgroundShortcutRunner/i', $_SERVER['HTTP_USER_AGENT'] ?? '');
if ($wantsJson) { header('Content-Type: application/json; charset=utf-8'); add_json($in); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') add_form();
add_popup();
