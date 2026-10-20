<?php
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/view.php';
$u = require_login(true);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
check_csrf();
$id = (int)($_POST['id'] ?? 0);
// Empty body removes the card (hx-swap outerHTML); the toast with Undo comes along out-of-band.
echo trash_bookmark($id, (int)$u['id']) ? render_toast($id) : '';
