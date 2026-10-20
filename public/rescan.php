<?php
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/view.php';
$u = require_login(true);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
check_csrf();
$row = fetch_card((int)($_POST['id'] ?? 0), (int)$u['id']);   // ownership check
if (!$row) { http_response_code(404); exit; }
// Back into the queue: re-crawl, new title/summary; the user's applied tags stay (refresh) and new ones are added.
db()->prepare("UPDATE bookmarks SET status='pending', attempts=0, error=NULL, text_edited=0, refresh=tags_edited, llm_pending=0, llm_attempts=0 WHERE id=?")->execute([$row['id']]);
header('Cache-Control: no-store');
echo render_card(fetch_card((int)$row['id'], (int)$u['id']));
