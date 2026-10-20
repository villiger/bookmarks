<?php
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/view.php';
$u = require_login(true);
header('Cache-Control: no-store');
echo render_results((int)$u['id'], trim((string)($_GET['q'] ?? '')), trim((string)($_GET['tag'] ?? '')),
    in_array($_GET['sort'] ?? '', ['new', 'old', 'az', 'review', 'match'], true) ? $_GET['sort'] : '', (int)($_GET['seed'] ?? 0) % 1000000);
