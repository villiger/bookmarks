<?php
require_once __DIR__ . '/db.php';

/** Escape, then turn the char(2)/char(3) highlight markers into <mark>. */
function hl(?string $s): string {
    return str_replace(["\x02", "\x03"], ['<mark>', '</mark>'], h($s));
}

function domain_of(string $url): string {
    $host = parse_url($url, PHP_URL_HOST) ?: $url;
    return preg_replace('/^www\./', '', $host);
}

function render_card(array $r): string {
    $id = (int)$r['id'];
    $title = $r['h_title'] ?? null;
    $titleHtml = $title ? hl($title) : h($r['title'] ?: $r['url']);
    if (trim(strip_tags($titleHtml)) === '') $titleHtml = h($r['url']);
    $desc = !empty($r['h_desc']) ? hl($r['h_desc']) : h($r['description']);
    $snip = (!empty($r['h_body']) && strpos($r['h_body'], "\x02") !== false) ? '<p class="snip">' . hl($r['h_body']) . '</p>' : '';
    $fav = $r['favicon'] ? '<img class="fav" src="' . h($r['favicon']) . '" alt="" loading="lazy" referrerpolicy="no-referrer" width="16" height="16">' : '<span class="fav"></span>';
    $thumb = $r['image'] ? '<img class="thumb" src="' . h($r['image']) . '" alt="" loading="lazy" referrerpolicy="no-referrer">' : '';
    $badge = '';
    if ($r['status'] === 'pending') $badge = '<span class="badge pending">pending</span>';
    elseif ($r['status'] === 'error') $badge = '<span class="badge error" title="' . h($r['error']) . '">error</span>';
    $cat = $r['category'] ? '<span class="badge cat">' . h($r['category']) . '</span>' : '';
    $tags = '';
    foreach ($r['tags'] ?? [] as $t) {
        $tags .= '<a class="tag" href="?tag=' . h(rawurlencode($t)) . '" hx-get="search.php?tag=' . h(rawurlencode($t)) . '" hx-target="#results" hx-swap="innerHTML" hx-push-url="?tag=' . h(rawurlencode($t)) . '" hx-include="[name=q]">' . h($t) . '</a>';
    }
    $date = date('Y-m-d', (int)$r['created_at']);
    return '<article class="card" id="b' . $id . '">'
        . ($thumb ? '<div class="thumbwrap">' . $thumb . '</div>' : '')
        . '<div class="main"><h3>' . $fav . '<a href="' . h($r['url']) . '" target="_blank" rel="noopener noreferrer">' . $titleHtml . '</a></h3>'
        . '<div class="meta">' . h(domain_of($r['url'])) . ' · ' . $date . ' ' . $badge . $cat . '</div>'
        . ($desc ? '<p class="desc">' . $desc . '</p>' : '') . $snip
        . '<div class="tags">' . $tags . '</div></div>'
        . '<button class="del" title="Delete" hx-post="delete.php" hx-vals=\'{"id":' . $id . '}\' hx-confirm="Delete this bookmark?" hx-target="#b' . $id . '" hx-swap="outerHTML">×</button>'
        . '</article>';
}

function render_results(int $uid, string $q, string $tag): string {
    $rows = search_bookmarks($uid, $q, $tag);
    $out = '';
    if ($tag !== '') $out .= '<p class="filter">Tag: <strong>' . h($tag) . '</strong> <a href="." hx-get="search.php" hx-target="#results" hx-push-url="." hx-include="[name=q]">clear</a></p>';
    if (!$rows) return $out . '<p class="empty">No bookmarks found.</p>';
    foreach ($rows as $r) $out .= render_card($r);
    return $out;
}
