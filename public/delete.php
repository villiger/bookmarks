<?php
require_once __DIR__ . '/../src/db.php';
require_login(true);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
delete_bookmark((int)($_POST['id'] ?? 0));
// empty body: htmx replaces the card with nothing
