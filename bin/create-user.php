#!/usr/bin/env php
<?php
// Usage: php bin/create-user.php email@example.com [--admin]
// Creates (or activates) the user and prints a one-time login link (valid 15 min).
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../src/auth.php';
$email = $argv[1] ?? '';
if (!valid_email($email)) { fwrite(STDERR, "Usage: create-user.php email [--admin]\n"); exit(1); }
$admin = in_array('--admin', $argv, true);
$u = find_user_by_email($email);
if (!$u) { create_user($email, $admin, 'active'); }
else { q("UPDATE users SET status='active', is_admin = MAX(is_admin, ?) WHERE id=?", [$admin ? 1 : 0, $u['id']]); }
$u = find_user_by_email($email);
echo ($admin ? "Admin" : "User") . " {$u['email']} ready.\nOne-time login link (15 min):\n" . new_login_link((int)$u['id']) . "\n";
@chmod(cfg('db_path'), 0660);
