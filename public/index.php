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
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex"><title>Bookmarks</title><link rel="stylesheet" href="style.css">
<script src="https://unpkg.com/htmx.org@2.0.4"></script>
</head><body hx-headers='<?= h(json_encode(["X-CSRF-Token" => csrf_token()])) ?>'>
<header><h1>Bookmarks</h1>
<span class="count"><?= (int)$count ?> saved<?= $pending ? ", " . (int)$pending . " pending" : "" ?></span>
<?php if ($user['is_admin']): ?><a href="admin.php">Users<?= $reqs ? " ($reqs requests)" : '' ?></a><?php endif; ?>
<form method="post" action="logout.php" class="inline"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><button class="linkbtn" title="<?= h($user['email']) ?>">Log out</button></form></header>
<details class="bmbox"><summary>Bookmarklet</summary>
<p>Drag this to your bookmarks bar: <a class="bml" href="<?= h($js) ?>" onclick="alert('Drag this link to your bookmarks bar.');return false">＋ Save to Bookmarks</a></p>
<p><button type="button" onclick="navigator.clipboard.writeText(document.getElementById('bmjs').value);this.textContent='Copied'">Copy</button>
<input id="bmjs" type="text" readonly value="<?= h($js) ?>" style="width:60%">
<form method="post" action="regenerate.php" class="inline" onsubmit="return confirm('The old bookmarklet will stop working. Continue?')"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><button>Regenerate key</button></form></p></details>
<details class="bmbox"><summary>iOS / macOS share sheet</summary>
<p>Key: <input type="text" readonly value="<?= h($user['bookmarklet_key']) ?>" id="bmkey" style="width:60%"> <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('bmkey').value);this.textContent='Copied'">Copy</button></p>
<p>Endpoint: <code><?= h(base_url() . '/add.php') ?></code> (POST or GET, <code>format=json</code>)</p>
<ol>
<li>Shortcuts app: new Shortcut, name it <b>Save to Bookmarks</b>.</li>
<li>Details: turn on <b>Show in Share Sheet</b>; accept <b>URLs</b>, <b>Safari web pages</b> and <b>Text</b>.</li>
<li>Add <b>Get Contents of URL</b>: URL <code><?= h(base_url() . '/add.php') ?></code>, Method <b>POST</b>, Request Body <b>Form</b> with fields <code>k</code> = your key, <code>u</code> = <b>Shortcut Input</b>, <code>format</code> = <code>json</code>.</li>
<li>Add <b>Get Dictionary Value</b> for <code>status</code>, then <b>Show Notification</b>: "Saved ✓ (status)".</li>
</ol>
<p>The Shortcut syncs to your Mac via iCloud and appears in Safari's Share menu there too. To share it, create an iCloud link with the key removed and add an Import Question for <code>k</code>.</p></details>
<form class="addurl" hx-post="add.php" hx-target="#results" hx-swap="afterbegin" hx-on:bm-added="this.reset()">
<input type="text" name="urls" placeholder="example.com or https://…" required autocomplete="off" autocapitalize="off" spellcheck="false"><button type="submit">Save</button></form>
<div id="addmsg" class="err"></div>
<datalist id="vocab"><?php foreach (user_vocabulary($uid) as $v): ?><option value="<?= h($v) ?>"><?php endforeach; ?></datalist>
<input type="search" name="q" value="<?= h($q) ?>" placeholder="Search title, description, text, tags…" autofocus autocomplete="off"
  hx-get="search.php" hx-trigger="keyup changed delay:300ms, search" hx-target="#results" hx-vals='js:{tag: new URLSearchParams(location.search).get("tag") || ""}'>
<div class="tagcloud">
<?php foreach (top_tags($uid) as $t): $e = rawurlencode($t['tag']); ?>
<a class="tag" href="?tag=<?= h($e) ?>" hx-get="search.php?tag=<?= h($e) ?>" hx-target="#results" hx-push-url="?tag=<?= h($e) ?>" hx-include="[name=q]"><?= h($t['tag']) ?> <small><?= (int)$t['c'] ?></small></a>
<?php endforeach; ?></div>
<div id="results"><?= render_results($uid, $q, $tag) ?></div>
<p class="foot">New bookmarks are processed by the background worker (about a minute).</p>
</body></html>
