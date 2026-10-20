<?php
require_once __DIR__ . '/../src/view.php';
require_login();
$tag = trim((string)($_GET['tag'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));
$base = cfg('base_url') ?: ((!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/'));
$add = rtrim($base, '/') . '/add.php';
$js = "javascript:(function(){window.open('" . $add . "?k=" . rawurlencode(cfg('token')) . "&u='+encodeURIComponent(location.href)+'&t='+encodeURIComponent(document.title),'bm','width=300,height=120')})()";
$count = db()->query("SELECT COUNT(*) FROM bookmarks")->fetchColumn();
$pending = db()->query("SELECT COUNT(*) FROM bookmarks WHERE status='pending'")->fetchColumn();
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex"><title>Bookmarks</title><link rel="stylesheet" href="style.css">
<script src="https://unpkg.com/htmx.org@2.0.4"></script>
</head><body>
<header><h1>Bookmarks</h1>
<span class="count"><?= (int)$count ?> saved<?= $pending ? ', ' . (int)$pending . ' pending' : '' ?></span>
<a class="bml" href="<?= h($js) ?>" title="Drag this to your bookmarks bar" onclick="alert('Drag this link to your bookmarks bar.');return false">+ Save bookmark</a></header>
<input type="search" name="q" value="<?= h($q) ?>" placeholder="Search title, description, text, tags…" autofocus autocomplete="off"
  hx-get="search.php" hx-trigger="keyup changed delay:300ms, search" hx-target="#results" hx-vals='js:{tag: new URLSearchParams(location.search).get("tag") || ""}'>
<div class="tagcloud">
<?php foreach (top_tags() as $t): $e = rawurlencode($t['tag']); ?>
<a class="tag" href="?tag=<?= h($e) ?>" hx-get="search.php?tag=<?= h($e) ?>" hx-target="#results" hx-push-url="?tag=<?= h($e) ?>" hx-include="[name=q]"><?= h($t['tag']) ?> <small><?= (int)$t['c'] ?></small></a>
<?php endforeach; ?></div>
<div id="results"><?= render_results($q, $tag) ?></div>
<p class="foot">Bookmarklet: drag “+ Save bookmark” to your bookmarks bar. Adds are processed by the background worker (about a minute).</p>
</body></html>
