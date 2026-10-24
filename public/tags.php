<?php
require __DIR__ . '/../src/app.php';
$u = endpoint();
echo render_tagcloud((int)$u['id'], ($_GET['all'] ?? '') === '1');
