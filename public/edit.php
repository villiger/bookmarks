<?php
require __DIR__ . '/../src/app.php';
$u = endpoint();
$row = owned_card($u, (int)($_GET['id'] ?? $_POST['id'] ?? 0));
if ($_SERVER['REQUEST_METHOD'] !== 'POST') exit(render_edit($row));
check_csrf();
$title = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($_POST['title'] ?? ''))), 0, 300);
$summary = mb_substr(trim((string)($_POST['summary'] ?? '')), 0, 600);
$shown = ($row['summary'] ?? '') !== '' ? $row['summary'] : clean_cut((string)$row['description']);
$textEdited = ($title !== '' && $title !== $row['title']) || $summary !== $shown;
$newUrl = trim((string)($_POST['url'] ?? ''));
if ($newUrl !== '' && $newUrl !== $row['url']) {            // new address: validate, reject duplicates, queue a re-crawl
    $n = normalize_input($newUrl);
    $err = $n['error'] ?? (url_taken((int)$u['id'], url_key($n['url']), (int)$row['id']) ? 'You already saved this URL.' : '');
    if ($err !== '') exit(render_edit(['title' => $title, 'summary' => $summary, 'url' => $newUrl] + $row, $err));
    q('UPDATE bookmarks SET url=?, url_key=?, scheme_guessed=? WHERE id=?', [$n['url'], url_key($n['url']), $n['guessed'] ? 1 : 0, $row['id']]);
    requeue((int)$row['id']);
}
q('UPDATE bookmarks SET title=?, summary=?, text_edited=? WHERE id=?', [$title ?: $row['title'], $summary, $textEdited ? 1 : 0, $row['id']]);
fts_reindex((int)$row['id']);
echo render_card(fetch_card((int)$row['id'], (int)$u['id']));
