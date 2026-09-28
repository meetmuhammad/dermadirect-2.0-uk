<?php
// Unit tests for source/destination table references. Run: php scripts/migration/tests/lib_test.php
declare(strict_types=1);
use DDMig\TableRef;
use function DDMig\tables_overlap;
require __DIR__ . '/../lib.php';
$fails = 0;
function check(string $n, bool $ok): void { global $fails; echo ($ok ? 'ok   ' : 'FAIL ') . "$n\n"; $fails += $ok ? 0 : 1; }
$S = new TableRef('dermadirect20uk', 'lgcy_');
check('bare name', $S['posts'] === '`dermadirect20uk`.`lgcy_posts`');
check('wp_ name mapped to prefix', $S['wp_woocommerce_order_items'] === '`dermadirect20uk`.`lgcy_woocommerce_order_items`');
check('interpolation', "FROM {$S['postmeta']} x" === 'FROM `dermadirect20uk`.`lgcy_postmeta` x');
check('name() for information_schema', $S->name('wp_wc_orders') === 'lgcy_wc_orders');
$D = new TableRef('dermadirect20uk', 'wp_');
check('distinct prefixes, same schema: no overlap', !tables_overlap($S, $D));
check('same prefix, same schema: overlap', tables_overlap(new TableRef('x', 'wp_'), new TableRef('x', 'wp_')));
check('nested prefix wp_ vs wp_old_: overlap', tables_overlap(new TableRef('x', 'wp_old_'), new TableRef('x', 'wp_')));
check('different schemas never overlap', !tables_overlap(new TableRef('a', 'wp_'), new TableRef('b', 'wp_')));
echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit($fails ? 1 : 0);
