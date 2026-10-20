#!/usr/bin/env php
<?php
// Unit tests for URL normalization/cleaning: php bin/test-urls.php
require_once __DIR__ . '/../src/url.php';
$fail = 0; $n = 0;
function t(string $name, $got, $want): void {
    global $fail, $n; $n++;
    if ($got !== $want) { $fail++; echo "FAIL $name\n  got:  " . json_encode($got) . "\n  want: " . json_encode($want) . "\n"; }
}
$clean = [
 'https://example.com/a?utm_source=x&utm_medium=y&id=5' => 'https://example.com/a?id=5',
 'https://example.com/a?id=5&fbclid=abc&b=2' => 'https://example.com/a?id=5&b=2',
 'https://example.com/?gclid=1' => 'https://example.com/',
 'https://Example.COM/Path?UTM_Campaign=x&Q=1' => 'https://example.com/Path?Q=1',
 'https://example.com/a?ga_source=1&_ga=2&ref=twitter&keep=1' => 'https://example.com/a?keep=1',
 'https://example.com/page;jsessionid=ABC123?x=1' => 'https://example.com/page?x=1',
 'https://example.com/p?PHPSESSID=zz&sid=1&aff=9&affiliate=3&z=1' => 'https://example.com/p?z=1',
 'https://example.com/a#:~:text=hello' => 'https://example.com/a',
 'https://example.com/a#xtor=AD-1' => 'https://example.com/a',
 'https://example.com/a#section-2' => 'https://example.com/a#section-2',
 'https://youtu.be/dQw4w9WgXcQ?si=abc&t=42' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=42',
 'https://www.youtube.com/shorts/dQw4w9WgXcQ' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&feature=share&pp=xx&t=10s&list=PL1' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10s&list=PL1',
 'https://open.spotify.com/track/abc?si=123' => 'https://open.spotify.com/track/abc',
 'https://example.com/a?si=keepme' => 'https://example.com/a?si=keepme',
 'https://www.google.com/url?q=https%3A%2F%2Fexample.org%2Fx%3Futm_source%3Da&sa=D' => 'https://example.org/x',
 'https://l.facebook.com/l.php?u=https%3A%2F%2Fexample.net%2Fy%3Ffbclid%3D1&h=AT' => 'https://example.net/y',
 'https://out.reddit.com/t3_x?url=https%3A%2F%2Fexample.io%2Fz&token=a' => 'https://example.io/z',
 'https://www.youtube.com/redirect?q=https%3A%2F%2Fexample.dev%2F&event=video' => 'https://example.dev/',
 'https://www.amazon.de/Some-Product-Name/dp/B08N5WRWNW/ref=sr_1_3?keywords=x&qid=1' => 'https://www.amazon.de/dp/B08N5WRWNW',
 'https://www.amazon.com/gp/product/b08n5wrwnw?tag=x' => 'https://www.amazon.com/dp/B08N5WRWNW',
 'https://example.com/a?b=1&b=2&utm_x=3' => 'https://example.com/a?b=1&b=2',
];
foreach ($clean as $in => $want) t("clean $in", clean_url($in), $want);
$norm = [
 'bla.com' => ['https://bla.com', true], 'www.bla.com' => ['https://www.bla.com', true], 'bla.com/path?x=1' => ['https://bla.com/path?x=1', true],
 '//bla.com' => ['https://bla.com', true], '  bla.com  ' => ['https://bla.com', true], 'http://bla.com/' => ['http://bla.com/', false],
 'HTTPS://Bla.com/A?utm_source=1' => ['https://bla.com/A', false], 'bla.com/?fbclid=1' => ['https://bla.com/', true],
];
foreach ($norm as $in => [$u, $g]) { $r = normalize_input($in); t("norm $in", [$r['url'] ?? $r, $r['guessed'] ?? null], [$u, $g]); }
foreach (['javascript:alert(1)', 'ftp://bla.com', 'localhost', 'bla', 'a b.com', 'data:text/html,x', 'mailto:a@b.com', 'http://', '', 'bla..com', 'file:///etc/passwd'] as $bad) {
    t("invalid '$bad'", isset(normalize_input($bad)['error']), true);
}
$same = [['bla.com', 'https://www.bla.com/'], ['http://bla.com/x/', 'https://www.bla.com/x'], ['https://bla.com/x?utm_source=a', 'bla.com/x#top']];
foreach ($same as [$a, $b]) t("same-key $a / $b", url_key(normalize_input($a)['url']), url_key(normalize_input($b)['url']));
t('different-key', url_key('https://bla.com/a') !== url_key('https://bla.com/b'), true);
echo ($fail ? "$fail FAILED of $n\n" : "all $n passed\n");
exit($fail ? 1 : 0);
