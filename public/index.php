<?php
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/view.php';
$user = require_login();
$uid = (int)$user['id'];
$tag = trim((string)($_GET['tag'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));
$add = base_url() . '/add.php';
$js = "javascript:(function(){window.open('" . $add . "?k=" . $user['bookmarklet_key'] . "&u='+encodeURIComponent(location.href)+'&t='+encodeURIComponent(document.title),'bm','width=300,height=120')})()";
$st = db()->prepare("SELECT COUNT(*), COALESCE(SUM(status='pending'),0) FROM bookmarks WHERE user_id=?"); $st->execute([$uid]);
[$count, $pending] = $st->fetch(PDO::FETCH_NUM);
$reqs = $user['is_admin'] ? (int)db()->query("SELECT COUNT(*) FROM users WHERE status='pending'")->fetchColumn() : 0;
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex"><title>Bookmarks</title><link rel="stylesheet" href="style.css">
<script src="https://unpkg.com/htmx.org@2.0.4"></script>
</head><body>
<header><h1>Bookmarks</h1>
<span class="count"><?= (int)$count ?> saved<?= $pending ? ", " . (int)$pending . " pending" : "" ?></span>
<?php if ($user['is_admin']): ?><a href="admin.php">Users<?= $reqs ? " ($reqs requests)" : '' ?></a><?php endif; ?>
<form method="post" action="logout.php" class="inline"><button class="linkbtn" title="<?= h($user['email']) ?>">Log out</button></form></header>
<details class="bmbox"><summary>Bookmarklet</summary>
<p>Drag this to your bookmarks bar: <a class="bml" href="<?= h($js) ?>" onclick="alert('Drag this link to your bookmarks bar.');return false">＋ Save to Bookmarks</a></p>
<p><button type="button" onclick="navigator.clipboard.writeText(document.getElementById('bmjs').value);this.textContent='Copied'">Copy</button>
<input id="bmjs" type="text" readonly value="<?= h($js) ?>" style="width:60%">
<form method="post" action="regenerate.php" class="inline" onsubmit="return confirm('The old bookmarklet will stop working. Continue?')"><button>Regenerate key</button></form></p></details>
<input type="search" name="q" value="<?= h($q) ?>" placeholder="Search title, description, text, tags…" autofocus autocomplete="off"
  hx-get="search.php" hx-trigger="keyup changed delay:300ms, search" hx-target="#results" hx-vals='js:{tag: new URLSearchParams(location.search).get("tag") || ""}'>
<div class="tagcloud">
<?php foreach (top_tags($uid) as $t): $e = rawurlencode($t['tag']); ?>
<a class="tag" href="?tag=<?= h($e) ?>" hx-get="search.php?tag=<?= h($e) ?>" hx-target="#results" hx-push-url="?tag=<?= h($e) ?>" hx-include="[name=q]"><?= h($t['tag']) ?> <small><?= (int)$t['c'] ?></small></a>
<?php endforeach; ?></div>
<div id="results"><?= render_results($uid, $q, $tag) ?></div>
<p class="foot">New bookmarks are processed by the background worker (about a minute).</p>
</body></html>
