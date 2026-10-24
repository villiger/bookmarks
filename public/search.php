<?php
require __DIR__ . '/../src/app.php';
$u = endpoint();
echo render_results((int)$u['id'], trim((string)($_GET['q'] ?? '')), trim((string)($_GET['tag'] ?? '')),
    in_array($_GET['sort'] ?? '', ['new', 'old', 'az', 'review', 'match'], true) ? $_GET['sort'] : '', (int)($_GET['seed'] ?? 0) % 1000000);
