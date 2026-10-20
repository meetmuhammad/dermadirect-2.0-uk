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
// Customer rule (shared by sync, staff restore, reconcile and verify): verified group roles count as
// customers; staff, mixed customer+staff, unknown roles and group-only accounts do not.
$caps = fn (array $roles) => serialize(array_fill_keys($roles, true));
$isC = fn (array $roles) => \DDMig\is_customer_caps($caps($roles));
check('ordinary customer is a customer', $isC(['customer']));
check('Nexfill Switch customer (customer + nexfill_switch) is a customer', $isC(['customer', 'nexfill_switch']));
check('blocked customer is a customer (no new enforcement, role kept)', $isC(['blocked_customer']));
check('blocked customer in the group is a customer', $isC(['blocked_customer', 'nexfill_switch']));
check('staff (shop_manager) is not a customer', !$isC(['shop_manager']));
check('administrator is not a customer', !$isC(['administrator']));
check('mixed customer + shop_manager follows the staff policy', !$isC(['customer', 'shop_manager']));
check('mixed customer + administrator follows the staff policy', !$isC(['customer', 'administrator']));
check('customer + an unknown role follows the staff policy (unknown roles are not assumed safe)', !$isC(['customer', 'some_new_role']));
check('group role alone (no base customer role) follows the staff policy', !$isC(['nexfill_switch']));
check('"customer-service" staff role is not mistaken for customer', !$isC(['customer-service']));
check('empty capabilities are not a customer', !\DDMig\is_customer_caps(serialize([])) && !\DDMig\is_customer_caps(null));
check('a role granted false does not count', $isC(['customer']) && \DDMig\is_customer_caps(serialize(['customer' => true, 'administrator' => false])));
// CHECKSUM TABLE answers: NULL (missing/unreadable table), empty, non-numeric or missing rows never compare
$ce = fn (array $rows, int $n = 1) => \DDMig\checksum_error($rows, $n);
check('numeric checksum accepted', $ce([['Table' => 'db.wp_posts', 'Checksum' => '123']]) === null);
check('NULL checksum refused', $ce([['Table' => 'db.wp_posts', 'Checksum' => null]]) !== null);
check('empty checksum refused', $ce([['Table' => 'db.wp_posts', 'Checksum' => '']]) !== null);
check('non-numeric checksum refused', $ce([['Table' => 'db.wp_posts', 'Checksum' => '12ab']]) !== null);
check('missing row refused', $ce([['Table' => 'a.x', 'Checksum' => '1']], 2) !== null);
echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit($fails ? 1 : 0);
