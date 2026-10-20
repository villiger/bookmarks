<?php
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/transfer.php';
$u = require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ./'); exit; }
check_csrf();
$f = $_FILES['file'] ?? null;
if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 8 * 1024 * 1024) { header('Location: ./?imp=' . rawurlencode('Upload failed (max 8 MB).')); exit; }
if (rate_limited('import', (string)$u['id'], 10)) { header('Location: ./?imp=' . rawurlencode('Too many imports, try again later.')); exit; }
set_time_limit(120);
$n = import_links((int)$u['id'], parse_netscape((string)file_get_contents($f['tmp_name'])));
header('Location: ./?imp=' . rawurlencode("Imported {$n['added']} bookmarks ({$n['dup']} duplicates, {$n['invalid']} invalid). They are processed in the background."));
