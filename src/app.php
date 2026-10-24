<?php
// Entry point of every page: one require loads the whole app. Plus the small helpers the htmx endpoints share.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/bookmarks.php';
require_once __DIR__ . '/view.php';
require_once __DIR__ . '/transfer.php';

/** Endpoint answering htmx requests: needs a session (401 + HX-Redirect otherwise); $post = POST only, with CSRF check. Returns the user. */
function endpoint(bool $post = false): array {
    $u = require_login(true);
    if ($post) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
        check_csrf();
    }
    return $u;
}

/** The user's bookmark, or a 404 and exit (ownership check). */
function owned_card(array $user, int $id): array {
    $row = fetch_card($id, (int)$user['id']);
    if (!$row) { http_response_code(404); exit; }
    return $row;
}
