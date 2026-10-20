<?php
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/transfer.php';
$u = require_login();
header('Content-Type: text/html; charset=utf-8');
header('Content-Disposition: attachment; filename="bookmarks-' . date('Y-m-d') . '.html"');
header('Cache-Control: no-store');
echo export_netscape((int)$u['id']);
