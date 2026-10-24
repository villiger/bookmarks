<?php
require_once __DIR__ . '/bookmarks.php';
require_once __DIR__ . '/crawler.php';
require_once __DIR__ . '/tagger.php';

/** Render views/$tpl.phtml with $vars as local variables. */
function render(string $tpl, array $vars = []): string {
    extract($vars, EXTR_SKIP);
    ob_start();
    require __DIR__ . '/../views/' . $tpl . '.phtml';
    return ob_get_clean();
}

/** Stroke icons (24x24 viewBox, 17px). */
function icon(string $name): string {
    $paths = [
        'edit' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
        'rescan' => '<path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 3v6h-6"/>',
        'trash' => '<path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/>',
    ];
    return '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $paths[$name] . '</svg>';
}

/** Escaped hx-vals JSON for a tag button. */
function tag_vals(int $id, string $action, string $tag): string { return h(json_encode(['id' => $id, 'action' => $action, 'tag' => $tag])); }

/** Escape, then turn the char(2)/char(3) highlight markers into <mark>. */
function hl(?string $s): string {
    return str_replace(["\x02", "\x03"], ['<mark>', '</mark>'], h($s));
}

/** Fingerprint of everything a card shows; a polling card sends it and gets 204 (no swap, no flicker) while it still matches. */
function card_sig(array $r): string {
    return substr(md5(json_encode([$r['status'], $r['attempts'] ?? 0, $r['title'], $r['summary'] ?? '', $r['description'], $r['favicon'], $r['image'], $r['category'], $r['llm_pending'] ?? 0, $r['error'], $r['tags'] ?? [], $r['suggested'] ?? []])), 0, 12);
}

function render_card(array $r): string {
    $id = (int)$r['id'];
    $title = $r['h_title'] ?? null;
    $titleHtml = $title ? hl($title) : h($r['title'] ?: $r['url']);
    if (trim(strip_tags($titleHtml)) === '') $titleHtml = h($r['url']);
    $shown = ($r['summary'] ?? '') !== '' ? $r['summary'] : ($r['description'] ? clean_cut((string)$r['description']) : prose_excerpt((string)$r['body']));
    $desc = (!empty($r['h_desc']) && ($r['summary'] ?? '') === '') ? hl($r['h_desc']) : h($shown);
    $snip = (!empty($r['h_body']) && strpos($r['h_body'], "\x02") !== false) ? '<p class="snip">' . hl($r['h_body']) . '</p>' : '';
    $badge = '';
    if ($r['status'] === 'pending') $badge = '<span class="badge pending">pending</span>';
    elseif ($r['status'] === 'error') $badge = '<span class="badge error" title="' . h($r['error']) . '">error</span>';
    if (!empty($r['llm_pending'])) $badge .= '<span class="llm" title="More tag suggestions coming">⋯</span>';
    // Pending cards refresh themselves, but never while the card is open or its tag field is in use (the swap would wipe it).
    $poll = ($r['status'] === 'pending' && (int)($r['attempts'] ?? 0) < (int)(cfg('max_attempts') ?: 3))
        ? ' hx-get="card.php?id=' . $id . '&amp;s=' . card_sig($r) . '" hx-trigger="every 10s" data-poll hx-swap="outerHTML"' : '';
    return render('card', ['r' => $r, 'id' => $id, 'titleHtml' => $titleHtml, 'desc' => $desc, 'snip' => $snip, 'badge' => $badge, 'date' => date('Y-m-d', (int)$r['created_at']), 'poll' => $poll]);
}

function render_results(int $uid, string $q, string $tag, string $sort = '', int $seed = 0): string {
    $rows = search_bookmarks($uid, $q, $tag, 100, $sort, $seed);
    $out = '';
    if ($tag !== '') $out .= '<p class="filter">Tag: <strong>' . h($tag) . '</strong> <a href="." hx-get="search.php" hx-target="#results" hx-push-url="." hx-include="[name=q],[name=sort],[name=seed]">clear</a></p>';
    if (!$rows) return $out . '<p class="empty">No bookmarks found.</p>';
    foreach ($rows as $r) $out .= render_card($r);
    return $out;
}

function render_tags(array $r): string {
    $tags = $r['tags'] ?? [];
    usort($tags, fn($a, $b) => in_array($b, BM_FORMAT_TAGS, true) <=> in_array($a, BM_FORMAT_TAGS, true));   // link type first (stable), then the rest alphabetically
    return render('tags', ['id' => (int)$r['id'], 'tags' => $tags, 'suggested' => $r['suggested'] ?? []]);
}

/** Edit form that replaces a card: title, the text shown on the card and the URL (a new URL is re-crawled). */
function render_edit(array $r, string $err = ''): string {
    $shown = ($r['summary'] ?? '') !== '' ? $r['summary'] : clean_cut((string)$r['description']);
    return render('edit', ['r' => $r, 'id' => (int)$r['id'], 'shown' => $shown, 'err' => $err]);
}

/** Tag cloud: the 10 most used topic tags (no link types); "more" swaps in every tag used 3+ times, link types included (and back). */
function render_tagcloud(int $uid, bool $all): string {
    return render('tagcloud', ['all' => $all, 'tags' => $all ? top_tags($uid, 300, 3) : top_tags($uid, 10, 1, BM_FORMAT_TAGS)]);
}

/** Out-of-band toast slot; with $id it offers "Undo" for a deleted bookmark (fades out by itself, see style.css). */
function render_toast(?int $id = null): string { return render('toast', ['id' => $id]); }

/** One row of the trash page; $note replaces the buttons after an action. */
function render_trash_row(array $t, string $note = ''): string { return render('trash_row', ['t' => $t, 'id' => (int)$t['id'], 'note' => $note]); }

/** The javascript: URL of the browser bookmarklet (page data goes to add.php as GET parameters, trimmed to fit the URL limit). */
function bookmarklet_js(string $key): string {
    $add = base_url() . '/add.php';
    return "javascript:(function(){var d=document,q=function(n){var e=d.querySelector('meta[name=\"'+n+'\"],meta[property=\"'+n+'\"]');return e?e.content:''},l=d.querySelector('link[rel~=icon]'),c=d.querySelector('link[rel=canonical]'),s=String(getSelection()||''),x=(s||(d.body&&d.body.innerText)||'').replace(/\\s+/g,' ').slice(0,1500),p={k:'" . $key . "',u:location.href,t:d.title,d:q('description')||q('og:description'),i:q('og:image'),f:l?l.href:'',c:c?c.href:'',kw:q('keywords'),x:x},g=function(){var a=[];for(var n in p)a.push(n+'='+encodeURIComponent(p[n]));return '" . $add . "?'+a.join('&')};while(g().length>7500&&p.x.length>100)p.x=p.x.slice(0,p.x.length-300);window.open(g(),'bm','width=300,height=120')})()";
}

/** Opening of a plain page (login, admin, trash …): doctype, head and <body>. */
function page_head(string $title): void { echo render('head', ['title' => $title]); }
