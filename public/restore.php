<?php
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/view.php';
$u = require_login(true);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
check_csrf();
$new = restore_bookmark((int)($_POST['id'] ?? 0), (int)$u['id']);
header('Cache-Control: no-store');
echo ($new ? render_card(fetch_card($new, (int)$u['id'])) : '') . render_toast(null);
