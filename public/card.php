<?php
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/view.php';
$u = require_login(true);
header('Cache-Control: no-store');
$row = fetch_card((int)($_GET['id'] ?? 0), (int)$u['id']);
if (!$row) { http_response_code(404); exit; }
if (($_GET['s'] ?? '') === card_sig($row)) { http_response_code(204); exit; }   // unchanged: the polling card keeps what it has
echo render_card($row);
