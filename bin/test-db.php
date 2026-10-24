#!/usr/bin/env php
<?php
// Tests for the database layer on a throw-away database: php bin/test-db.php
if (PHP_SAPI !== 'cli') exit(1);
$dir = sys_get_temp_dir() . '/bm-test-' . getmypid();
mkdir($dir);
file_put_contents("$dir/config.php", "<?php return ['db_path' => '$dir/t.db', 'lock_path' => '$dir/w.lock', 'base_url' => 'http://localhost', 'seed_tags_file' => '" . __DIR__ . "/../seed_tags.txt', 'max_attempts' => 3];");
putenv("BM_CONFIG=$dir/config.php");
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/bookmarks.php';
require_once __DIR__ . '/../src/pipeline.php';
require_once __DIR__ . '/../src/tagger.php';

$fail = 0; $n = 0;
function t(string $name, $got, $want): void {
    global $fail, $n; $n++;
    if ($got !== $want) { $fail++; echo "FAIL $name\n  got:  " . json_encode($got) . "\n  want: " . json_encode($want) . "\n"; }
}
$ids = fn(array $rows) => array_map('intval', array_column($rows, 'id'));

// schema
t('migrated to latest', (int)q('PRAGMA user_version')->fetchColumn(), SCHEMA_VERSION);
$a = create_user('a@x.com', false, 'active'); $b = create_user('b@x.com', false, 'active');

// add, duplicates, jobs row from the trigger
$r1 = add_bookmark($a, 'https://example.com/one?utm_source=x', 'One');
t('add', $r1['status'], 'added');
t('job row created', q('SELECT status FROM jobs WHERE bookmark_id=?', [$r1['id']])->fetchColumn(), 'pending');
t('duplicate (www + slash + tracking)', add_bookmark($a, 'http://www.example.com/one/?utm_medium=y')['status'], 'dup');
t('same URL for another user', add_bookmark($b, 'https://example.com/one')['status'], 'added');
t('invalid', add_bookmark($a, 'javascript:alert(1)')['status'], 'invalid');
$r2 = add_bookmark($a, 'https://example.org/two', '', ['client_title' => 'CT', 'client_text' => 'page text', 'client_desc' => 'd']);
t('capture stored', q('SELECT client_title FROM capture WHERE bookmark_id=?', [$r2['id']])->fetchColumn(), 'CT');
t('worker takes the oldest first', next_pending(3)['id'] ?? 0, $r1['id']);
q("UPDATE jobs SET status='done' WHERE bookmark_id<>?", [$r2['id']]);
t('capture reaches the crawler row', next_pending(3)['client_text'], 'page text');

// tags + search
q("INSERT INTO tags VALUES (?, 'alpha', 0), (?, 'beta', 1)", [$r1['id'], $r1['id']]);
fts_reindex($r1['id']);
t('search by word', $ids(search_bookmarks($a, 'one', '')), [$r1['id']]);
t('search by tag text', $ids(search_bookmarks($a, 'alpha', '')), [$r1['id']]);
t('tag filter', $ids(search_bookmarks($a, '', 'alpha')), [$r1['id']]);
t('other user sees nothing of mine', search_bookmarks($b, 'two', ''), []);
t('suggested tags need to be established', fetch_card($r1['id'], $a)['suggested'], []);
t('card has job state', fetch_card($r1['id'], $a)['status'], 'done');
t('not the owner', fetch_card($r1['id'], $b), null);
foreach (['new', 'old', 'az', 'review', 'match', ''] as $s) t("sort $s runs", is_array(search_bookmarks($a, $s === 'match' ? 'one' : '', '', 10, $s, 7)), true);

