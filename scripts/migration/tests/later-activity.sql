-- Simulated legacy activity AFTER the first sync. Apply to dd_rh_src ONLY (root).
-- 1 status change (normal path: post_modified updated)
UPDATE wp_posts SET post_status='wc-completed', post_modified=UTC_TIMESTAMP(), post_modified_gmt=UTC_TIMESTAMP() WHERE ID=698964;
-- 2 metadata-only order change: tracking added + phone edited, post_modified NOT touched
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES (698965,'_wc_shipment_tracking_items','a:1:{i:0;a:4:{s:17:"tracking_provider";s:3:"dpd";s:15:"tracking_number";s:12:"DDTEST000001";s:13:"date_shipped";s:10:"1790500000";s:11:"tracking_id";s:32:"00000000000000000000000000000001";}}');
UPDATE wp_postmeta SET meta_value='00000 111111' WHERE post_id=698965 AND meta_key='_billing_phone';
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) SELECT 698965,'_billing_phone','00000 111111' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM wp_postmeta WHERE post_id=698965 AND meta_key='_billing_phone');
-- 3 note-only change on a real historical order (e.g. a courier note), order row untouched
INSERT INTO wp_comments (comment_post_ID,comment_author,comment_author_email,comment_date,comment_date_gmt,comment_content,comment_approved,comment_agent,comment_type,user_id)
  VALUES (600000,'WooCommerce','woocommerce@example.invalid',UTC_TIMESTAMP(),UTC_TIMESTAMP(),'DD-MIGRATION-TEST: later note on historical order','1','WooCommerce','order_note',0);
-- 4 customer metadata-only change (no user row change)
UPDATE wp_usermeta SET meta_value='00000 222222' WHERE user_id=17808 AND meta_key='billing_phone';
-- 5 product stock change (commercial field)
UPDATE wp_postmeta SET meta_value=CAST(meta_value AS SIGNED)-1 WHERE post_id=694749 AND meta_key='_stock';
-- 6 new back-in-stock registration (retained plugin table)
INSERT INTO wp_woocommerce_bis_notifications (type,product_id,user_id,user_email,create_date,subscribe_date,last_notified_date,is_queued,is_active,is_verified)
  VALUES ('one-time',694746,17808,'dd-migration-test-a@example.invalid',UNIX_TIMESTAMP(),UNIX_TIMESTAMP(),0,'off','on','yes');
-- 7 coupon usage
UPDATE wp_postmeta SET meta_value=CAST(meta_value AS UNSIGNED)+1 WHERE meta_key='usage_count' AND post_id=(SELECT ID FROM wp_posts WHERE post_type='shop_coupon' AND post_status='publish' ORDER BY ID DESC LIMIT 1);
SELECT 'later activity applied' k, (SELECT ID FROM wp_posts WHERE post_type='shop_coupon' AND post_status='publish' ORDER BY ID DESC LIMIT 1) coupon;
