<?php
/**
 * Plugin Name: DermaDirect review-site fail-closed Stripe test-mode guard (named testers)
 *
 * Generalises the bounded 2026-10-21 single-customer guard for a ~1 week review by named testers.
 * Fail-closed: no gateway for anyone except logged-in users flagged usermeta dd_review_tester=1 while the
 * window option dd_review_sandbox is active and unexpired; only stripe_cc; only sk_test_/pk_test_ keys; live keys
 * stripped from the option; HTTP only to api.stripe.com v1 (allow-listed endpoints); saved cards hidden; no Stripe
 * receipt emails; every checkout order is labelled _dd_review_order; ALL provider callbacks stay blocked until a
 * reviewed webhook rule is added.
 */
namespace DermaDirect\ReviewSandbox;
defined('ABSPATH') || exit;
if (!defined('WP_ENV') || WP_ENV !== 'staging' || realpath(ABSPATH.'/../..') !== '/www/dermadirect20uk_672/public') { return; }
const OPTION='dd_review_sandbox';
function context(): bool {
 $s=get_option(OPTION,[]);
 $uid=get_current_user_id();
 return !empty($s['active']) && time()<(int)($s['expires']??0) && $uid>0 && get_user_meta($uid,'dd_review_tester',true)==='1';
}
// Preserve stored credentials, but never expose live credentials through this plugin's options.
add_filter('option_woocommerce_stripe_api_settings',function($v){
 if(!is_array($v))return $v;
 $v['debug_log']='no';$v['mode']='test';$v['testmode']='yes';$v['test_mode']='yes';
 foreach(['secret_key_live','publishable_key_live','webhook_secret_live','refresh_token','secret_key','publishable_key'] as $k)$v[$k]='';
 return $v;
},PHP_INT_MAX);
add_filter('wc_stripe_mode',static fn()=> 'test',PHP_INT_MAX);
add_filter('wc_stripe_get_secret_key',static fn($v,$mode)=>$mode==='test' && is_string($v) && preg_match('/^(sk|rk)_test_/',$v)?$v:'',PHP_INT_MAX,2);
add_filter('wc_stripe_get_publishable_key',static fn($v,$mode)=>$mode==='test' && is_string($v) && str_starts_with($v,'pk_test_')?$v:'',PHP_INT_MAX,2);
add_filter('woocommerce_available_payment_gateways',function($g){return context() && isset($g['stripe_cc'])?['stripe_cc'=>$g['stripe_cc']]:[];},PHP_INT_MAX);
// Label every review checkout; imported orders are never modified here. GBP only.
add_action('woocommerce_checkout_create_order',function($order){
 if(!context())return;
 if(strtoupper($order->get_currency())!=='GBP')throw new \Exception('Review checkout is GBP only.');
 $order->update_meta_data('_dd_review_order','1');
},PHP_INT_MAX);
// Saved cards are never offered during review (testers pay with Stripe test cards).
add_filter('woocommerce_saved_payment_methods_list','__return_empty_array',PHP_INT_MAX);
add_filter('wc_stripe_payment_intent_args',function($args,$order){
 if(!context() || $order->get_meta('_dd_review_order')!=='1' || $order->get_customer_id()!==get_current_user_id())throw new \RuntimeException('Non-review order blocked');
 if(strtolower($args['currency']??'')!=='gbp')throw new \RuntimeException('Review currency limit');
 unset($args['receipt_email'],$args['setup_future_usage']);$args['metadata']['dd_review']='1';return $args;
},PHP_INT_MAX,2);
// Reject provider callbacks during this bounded test; no migrated order may be updated by them.
// Only exceptions: GET/POST to the WC REST webhooks collection and GET/PUT/PATCH to webhooks with
// id >= DD_WEBHOOK_MIN_EDITABLE_ID (default 27) (see dd-review-sandbox/callback-guard.php).
require_once __DIR__.'/dd-review-sandbox/callback-guard.php';
add_action('init',function(){
 if(is_blocked_callback((string)($_SERVER['REQUEST_URI']??''),(string)($_SERVER['REQUEST_METHOD']??''),is_array($_POST)?$_POST:[],(string)($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']??''))){status_header(403);exit('Review-site integration callbacks disabled');}
},-9999);
// Load the existing installed SDK early so no plugin boot can use its unguarded default transport.
$autoload=WP_CONTENT_DIR.'/plugins/woo-stripe-payment/vendor/autoload.php';
if(!is_file($autoload)){throw new \RuntimeException('Review QA: required installed SDK missing');}
require_once $autoload;
final class GuardedTransport implements \PaymentPlugins\Vendor\Stripe\HttpClient\ClientInterface {
 public static function validate($method,$url,$headers,$params,$hasFile,$apiMode,$allowed,$expected): bool {
  if(!$allowed || $hasFile || $apiMode!=='v1' || !in_array(strtolower($method),['get','post'],true))return false;
  $u=parse_url($url);
  if(!$u || ($u['scheme']??'')!=='https' || ($u['host']??'')!=='api.stripe.com' || isset($u['port']) || isset($u['user']) || isset($u['pass']) || isset($u['query']) || isset($u['fragment']))return false;
  if(!preg_match('~^/v1/(account|customers(?:/cus_[A-Za-z0-9]+)?|payment_methods(?:/pm_[A-Za-z0-9]+(?:/attach)?)?|payment_intents(?:/pi_[A-Za-z0-9]+(?:/(confirm|cancel))?)?)$~D',$u['path']??''))return false;
  if(!is_string($expected) || !preg_match('/^(sk|rk)_test_/',$expected))return false;
  $auth=[];foreach($headers as $h){if(stripos($h,'Authorization:')===0)$auth[]=trim(substr($h,14));if(stripos($h,'Stripe-Account:')===0)return false;}
  return count($auth)===1 && hash_equals('Bearer '.$expected,$auth[0]);
 }
 public function request($method,$url,$headers,$params,$hasFile,$apiMode='v1',$maxNetworkRetries=null){
  global $wpdb;
  $raw=maybe_unserialize($wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name='woocommerce_stripe_api_settings'"));
  $key=is_array($raw)?($raw['secret_key_test']??''):'';
  if(!self::validate($method,$url,$headers,$params,$hasFile,$apiMode,context(),$key))throw new \PaymentPlugins\Vendor\Stripe\Exception\InvalidArgumentException('Review QA blocked Stripe request');
  $method=strtolower($method);$body=http_build_query($params??[],'','&',PHP_QUERY_RFC3986);
  if($method==='get' && $body!=='')$url.='?'.$body;
  $c=curl_init($url);$responseHeaders=[];
  curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25,CURLOPT_HTTPHEADER=>$headers,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HEADERFUNCTION=>function($c,$line)use(&$responseHeaders){$p=explode(':',$line,2);if(count($p)===2)$responseHeaders[strtolower(trim($p[0]))]=trim($p[1]);return strlen($line);}]);
  if($method==='post'){curl_setopt($c,CURLOPT_POST,true);curl_setopt($c,CURLOPT_POSTFIELDS,$body);}
  $body=curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);$error=curl_errno($c);curl_close($c);
  if($error || !is_string($body) || ($status>=300 && $status<400))throw new \RuntimeException('Review QA sandbox transport failed');
  return [$body,$status,$responseHeaders];
 }
}
\PaymentPlugins\Vendor\Stripe\ApiRequestor::setHttpClient(new GuardedTransport());
