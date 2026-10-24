<?php
require __DIR__ . '/../src/app.php';
$u = require_login();
header('Content-Type: text/html; charset=utf-8');
header('Content-Disposition: attachment; filename="bookmarks-' . date('Y-m-d') . '.html"');
echo export_netscape((int)$u['id']);
