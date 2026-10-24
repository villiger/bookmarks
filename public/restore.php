<?php
require __DIR__ . '/../src/app.php';
$u = endpoint(true);
$new = restore_bookmark((int)($_POST['id'] ?? 0), (int)$u['id']);
echo ($new ? render_card(fetch_card($new, (int)$u['id'])) : '') . render_toast(null);
