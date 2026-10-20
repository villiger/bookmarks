<?php
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/view.php';
$user = require_login();
$uid = (int)$user['id'];
$tag = trim((string)($_GET['tag'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));
$add = base_url() . '/add.php';
$js = "javascript:(function(){var d=document,q=function(n){var e=d.querySelector('meta[name=\"'+n+'\"],meta[property=\"'+n+'\"]');return e?e.content:''},l=d.querySelector('link[rel~=icon]'),c=d.querySelector('link[rel=canonical]'),s=String(getSelection()||''),x=(s||(d.body&&d.body.innerText)||'').replace(/\\s+/g,' ').slice(0,1500),p={k:'" . $user['bookmarklet_key'] . "',u:location.href,t:d.title,d:q('description')||q('og:description'),i:q('og:image'),f:l?l.href:'',c:c?c.href:'',kw:q('keywords'),x:x},g=function(){var a=[];for(var n in p)a.push(n+'='+encodeURIComponent(p[n]));return '" . $add . "?'+a.join('&')};while(g().length>7500&&p.x.length>100)p.x=p.x.slice(0,p.x.length-300);window.open(g(),'bm','width=300,height=120')})()";
$st = db()->prepare("SELECT COUNT(*), COALESCE(SUM(status='pending'),0) FROM bookmarks WHERE user_id=?"); $st->execute([$uid]);
[$count, $pending] = $st->fetch(PDO::FETCH_NUM);
$reqs = $user['is_admin'] ? (int)db()->query("SELECT COUNT(*) FROM users WHERE status='pending'")->fetchColumn() : 0;
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><link rel="icon" href="favicon.svg" type="image/svg+xml"><link rel="icon" href="favicon.ico" sizes="48x48"><link rel="apple-touch-icon" href="apple-touch-icon.png"><link rel="manifest" href="manifest.webmanifest"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#fafafa" media="(prefers-color-scheme: light)"><meta name="theme-color" content="#141416" media="(prefers-color-scheme: dark)">
<meta name="robots" content="noindex"><title>Bookmarks</title><link rel="stylesheet" href="style.css">
<script src="htmx.min.js"></script>
</head><body hx-headers='<?= h(json_encode(["X-CSRF-Token" => csrf_token()])) ?>'>
<header class="top"><a class="brand" href="./"><span class="logo" aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M6 3h12a1 1 0 0 1 1 1v17l-7-4-7 4V4a1 1 0 0 1 1-1z"/></svg></span>Bookmarks</a>
<span class="count"><?= (int)$count ?> saved<?= $pending ? " · " . (int)$pending . " processing" : "" ?></span>
<details class="menu"><summary aria-label="Menu"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/></svg><?php if ($reqs): ?><i class="dot"></i><?php endif; ?></summary>
<div class="panel">
<section><h4>Save from anywhere</h4>
<details><summary>Browser bookmarklet</summary>
<p>Drag this to your bookmarks bar: <a class="bml" href="<?= h($js) ?>" onclick="alert('Drag this link to your bookmarks bar.');return false">＋ Save to Bookmarks</a></p>
<p><button type="button" onclick="navigator.clipboard.writeText(document.getElementById('bmjs').value);this.textContent='Copied'">Copy</button>
<input id="bmjs" type="text" readonly value="<?= h($js) ?>" class="wide">
<form method="post" action="regenerate.php" class="inline" onsubmit="return confirm('The old bookmarklet will stop working. Continue?')"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><button>Regenerate key</button></form></p></details>
<details><summary>iPhone / Mac share sheet</summary>
<p>Key: <input type="text" readonly value="<?= h($user['bookmarklet_key']) ?>" id="bmkey" class="wide"> <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('bmkey').value);this.textContent='Copied'">Copy</button></p>
<p>Endpoint: <code><?= h(base_url() . '/add.php') ?></code> (POST or GET, <code>format=json</code>)</p>
<ol>
<li>Shortcuts app: new Shortcut, name it <b>Save to Bookmarks</b>.</li>
<li>Details: turn on <b>Show in Share Sheet</b>; accept <b>URLs</b>, <b>Safari web pages</b> and <b>Text</b>.</li>
<li>Add <b>Get Contents of URL</b>: URL <code><?= h(base_url() . '/add.php') ?></code>, Method <b>POST</b>, Request Body <b>JSON</b> with <code>k</code> = your key (Text) and <code>u</code> = <b>Shortcut Input</b>. That is enough; <code>format</code> is optional (Form also works).</li>
<li>Add <b>Get Dictionary Value</b> for <code>status</code>, then <b>Show Notification</b>: "Saved ✓ (status)".</li>
</ol>
<p>The Shortcut syncs to your Mac via iCloud and appears in Safari's Share menu there too. To share it, create an iCloud link with the key removed and add an Import Question for <code>k</code>.</p></details></section>
<section><h4>Your data</h4>
<details><summary>Import / export</summary>
<p><a href="export.php" download>Export all bookmarks</a> (HTML, importable into Safari, Chrome and Firefox).</p>
<form method="post" action="import.php" enctype="multipart/form-data" class="inline"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
Import a bookmarks file (Safari: File ▸ Export ▸ Bookmarks): <input type="file" name="file" accept=".html,.htm" required> <button>Import</button></form></details></section>
<section class="rows"><a href="trash.php">Recently deleted</a><?php if ($user['is_admin']): ?><a href="admin.php">Users<?= $reqs ? " <span class=\"badge pending\">$reqs</span>" : '' ?></a><?php endif; ?>
<form method="post" action="logout.php"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><button class="rowbtn" title="<?= h($user['email']) ?>">Log out <small><?= h($user['email']) ?></small></button></form></section>
</div></details></header>
<form class="addurl" hx-post="add.php" hx-target="#results" hx-swap="afterbegin" hx-on:bm-added="this.reset()">
<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg><input type="text" name="urls" placeholder="Paste a link to save…" aria-label="URL to save" required autocomplete="off" autocapitalize="off" spellcheck="false" enterkeyhint="go"><button type="submit" class="primary">Save</button></form>
<div id="addmsg" class="err"></div>
<?php if (!empty($_GET['imp'])): ?><p class="count"><?= h($_GET['imp']) ?></p><?php endif; ?>
<datalist id="vocab"><?php foreach (user_vocabulary($uid) as $v): ?><option value="<?= h($v) ?>"><?php endforeach; ?></datalist>
<div class="searchbox"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg><input type="search" name="q" value="<?= h($q) ?>" placeholder="Search title, description, text, tags…" autocomplete="off"
  hx-get="search.php" hx-trigger="keyup changed delay:300ms, search" hx-target="#results" hx-include="[name=sort],[name=seed]" hx-vals='js:{tag: new URLSearchParams(location.search).get("tag") || ""}'></div>
