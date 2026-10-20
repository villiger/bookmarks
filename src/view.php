<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crawler.php';
require_once __DIR__ . '/tagger.php';

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
        . '<div class="meta">' . h(domain_of($r['url'])) . ' · ' . $date . ' ' . $badge . '</div>'
        . ($desc ? '<p class="desc">' . $desc . '</p>' : '') . $snip
        . render_tags($r) . '</div>'
        . '<div class="acts"><button title="Edit" hx-get="edit.php?id=' . $id . '" hx-target="#b' . $id . '" hx-swap="outerHTML" aria-label="Edit"><svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg></button>'
        . '<button title="Rescan page (keeps your tags, replaces title and summary)" hx-post="rescan.php" hx-vals=\'{"id":' . $id . '}\' hx-target="#b' . $id . '" hx-swap="outerHTML" aria-label="Rescan"><svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 3v6h-6"/></svg></button>'
        . '<button class="del" title="Delete" hx-post="delete.php" hx-vals=\'{"id":' . $id . '}\' hx-target="#b' . $id . '" hx-swap="outerHTML" aria-label="Delete"><svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/></svg></button></div>'
        . '</article>';
}

function render_results(int $uid, string $q, string $tag, string $sort = '', int $seed = 0): string {
    $rows = search_bookmarks($uid, $q, $tag, 100, $sort, $seed);
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
    $tags = $r['tags'] ?? [];
    usort($tags, fn($a, $b) => in_array($b, BM_FORMAT_TAGS, true) <=> in_array($a, BM_FORMAT_TAGS, true));   // link type first (stable), then the rest alphabetically
    foreach ($tags as $t) {
        $e = h(rawurlencode($t));
        $o .= '<span class="tag' . (in_array($t, BM_FORMAT_TAGS, true) ? ' fmt' : '') . '"><a href="?tag=' . $e . '" hx-get="search.php?tag=' . $e . '" hx-target="#results" hx-swap="innerHTML" hx-push-url="?tag=' . $e . '" hx-include="[name=q]">' . h($t) . '</a>'
            . '<button class="x" title="Remove tag" hx-post="tag.php"' . $vals('remove', $t) . $tgt . '>×</button></span>';
    }
    foreach ($r['suggested'] ?? [] as $t) {
        $o .= '<span class="tag sugg"><button class="acc" title="Accept suggested tag" hx-post="tag.php"' . $vals('accept', $t) . $tgt . '>＋ ' . h($t) . '</button>'
            . '<button class="x" title="Dismiss" hx-post="tag.php"' . $vals('remove', $t) . $tgt . '>×</button></span>';
    }
    $o .= '<form class="addtag" hx-post="tag.php"' . $tgt . '><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="action" value="add">'
        . '<input name="tag" list="vocab" maxlength="24" placeholder="+ tag" autocomplete="off" enterkeyhint="done" required><button type="submit" class="addbtn" title="Add tag" aria-label="Add tag">＋</button></form></div>';
    return $o;
}

/** Edit form that replaces a card: title, the text shown on the card and the URL (a new URL is re-crawled). */
function render_edit(array $r, string $err = ''): string {
    $id = (int)$r['id'];
    $shown = ($r['summary'] ?? '') !== '' ? $r['summary'] : clean_cut((string)$r['description']);
    return '<article class="card editing" id="b' . $id . '"><form class="editform" hx-post="edit.php" hx-target="#b' . $id . '" hx-swap="outerHTML">'
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<label>Title<input name="title" maxlength="300" value="' . h($r['title']) . '"></label>'
        . '<label>Description<textarea name="summary" rows="4" maxlength="600">' . h($shown) . '</textarea></label>'
        . '<label>URL<input name="url" type="text" maxlength="2000" value="' . h($r['url']) . '" autocapitalize="off" spellcheck="false"></label>'
        . ($err !== '' ? '<div class="err">' . h($err) . '</div>' : '')
        . '<div class="btns"><button type="submit">Save</button><button type="button" hx-get="card.php?id=' . $id . '" hx-target="#b' . $id . '" hx-swap="outerHTML">Cancel</button></div></form></article>';
}

/** Tag cloud: the 10 most used topic tags (no link types); "more" swaps in every tag used 3+ times, link types included (and back). */
function render_tagcloud(int $uid, bool $all): string {
    $tags = $all ? top_tags($uid, 300, 3) : top_tags($uid, 10, 1, BM_FORMAT_TAGS);
    $o = '<div class="tagcloud" id="tagcloud">';
    foreach ($tags as $t) {
        $e = h(rawurlencode($t['tag']));
        $o .= '<a class="tag" href="?tag=' . $e . '" hx-get="search.php?tag=' . $e . '" hx-target="#results" hx-push-url="?tag=' . $e . '" hx-include="[name=q],[name=sort],[name=seed]">' . h($t['tag']) . ' <small>' . (int)$t['c'] . '</small></a>';
    }
    return $o . '<a class="more" href="#" hx-get="tags.php?all=' . ($all ? 0 : 1) . '" hx-target="#tagcloud" hx-swap="outerHTML">' . ($all ? 'less' : 'more') . '</a></div>';
}

/** Out-of-band toast slot; with $id it offers "Undo" for a deleted bookmark (fades out by itself, see style.css). */
function render_toast(?int $id = null): string {
    if ($id === null) return '<div id="toast" hx-swap-oob="true"></div>';
    return '<div id="toast" hx-swap-oob="true"><div class="toast">Bookmark deleted <button hx-post="restore.php" hx-vals=\'{"id":' . $id . '}\' hx-target="#results" hx-swap="afterbegin">Undo</button></div></div>';
}

/** One row of the trash page; $note replaces the buttons after an action. */
function render_trash_row(array $t, string $note = ''): string {
    $id = (int)$t['id'];
    $act = fn($a, $label, $cls = '') => '<button class="' . $cls . '" hx-post="trash.php" hx-vals=\'{"id":' . $id . ',"action":"' . $a . '"}\' hx-target="#t' . $id . '" hx-swap="outerHTML"' . ($a === 'purge' ? ' hx-confirm="Delete forever? This cannot be undone."' : '') . '>' . $label . '</button>';
    return '<article class="card trashrow" id="t' . $id . '"><div class="main"><h3>' . h($t['title']) . '</h3><div class="meta">' . h(domain_of($t['url'])) . ' · deleted ' . date('Y-m-d H:i', $t['deleted_at']) . '</div></div>'
        . '<div class="rowacts">' . ($note !== '' ? '<span class="note">' . h($note) . '</span>' : $act('restore', 'Restore', 'primary') . $act('purge', 'Delete forever')) . '</div></article>';
}
