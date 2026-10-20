<?php
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/view.php';
$u = require_login(true);
header('Cache-Control: no-store');
echo render_tagcloud((int)$u['id'], ($_GET['all'] ?? '') === '1');
