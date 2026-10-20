<?php
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/view.php';
$u = require_login(true);
header('Cache-Control: no-store');
$row = fetch_card((int)($_GET['id'] ?? $_POST['id'] ?? 0), (int)$u['id']);   // ownership check
if (!$row) { http_response_code(404); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $title = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($_POST['title'] ?? ''))), 0, 300);
    $summary = mb_substr(trim((string)($_POST['summary'] ?? '')), 0, 600);
    db()->prepare('UPDATE bookmarks SET title=?, summary=?, text_edited=1 WHERE id=?')->execute([$title ?: $row['title'], $summary, $row['id']]);
    fts_reindex((int)$row['id']);
    echo render_card(fetch_card((int)$row['id'], (int)$u['id']));
    exit;
}
echo render_edit($row);
