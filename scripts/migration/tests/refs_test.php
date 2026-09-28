<?php
// Unit tests for the reference rewriter (pure functions). Run: php scripts/migration/tests/refs_test.php
declare(strict_types=1);

use function DDMig\Refs\{tokens, rewrite_int, rewrite_csv, rewrite_serialized, rewrite_patterns, rewrite_blocks, equivalent_under_map, url_pairs, rewrite_urls};

require __DIR__ . '/../refs.php';

$fails = 0;
function check(string $name, bool $ok, mixed $got = null): void
{
    global $fails;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($ok ? '' : '  got: ' . var_export($got, true)) . "\n";
    $fails += $ok ? 0 : 1;
}

$map = [696260 => 9696260, 696618 => 9696618, 696114 => 9696114, 696282 => 9696282, 696212 => 9696212, 696276 => 9696276, 696351 => 9696351, 17553 => 9017553];
$acf = ['field_img' => ['type' => 'image'], 'field_txt' => ['type' => 'text'], 'field_gal' => ['type' => 'gallery']];

// tokens: standalone numbers only; hex/uuid fragments and decimals are not IDs
check('token in json', array_column(tokens('"id":696260,', $map), 0) === [696260]);
check('no token inside hex', tokens('a:1:{i:0;s:13:"67f696260c253";}', $map) === []);
check('no token in uuid segment', tokens('b4e1e04a-a26d-696260d07c96', $map) === []);
check('no token in decimal', tokens('696260.50 and 1.696260', $map) === []);
check('token after colon (edit lock)', array_column(tokens('1790449290:17553', $map), 0) === [17553]);
check('token in revision slug', array_column(tokens('696276-revision-v1', $map), 0) === [696276]);

// ints and csv
check('int remap', rewrite_int('696260', $map) === '9696260');
check('int not in map untouched', rewrite_int('123', $map) === '123');
check('csv remap keeps others', rewrite_csv('696260,5,696114', $map) === '9696260,5,9696114');

// serialized: approved paths only, lengths recomputed, keys when approved
$r = rewrite_serialized('a:2:{s:18:"custom_css_post_id";i:696618;s:5:"other";i:696260;}', $map, ['custom_css_post_id']);
check('ser any unresolved hit leaves value untouched', $r['value'] === 'a:2:{s:18:"custom_css_post_id";i:696618;s:5:"other";i:696260;}', $r);
check('ser unapproved hit reported', $r['unresolved'] === ['other'], $r['unresolved']);
$r = rewrite_serialized('a:2:{s:18:"custom_css_post_id";i:696618;s:5:"other";i:5;}', $map, ['custom_css_post_id']);
check('ser approved path rewritten', $r['value'] === 'a:2:{s:18:"custom_css_post_id";i:9696618;s:5:"other";i:5;}' && $r['unresolved'] === [], $r);
$r = rewrite_serialized('a:1:{s:4:"note";s:10:"see 696260";}', $map, ['x']);
check('ser id inside free text reported', $r['unresolved'] === ['note'], $r);
$r = rewrite_serialized('a:1:{i:4;a:2:{s:13:"media_file_id";s:6:"696114";s:5:"title";s:3:"abc";}}', $map, ['*/media_file_id']);
check('ser numeric string length fixed', $r['value'] === 'a:1:{i:4;a:2:{s:13:"media_file_id";s:7:"9696114";s:5:"title";s:3:"abc";}}' && $r['unresolved'] === [], $r);
$r = rewrite_serialized('a:1:{i:696282;b:1;}', $map, ['@keys']);
check('ser key remap', $r['value'] === 'a:1:{i:9696282;b:1;}', $r);
$r = rewrite_serialized('a:2:{i:655821;i:696260;i:696114;i:1;}', $map, ['@values']);
check('ser values only: keys are foreign ids, untouched', $r['value'] === 'a:2:{i:655821;i:9696260;i:696114;i:1;}' && $r['unresolved'] === [], $r);
check('ser non-roundtrip refused when hit', rewrite_serialized('a:1:{s:1:"x";s:6:"696260"', $map, ['x'])['unresolved'] === ['@unserializable'], null);

// text patterns (guid, slugs, html)
check('guid ?p=', rewrite_patterns('https://x/?p=696260', $map, ['query_id'])['value'] === 'https://x/?p=9696260');
check('guid page_id with entity', rewrite_patterns('/?post_type=wpcf7&#038;p=696260', $map, ['query_id'])['value'] === '/?post_type=wpcf7&#038;p=9696260');
check('revision slug', rewrite_patterns('696276-revision-v1', $map, ['revision_name'])['value'] === '9696276-revision-v1');
$r = rewrite_patterns('see 696260 here', $map, ['query_id']);
check('unmatched pattern reported', $r['value'] === 'see 696260 here' && $r['unresolved'] === ['696260@4'], $r);