<div class="sortbar"><label>Sort <select name="sort" aria-label="Sort order" hx-get="search.php" hx-trigger="change" hx-target="#results" hx-include="[name=q],[name=seed]" hx-vals='js:{tag: new URLSearchParams(location.search).get("tag") || ""}'>
<option value="">Shuffle</option><option value="new">Newest first</option><option value="old">Oldest first</option><option value="az">A–Z</option><option value="review">Needs review</option></select></label>
<input type="hidden" name="seed" value="<?= random_int(1, 999999) ?>">
<button type="button" class="reshuffle" title="Shuffle again" aria-label="Shuffle again" onclick="var f=this.parentNode;f.querySelector('[name=seed]').value=Math.floor(Math.random()*999999)+1;var s=f.querySelector('select');s.value='';htmx.trigger(s,'change')"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 3h5v5M4 20 21 3M21 16v5h-5M15 15l6 6M4 4l5 5"/></svg></button></div>
<script>(function(){var q=document.querySelector('[name=q]'),o=document.querySelector('[name=sort] option');function l(){o.textContent=q.value.trim()?'Best match':'Shuffle'}q.addEventListener('input',l);l()})()</script>
<?= render_tagcloud($uid, false) ?>
<div id="toast"></div>
<div id="results"><?= render_results($uid, $q, $tag) ?></div>

<script>if(matchMedia("(hover:hover)").matches&&!location.search)document.querySelector("[name=q]").focus()</script>
</body></html>
