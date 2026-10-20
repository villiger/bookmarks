<?php
require_once __DIR__ . '/../src/view.php';
require_login(true);
header('Cache-Control: no-store');
echo render_results(trim((string)($_GET['q'] ?? '')), trim((string)($_GET['tag'] ?? '')));
