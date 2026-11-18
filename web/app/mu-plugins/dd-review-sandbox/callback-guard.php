<?php
/**
 * Pure callback guard for 000-dd-review-sandbox.php. No plugin header on purpose: the Bedrock autoloader only loads
 * files with a Plugin Name header, so this file is loaded once, by require_once from the review sandbox mu-plugin.
 *
 * Blocks provider callbacks: any 'wc-api' URI, any /wp-json/ URI containing 'webhook', and any rest_route query
 * containing 'webhook'. Exceptions, on the WooCommerce REST webhooks routes (/wc/v1|v2|v3, optional trailing slash),
 * same rules for /wp-json/ and ?rest_route=:
 *  - GET or POST to the collection (/webhooks) so an approved test channel can list and create its own webhook;
 *  - GET, PUT or PATCH to an item (/webhooks/<id>) only when <id> >= the minimum editable id, so the test channel can
 *    update its own webhook. Webhooks below that id existed before the test and stay protected (19 is the live
 *    Shiptheory webhook, 20-26 are Omnisend). Minimum: DD_WEBHOOK_MIN_EDITABLE_ID if a positive integer, else 27.
 * DELETE, POST to an item, batch and deliveries routes and method overrides stay blocked.
 */
namespace DermaDirect\ReviewSandbox;
defined('ABSPATH') || exit;
const DEFAULT_MIN_EDITABLE_WEBHOOK_ID=27;
/** Minimum editable webhook id from a configured value (the constant), falling back to 27 unless a positive integer. */
function resolve_min_editable_webhook_id($configured): int {
 if(is_int($configured))return $configured>0?$configured:DEFAULT_MIN_EDITABLE_WEBHOOK_ID;
 if(is_string($configured) && preg_match('~^[1-9]\d{0,9}$~D',$configured))return (int)$configured;
 return DEFAULT_MIN_EDITABLE_WEBHOOK_ID;
}
function min_editable_webhook_id(): int {
 return resolve_min_editable_webhook_id(defined('DD_WEBHOOK_MIN_EDITABLE_ID')?constant('DD_WEBHOOK_MIN_EDITABLE_ID'):null);
}
/** Whether $method on WC REST route $route (no /wp-json prefix) is one of the allowed webhook exceptions. */
function is_allowed_webhook_route(string $route,string $method,int $minId): bool {
 if(preg_match('~^/wc/v[123]/webhooks/?$~D',$route))return in_array($method,['GET','POST'],true);
 if(!preg_match('~^/wc/v[123]/webhooks/([1-9]\d{0,9})/?$~D',$route,$m))return false;
 return in_array($method,['GET','PUT','PATCH'],true) && (int)$m[1]>=$minId;
}
function is_blocked_callback(string $uri,string $method,array $body=[],string $override='',?int $minEditableId=null): bool {
 $u=strtolower($uri);
 [$path,$query]=array_pad(explode('?',$uri,2),2,'');
 parse_str($query,$q);
 $rr=$q['rest_route']??null;
 if(str_contains($u,'wc-api'))return true;
 $rrHook=$rr!==null && (!is_string($rr) || str_contains(strtolower($rr),'webhook'));
 if(!$rrHook && !(str_contains($u,'/wp-json/') && str_contains($u,'webhook')))return false;
 // Exceptions only without a method override and without rest_route supplied via the body.
 if($override!=='' || isset($q['_method']) || isset($body['_method']) || isset($body['rest_route']))return true;
 $minId=$minEditableId ?? min_editable_webhook_id();
 if($rr===null)return !(str_starts_with($path,'/wp-json/') && is_allowed_webhook_route(substr($path,8),$method,$minId));
 return !(in_array($path,['/','/index.php'],true) && is_string($rr) && is_allowed_webhook_route($rr,$method,$minId));
}
