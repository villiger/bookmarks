<?php
require __DIR__ . '/../src/app.php';
$u = endpoint(true);
// Empty body removes the card (hx-swap outerHTML); the toast with Undo comes along out-of-band.
$id = (int)($_POST['id'] ?? 0);
echo trash_bookmark($id, (int)$u['id']) ? render_toast($id) : '';
