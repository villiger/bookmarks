<?php
require __DIR__ . '/../src/app.php';
$u = endpoint();
$row = owned_card($u, (int)($_GET['id'] ?? 0));
if (($_GET['s'] ?? '') === card_sig($row)) { http_response_code(204); exit; }   // unchanged: the polling card keeps what it has
echo render_card($row);
