<?php
/**
 * Generates SQL that simulates legacy activity on the REHEARSAL SOURCE copy (dd_rh_src) only:
 * two test customers with known passwords (WordPress 6.9 bcrypt and legacy phpass hashes), their
 * orders (items, variation, shipping, tax, coupon, notes, Super Payments reference, refund) and a
 * guest order. All data is marked "DD-MIGRATION-TEST" and uses example.invalid addresses.
 *   php make-fixtures.php <wp-includes dir> <password-env-out> > fixtures.sql
 */
require $argv[1] . '/class-phpass.php';
$orderOnly = $argv[3] ?? null; // "order-only:<tag>:<customer_id>" emits just one new order (later-activity test)

if ($orderOnly) { goto orders; }
$pwA = bin2hex(random_bytes(9));
$pwB = bin2hex(random_bytes(9));
// WordPress 6.8+ wp_hash_password(): '$wp' . password_hash(base64(hmac-sha384(pw, 'wp-sha384')), BCRYPT)
$hashA = '$wp' . password_hash(base64_encode(hash_hmac('sha384', $pwA, 'wp-sha384', true)), PASSWORD_BCRYPT);
$hashB = (new PasswordHash(8, true))->HashPassword($pwB);
file_put_contents($argv[2], "RH_CUSTOMER_LOGIN=dd-migration-test-a\nRH_CUSTOMER_PASS=$pwA\nRH_CUSTOMER_B_LOGIN=dd-migration-test-b\nRH_CUSTOMER_B_PASS=$pwB\n");
chmod($argv[2], 0600);

$now = gmdate('Y-m-d H:i:s');
$q = fn ($s) => "'" . addslashes((string) $s) . "'";
echo "-- DD-MIGRATION-TEST fixtures, generated $now UTC. Apply to dd_rh_src ONLY.\n";

$user = function (string $login, string $hash, string $first) use ($q, $now) {
    $email = "$login@example.invalid";
    return "INSERT INTO wp_users (user_login,user_pass,user_nicename,user_email,user_url,user_registered,user_activation_key,user_status,display_name)
      VALUES ({$q($login)},{$q($hash)},{$q($login)},{$q($email)},'',{$q($now)},'',0,{$q("$first Test")});
    SET @u_$first := LAST_INSERT_ID();
    INSERT INTO wp_usermeta (user_id,meta_key,meta_value) VALUES
      (@u_$first,'wp_capabilities','a:1:{s:8:\"customer\";b:1;}'),(@u_$first,'wp_user_level','0'),
      (@u_$first,'first_name',{$q($first)}),(@u_$first,'last_name','Test'),(@u_$first,'billing_first_name',{$q($first)}),
      (@u_$first,'billing_last_name','Test'),(@u_$first,'billing_email',{$q($email)}),(@u_$first,'billing_phone','00000 000000'),
      (@u_$first,'billing_address_1','1 Test Street'),(@u_$first,'billing_city','Testville'),(@u_$first,'billing_postcode','ZZ99 9ZZ'),
      (@u_$first,'billing_country','GB'),(@u_$first,'paying_customer','1'),(@u_$first,'session_tokens','a:0:{}'),
      (@u_$first,'afreg_new_user_status','approved');\n";
};
echo $user('dd-migration-test-a', $hashA, 'Alpha');
echo $user('dd-migration-test-b', $hashB, 'Bravo');

orders:
$now = gmdate('Y-m-d H:i:s');
$q = fn ($s) => "'" . addslashes((string) $s) . "'";
// A published simple product and a variation that exist on both sides (identity verified by ID+SKU).
echo "SET @simple := 694749; SET @var := (SELECT ID FROM wp_posts WHERE post_type='product_variation' AND post_parent=490312 ORDER BY ID LIMIT 1);\n";

