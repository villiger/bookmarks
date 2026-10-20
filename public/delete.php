<?php
require_once __DIR__ . '/../src/auth.php';
$u = require_login(true);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
check_csrf();
delete_bookmark((int)($_POST['id'] ?? 0), (int)$u['id']);