// blocks
$html = '<!-- wp:acf/hero {"name":"acf/hero","data":{"img":696260,"_img":"field_img","title":"Top 696114","_title":"field_txt"},"mode":"preview"} /-->';
$r = rewrite_blocks($html, $map, $acf);
check('acf text hit reported, block untouched', $r['value'] === $html && $r['unresolved'] === ['acf/hero:data.title'], $r);
$html = '<!-- wp:acf/hero {"name":"acf/hero","data":{"img":696260,"_img":"field_img","title":"Top","_title":"field_txt"},"mode":"preview"} /-->';
check('acf image rewritten', rewrite_blocks($html, $map, $acf)['value'] === str_replace('696260', '9696260', $html));
$html = '<!-- wp:acf/g {"name":"acf/g","data":{"gal":["696260","696114"],"_gal":"field_gal"}} /-->';
check('acf gallery array', rewrite_blocks($html, $map, $acf)['value'] === '<!-- wp:acf/g {"name":"acf/g","data":{"gal":["9696260","9696114"],"_gal":"field_gal"}} /-->');
$html = '<!-- wp:generateblocks/media {"uniqueId":"2be59a13","mediaId":696212,"htmlAttributes":{"src":"x.png"}} --><img class="gb-media-2be59a13 wp-image-696212" data-id="696212" alt=""/><!-- /wp:generateblocks/media -->';
$r = rewrite_blocks($html, $map, $acf);
check('mediaId + html class/data-id', $r['value'] === '<!-- wp:generateblocks/media {"uniqueId":"2be59a13","mediaId":9696212,"htmlAttributes":{"src":"x.png"}} --><img class="gb-media-2be59a13 wp-image-9696212" data-id="9696212" alt=""/><!-- /wp:generateblocks/media -->' && $r['unresolved'] === [], $r);
$html = '<!-- wp:paragraph --><p>Order 696260 shipped</p><!-- /wp:paragraph -->';
$r = rewrite_blocks($html, $map, $acf);
check('free text number is unresolved, untouched', $r['unresolved'] === ['html:696260@30'] && $r['value'] === $html, $r);

// independent equivalence check used for verification
check('equivalent under map', equivalent_under_map('a:1:{s:1:"a";s:6:"696260";}', 'a:1:{s:1:"a";s:7:"9696260";}', $map));
check('not equivalent when other change', !equivalent_under_map('a:1:{s:1:"a";s:6:"696260";}', 'a:1:{s:1:"b";s:7:"9696260";}', $map));
check('text equivalent under map', equivalent_under_map('x wp-image-696260 y', 'x wp-image-9696260 y', $map));
check('text not equivalent (unmapped digit change)', !equivalent_under_map('x 5 y', 'x 6 y', $map));

// URL/path rewriting of copied legacy values (serialization-safe, JSON-escaped forms included)
$pairs = url_pairs([['https://legacy.local:8443', 'http://localhost:8088'], ['/wp-content/uploads/', '/app/uploads/']]);
check('plain url+path', rewrite_urls('https://legacy.local:8443/wp-content/uploads/a.png', $pairs) === 'http://localhost:8088/app/uploads/a.png');
check('json escaped', rewrite_urls('{"u":"https:\\/\\/legacy.local:8443\\/wp-content\\/uploads\\/a.png"}', $pairs) === '{"u":"http:\\/\\/localhost:8088\\/app\\/uploads\\/a.png"}');
check('serialized length fixed', rewrite_urls('a:1:{s:3:"url";s:36:"https://legacy.local:8443/x/?a=1&b=2";}', $pairs) === 'a:1:{s:3:"url";s:32:"http://localhost:8088/x/?a=1&b=2";}');
check('nested serialized string', rewrite_urls(serialize(['in' => serialize(['u' => 'https://legacy.local:8443/p'])]), $pairs) === serialize(['in' => serialize(['u' => 'http://localhost:8088/p'])]));
check('unrelated untouched', rewrite_urls('https://other.example/wp-content/themes/x.css', $pairs) === 'https://other.example/wp-content/themes/x.css');
check('broken serialized refused', rewrite_urls('a:1:{s:3:"url";s:99:"https://legacy.local:8443/";}', $pairs) === null);

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit($fails ? 1 : 0);
