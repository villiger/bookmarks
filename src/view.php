<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crawler.php';

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
    $shown = ($r['summary'] ?? '') !== '' ? $r['summary'] : ($r['description'] ? clean_cut((string)$r['description']) : prose_excerpt((string)$r['body']));
    $desc = (!empty($r['h_desc']) && ($r['summary'] ?? '') === '') ? hl($r['h_desc']) : h($shown);
    $snip = (!empty($r['h_body']) && strpos($r['h_body'], "\x02") !== false) ? '<p class="snip">' . hl($r['h_body']) . '</p>' : '';
    $fav = $r['favicon'] ? '<img class="fav" src="' . h($r['favicon']) . '" alt="" loading="lazy" referrerpolicy="no-referrer" width="16" height="16">' : '<span class="fav"></span>';
    $thumb = $r['image'] ? '<img class="thumb" src="' . h($r['image']) . '" alt="" loading="lazy" referrerpolicy="no-referrer">' : '';
    $badge = '';
    if ($r['status'] === 'pending') $badge = '<span class="badge pending">pending</span>';
    elseif ($r['status'] === 'error') $badge = '<span class="badge error" title="' . h($r['error']) . '">error</span>';
    if (!empty($r['llm_pending'])) $badge .= '<span class="llm" title="More tag suggestions coming">⋯</span>';
    $cat = $r['category'] ? '<span class="badge cat">' . h($r['category']) . '</span>' : '';
    $date = date('Y-m-d', (int)$r['created_at']);
    $poll = $r['status'] === 'pending' ? ' hx-get="card.php?id=' . $id . '" hx-trigger="every 10s" hx-swap="outerHTML"' : '';
    return '<article class="card" id="b' . $id . '"' . $poll . '>'
        . ($thumb ? '<div class="thumbwrap">' . $thumb . '</div>' : '')
        . '<div class="main"><h3>' . $fav . '<a href="' . h($r['url']) . '" target="_blank" rel="noopener noreferrer">' . $titleHtml . '</a></h3>'
        . '<div class="meta">' . h(domain_of($r['url'])) . ' · ' . $date . ' ' . $badge . $cat . '</div>'
        . ($desc ? '<p class="desc">' . $desc . '</p>' : '') . $snip
        . render_tags($r) . '</div>'
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

function render_tags(array $r): string {
    $id = (int)$r['id'];
    $tgt = ' hx-target="#tg' . $id . '" hx-swap="outerHTML"';
    $vals = fn($act, $t) => ' hx-vals=\'' . h(json_encode(['id' => $id, 'action' => $act, 'tag' => $t])) . '\'';
    $o = '<div class="tags" id="tg' . $id . '">';
    foreach ($r['tags'] ?? [] as $t) {
        $e = h(rawurlencode($t));
        $o .= '<span class="tag"><a href="?tag=' . $e . '" hx-get="search.php?tag=' . $e . '" hx-target="#results" hx-swap="innerHTML" hx-push-url="?tag=' . $e . '" hx-include="[name=q]">' . h($t) . '</a>'
            . '<button class="x" title="Remove tag" hx-post="tag.php"' . $vals('remove', $t) . $tgt . '>×</button></span>';
    }
    foreach ($r['suggested'] ?? [] as $t) {
        $o .= '<span class="tag sugg"><button class="acc" title="Accept suggested tag" hx-post="tag.php"' . $vals('accept', $t) . $tgt . '>＋ ' . h($t) . '</button>'
            . '<button class="x" title="Dismiss" hx-post="tag.php"' . $vals('remove', $t) . $tgt . '>×</button></span>';
    }
    $o .= '<form class="addtag" hx-post="tag.php"' . $tgt . '><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="action" value="add">'
        . '<input name="tag" list="vocab" maxlength="24" placeholder="+ tag" autocomplete="off" required></form></div>';
    return $o;
}