// trash: hidden everywhere, restorable, purgeable
t('trash', trash_bookmark($r1['id'], $a), true);
t('trash twice', trash_bookmark($r1['id'], $a), false);
t('not found while trashed', search_bookmarks($a, 'one', ''), []);
t('not in FTS while trashed', (int)q('SELECT COUNT(*) FROM bookmarks_fts WHERE rowid=?', [$r1['id']])->fetchColumn(), 0);
t('card gone while trashed', fetch_card($r1['id'], $a), null);
t('tag cloud ignores trashed', top_tags($a), []);
t('worker ignores trashed', next_pending(3)['id'], $r2['id']);
t('trash list', array_column(trash_list($a), 'id'), [$r1['id']]);
t('other user cannot restore', restore_bookmark($r1['id'], $b), null);
t('restore', restore_bookmark($r1['id'], $a), $r1['id']);
t('searchable again', $ids(search_bookmarks($a, 'one', '')), [$r1['id']]);
t('tags came back', fetch_card($r1['id'], $a)['tags'], ['alpha']);
trash_bookmark($r1['id'], $a);
t('re-saving a trashed URL starts fresh', add_bookmark($a, 'https://example.com/one')['status'], 'added');
t('trash is empty then', trash_list($a), []);
$id3 = add_bookmark($a, 'https://example.net/three')['id'];
trash_bookmark($id3, $a); purge_bookmark($id3, $a);
t('purge', q('SELECT COUNT(*) FROM bookmarks WHERE id=?', [$id3])->fetchColumn() == 0, true);
t('purge keeps live bookmarks', purge_bookmark($r2['id'], $a) ?? q('SELECT COUNT(*) FROM bookmarks WHERE id=?', [$r2['id']])->fetchColumn() == 1, true);
q('UPDATE bookmarks SET deleted_at=? WHERE id=?', [time() - 86400 * 40, $r2['id']]);
trash_bookmark(add_bookmark($a, 'https://example.net/four')['id'], $a);   // trashing purges what is older than TRASH_DAYS
t('expired trash is purged', q('SELECT COUNT(*) FROM bookmarks WHERE id=?', [$r2['id']])->fetchColumn() == 0, true);

// url_taken: live duplicate, trashed copy is dropped
$x = add_bookmark($a, 'https://dup.example/x')['id']; $y = add_bookmark($a, 'https://dup.example/y')['id'];
t('url_taken live', url_taken($a, url_key('https://dup.example/x'), $y), true);
t('url_taken free', url_taken($a, url_key('https://dup.example/z'), $y), false);
trash_bookmark($x, $a);
t('url_taken ignores and drops a trashed copy', url_taken($a, url_key('https://dup.example/x'), $y), false);
t('trashed copy dropped', q('SELECT COUNT(*) FROM bookmarks WHERE id=?', [$x])->fetchColumn() == 0, true);

// job state: requeue keeps applied tags of corrected bookmarks (refresh), error/pending handling
q("UPDATE bookmarks SET tags_edited=1 WHERE id=?", [$y]);
q("UPDATE jobs SET status='error', error='boom', attempts=3, llm_pending=1 WHERE bookmark_id=?", [$y]);
requeue($y);
$j = q('SELECT * FROM jobs WHERE bookmark_id=?', [$y])->fetch();
t('requeue', [$j['status'], $j['attempts'], $j['error'], $j['refresh'], $j['llm_pending']], ['pending', 0, null, 1, 0]);
q("UPDATE jobs SET attempts=3 WHERE bookmark_id=?", [$y]);
fail_exhausted(3);
t('exhausted attempts become errors', q('SELECT status FROM jobs WHERE bookmark_id=?', [$y])->fetchColumn(), 'error');
t('re-saving an errored URL retries it', [add_bookmark($a, 'https://dup.example/y')['status'], q('SELECT status FROM jobs WHERE bookmark_id=?', [$y])->fetchColumn()], ['dup', 'pending']);

// save_tags: replaced, or kept for corrected bookmarks
$z = add_bookmark($a, 'https://tags.example/z')['id'];
q("INSERT INTO tags VALUES (?, 'old', 0)", [$z]);
$res = ['tags' => ['new1', 'new2'], 'suggested' => ['s1']];
save_tags($z, $res, ['tags_edited' => 0, 'refresh' => 0]);
t('tags replaced', q('SELECT tag, suggested FROM tags WHERE bookmark_id=? ORDER BY tag', [$z])->fetchAll(PDO::FETCH_NUM), [['new1', 0], ['new2', 0], ['s1', 1]]);
save_tags($z, ['tags' => ['zzz'], 'suggested' => []], ['tags_edited' => 1, 'refresh' => 0]);
t('corrected bookmark untouched', count(q('SELECT 1 FROM tags WHERE bookmark_id=?', [$z])->fetchAll()), 3);
save_tags($z, ['tags' => ['zzz'], 'suggested' => ['s1', 's2']], ['tags_edited' => 1, 'refresh' => 1]);
t('refresh keeps applied, adds new', q('SELECT tag, suggested FROM tags WHERE bookmark_id=? ORDER BY tag', [$z])->fetchAll(PDO::FETCH_NUM), [['new1', 0], ['new2', 0], ['s1', 1], ['s2', 1], ['zzz', 0]]);

// fresh database from scratch equals a migrated one: second connection sees the same version and does not re-run
t('migrate is a no-op when current', (function () { migrate(db()); return (int)q('PRAGMA user_version')->fetchColumn(); })(), SCHEMA_VERSION);

array_map('unlink', glob("$dir/*")); rmdir($dir);
echo $fail ? "$fail of $n FAILED\n" : "all $n passed\n";
exit($fail ? 1 : 0);