$order = function (string $tag, string $cust, string $status, string $total, string $method, bool $withRefund) use ($q, $now) {
    $key = 'wc_order_' . substr(md5($tag), 0, 13);
    $sql = "INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_content,post_title,post_excerpt,post_status,comment_status,ping_status,post_password,post_name,to_ping,pinged,post_modified,post_modified_gmt,post_content_filtered,post_parent,guid,menu_order,post_type,post_mime_type,comment_count)
      VALUES (1,{$q($now)},{$q($now)},'',{$q("DD-MIGRATION-TEST $tag")},'',{$q($status)},'open','closed',{$q($key)},{$q("order-$tag")},'','',{$q($now)},{$q($now)},'',0,'',0,'shop_order','',2);
    SET @o := LAST_INSERT_ID(); SET @o_$tag := @o;
    UPDATE wp_posts SET guid=CONCAT('https://dermadirect.co.uk/?post_type=shop_order&p=',@o) WHERE ID=@o;
    INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
      (@o,'_order_key',{$q($key)}),(@o,'_customer_user',$cust),(@o,'_order_currency','GBP'),(@o,'_prices_include_tax','yes'),
      (@o,'_billing_first_name','Test'),(@o,'_billing_last_name','Fixture'),(@o,'_billing_email',{$q("$tag@example.invalid")}),
      (@o,'_billing_address_1','1 Test Street'),(@o,'_billing_city','Testville'),(@o,'_billing_postcode','ZZ99 9ZZ'),(@o,'_billing_country','GB'),
      (@o,'_shipping_first_name','Test'),(@o,'_shipping_last_name','Fixture'),(@o,'_shipping_address_1','1 Test Street'),(@o,'_shipping_city','Testville'),
      (@o,'_shipping_postcode','ZZ99 9ZZ'),(@o,'_shipping_country','GB'),(@o,'_payment_method',{$q($method)}),(@o,'_payment_method_title','Test method'),
      (@o,'_order_total',{$q($total)}),(@o,'_order_tax','20.00'),(@o,'_order_shipping','5.00'),(@o,'_order_shipping_tax','1.00'),
      (@o,'_cart_discount','10.00'),(@o,'_cart_discount_tax','2.00'),(@o,'_date_paid','1790000000'),(@o,'_paid_date',{$q($now)}),
      (@o,'_transaction_id',{$q("test-txn-$tag")}),(@o,'super_transaction_id',{$q("00000000-0000-4000-8000-" . substr(md5($tag), 0, 12))}),
      (@o,'marketing_opt_in_consent','no'),(@o,'_order_version','10.7.0'),(@o,'_created_via','checkout'),(@o,'_recorded_sales','yes'),(@o,'_order_stock_reduced','yes');
    INSERT INTO wp_woocommerce_order_items (order_item_name,order_item_type,order_id) VALUES ('Fixture simple product','line_item',@o); SET @i1 := LAST_INSERT_ID();
    INSERT INTO wp_woocommerce_order_itemmeta (order_item_id,meta_key,meta_value) VALUES (@i1,'_product_id',@simple),(@i1,'_variation_id','0'),(@i1,'_qty','2'),
      (@i1,'_line_subtotal','83.33'),(@i1,'_line_subtotal_tax','16.67'),(@i1,'_line_total','75.00'),(@i1,'_line_tax','15.00'),(@i1,'_tax_class',''),(@i1,'_reduced_stock','2');
    INSERT INTO wp_woocommerce_order_items (order_item_name,order_item_type,order_id) VALUES ('Fixture variation','line_item',@o); SET @i2 := LAST_INSERT_ID();
    INSERT INTO wp_woocommerce_order_itemmeta (order_item_id,meta_key,meta_value) VALUES (@i2,'_product_id','490312'),(@i2,'_variation_id',@var),(@i2,'_qty','1'),
      (@i2,'_line_subtotal','25.00'),(@i2,'_line_subtotal_tax','5.00'),(@i2,'_line_total','25.00'),(@i2,'_line_tax','5.00'),(@i2,'_tax_class','');
    INSERT INTO wp_woocommerce_order_items (order_item_name,order_item_type,order_id) VALUES ('Next Day','shipping',@o); SET @i3 := LAST_INSERT_ID();
    INSERT INTO wp_woocommerce_order_itemmeta (order_item_id,meta_key,meta_value) VALUES (@i3,'method_id','flexible_shipping_single'),(@i3,'cost','5.00'),(@i3,'total_tax','1.00');
    INSERT INTO wp_woocommerce_order_items (order_item_name,order_item_type,order_id) VALUES ('GB-VAT-1','tax',@o); SET @i4 := LAST_INSERT_ID();
    INSERT INTO wp_woocommerce_order_itemmeta (order_item_id,meta_key,meta_value) VALUES (@i4,'rate_id','1'),(@i4,'label','VAT'),(@i4,'tax_amount','20.00'),(@i4,'shipping_tax_amount','1.00'),(@i4,'rate_percent','20');
    INSERT INTO wp_woocommerce_order_items (order_item_name,order_item_type,order_id) VALUES ('ddtest10','coupon',@o); SET @i5 := LAST_INSERT_ID();
    INSERT INTO wp_woocommerce_order_itemmeta (order_item_id,meta_key,meta_value) VALUES (@i5,'discount_amount','10.00'),(@i5,'discount_amount_tax','2.00');
    INSERT INTO wp_comments (comment_post_ID,comment_author,comment_author_email,comment_date,comment_date_gmt,comment_content,comment_approved,comment_agent,comment_type,user_id)
      VALUES (@o,'WooCommerce','woocommerce@example.invalid',{$q($now)},{$q($now)},'DD-MIGRATION-TEST: payment complete (fixture note)','1','WooCommerce','order_note',0),
             (@o,'WooCommerce','woocommerce@example.invalid',{$q($now)},{$q($now)},'DD-MIGRATION-TEST: customer note (fixture)','1','WooCommerce','order_note',0);
    INSERT INTO wp_commentmeta (comment_id,meta_key,meta_value) VALUES (LAST_INSERT_ID(),'is_customer_note','1');\n";
    if ($withRefund) {
        $sql .= "INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_content,post_title,post_excerpt,post_status,comment_status,ping_status,post_password,post_name,to_ping,pinged,post_modified,post_modified_gmt,post_content_filtered,post_parent,guid,menu_order,post_type,post_mime_type,comment_count)
          VALUES (1,{$q($now)},{$q($now)},'','DD-MIGRATION-TEST refund','','wc-completed','closed','closed','',{$q("refund-$tag")},'','',{$q($now)},{$q($now)},'',@o,'',0,'shop_order_refund','',0);
        SET @r := LAST_INSERT_ID(); SET @r_$tag := @r;
        INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES (@r,'_order_currency','GBP'),(@r,'_refund_amount','30.00'),(@r,'_refunded_by','1'),(@r,'_refund_reason','DD-MIGRATION-TEST partial refund'),(@r,'_order_total','-30.00'),(@r,'_refunded_payment','1');
        INSERT INTO wp_woocommerce_order_items (order_item_name,order_item_type,order_id) VALUES ('Fixture simple product','line_item',@r); SET @ri := LAST_INSERT_ID();
        INSERT INTO wp_woocommerce_order_itemmeta (order_item_id,meta_key,meta_value) VALUES (@ri,'_product_id',@simple),(@ri,'_qty','-1'),(@ri,'_line_total','-25.00'),(@ri,'_line_tax','-5.00'),(@ri,'_refunded_item_id',@i1);\n";
    }
    return $sql;
};
if ($orderOnly) {
    [, $tag, $cust] = explode(':', $orderOnly);
    echo $order($tag, $cust, 'wc-processing', '106.00', 'superpayments', false);
    echo "SELECT 'new order' k, @o_$tag id;\n";
    exit;
}
echo $order('alpha1', '@u_Alpha', 'wc-completed', '106.00', 'superpayments', true);
echo $order('alpha2', '@u_Alpha', 'wc-processing', '106.00', 'stripe_cc', false);
echo $order('bravo1', '@u_Bravo', 'wc-completed', '106.00', 'superpayments', false);
echo $order('guest1', '0', 'wc-completed', '106.00', 'superpayments', false);
echo "SELECT 'fixtures' k, @u_Alpha alpha, @u_Bravo bravo, @o_alpha1 o_alpha1, @r_alpha1 r_alpha1, @o_alpha2 o_alpha2, @o_bravo1 o_bravo1, @o_guest1 o_guest1;\n";
