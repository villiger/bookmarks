<?php
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/view.php';
require_once __DIR__ . '/../src/url.php';
$u = require_login(true);
header('Cache-Control: no-store');
$row = fetch_card((int)($_GET['id'] ?? $_POST['id'] ?? 0), (int)$u['id']);   // ownership check
if (!$row) { http_response_code(404); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $title = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($_POST['title'] ?? ''))), 0, 300);
    $summary = mb_substr(trim((string)($_POST['summary'] ?? '')), 0, 600);
    $shown = ($row['summary'] ?? '') !== '' ? $row['summary'] : clean_cut((string)$row['description']);
    $textEdited = ($title !== '' && $title !== $row['title']) || $summary !== $shown;
    $pdo = db();
    $newUrl = trim((string)($_POST['url'] ?? ''));
    if ($newUrl !== '' && $newUrl !== $row['url']) {            // new address: validate, reject duplicates, queue a re-crawl
        $n = normalize_input($newUrl);
        if (isset($n['error'])) { $row['title'] = $title; $row['summary'] = $summary; $row['url'] = $newUrl; exit(render_edit($row, $n['error'])); }
        $key = url_key($n['url']);
        $dup = $pdo->prepare('SELECT 1 FROM bookmarks WHERE user_id=? AND url_key=? AND id<>?');
        $dup->execute([$u['id'], $key, $row['id']]);
        if ($dup->fetchColumn()) { $row['title'] = $title; $row['summary'] = $summary; $row['url'] = $newUrl; exit(render_edit($row, 'You already saved this URL.')); }
        $pdo->prepare("UPDATE bookmarks SET url=?, url_key=?, scheme_guessed=?, status='pending', attempts=0, error=NULL, refresh=tags_edited, llm_pending=0, llm_attempts=0 WHERE id=?")
            ->execute([$n['url'], $key, $n['guessed'] ? 1 : 0, $row['id']]);
    }
    $pdo->prepare('UPDATE bookmarks SET title=?, summary=?, text_edited=? WHERE id=?')->execute([$title ?: $row['title'], $summary, $textEdited ? 1 : 0, $row['id']]);
    fts_reindex((int)$row['id']);
    echo render_card(fetch_card((int)$row['id'], (int)$u['id']));
    exit;
}
echo render_edit($row);
