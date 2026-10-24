<?php
require __DIR__ . '/../src/app.php';
if (current_user()) { header('Location: ./'); exit; }
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    if (!valid_email($email)) { $msg = 'Please enter a valid email address.'; }
    else {
        // Same response in every case (no account enumeration).
        $msg = 'If this address is eligible, a login link is on its way (valid ' . LINK_MINUTES . ' minutes). New addresses are sent to the admin for approval.';
        $limited = rate_limited('link-email', $email, 5) | rate_limited('link-ip', client_ip(), 5);
        if (!$limited) {
            $u = find_user_by_email($email);
            if ($u && $u['status'] === 'active') {
                email_login_link($u);
            } elseif (!$u) {
                create_user($email, false, 'pending');
                $admins = q("SELECT email FROM users WHERE is_admin=1 AND status='active'")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($admins as $a) send_mail($a, 'Bookmarks: new account request', "$email requested access.\nReview: " . base_url() . "/admin.php\n");
            }
        }
    }
}
page_head('Login'); ?>
<main class="login"><h1>Bookmarks</h1>
<form method="post"><input type="email" name="email" placeholder="you@example.com" autofocus required>
<button type="submit">Email me a login link</button></form>
<?php if ($msg): ?><p class="note"><?= h($msg) ?></p><?php endif; ?></main></body></html>
