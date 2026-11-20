<?php
/**
 * Pure callback guard for 000-dd-review-sandbox.php. No plugin header on purpose: the Bedrock autoloader only loads
 * files with a Plugin Name header, so this file is loaded once, by require_once from the review sandbox mu-plugin.
 *
 * Blocks:
 *  - any 'wc-api' URI (legacy provider callbacks);
 *  - any request, whatever the method or method override, whose WooCommerce REST webhooks route (/wc/vN/webhooks)
 *    targets a protected webhook id (PROTECTED_WEBHOOK_ID_MIN..PROTECTED_WEBHOOK_ID_MAX), including sub-routes such
 *    as /deliveries;
 *  - webhooks batch requests (/webhooks/batch) whose update or delete lists reference a protected id, or whose body
 *    could not be parsed;
 *  - other webhook callbacks, as before: a /wp-json/ URI or a rest_route containing 'webhook' that is not a
 *    WooCommerce REST webhooks route (e.g. payment provider webhooks), and any non-string rest_route.
 * All other WooCommerce REST webhook calls (list, create, read, update, delete) are allowed for any method, through
 * /wp-json/ (repeated slashes collapsed, trailing slash, percent-encoding and case ignored) or ?rest_route= on any path.
 */
namespace DermaDirect\ReviewSandbox;
defined('ABSPATH') || exit;
// Protected webhook ids, copied from production: 19 is the live Shiptheory webhook, 20-26 are Omnisend.
const PROTECTED_WEBHOOK_ID_MIN=19;
const PROTECTED_WEBHOOK_ID_MAX=26;
/** Whether a webhook id value (as WooCommerce would cast it with (int)) is protected. Non-scalars count as protected. */
function is_protected_webhook_id($id): bool {
 if(!is_scalar($id))return true;
 $id=(int)$id;
 return $id>=PROTECTED_WEBHOOK_ID_MIN && $id<=PROTECTED_WEBHOOK_ID_MAX;
}
/** Route in canonical form for matching: percent-decoded, lower case, repeated slashes collapsed. */
function normalize_route(string $route): string {
 for($i=0;$i<3 && str_contains($route,'%');$i++)$route=rawurldecode($route);
 return (string)preg_replace('~/{2,}~','/',strtolower($route));
}
/** Whether a batch body (update and delete lists) references a protected webhook id. Unparseable lists count as yes. */
function batch_references_protected_id(array $params): bool {
 foreach(['update','delete'] as $key){
  if(!array_key_exists($key,$params))continue;
  if(!is_array($params[$key]))return true;
  foreach($params[$key] as $item){
   if($key==='update')$item=is_array($item)?($item['id']??null):$item;
   elseif(is_array($item))$item=$item['id']??null;
   if(is_protected_webhook_id($item))return true;
  }
 }
 return false;
}
/**
 * Whether a normalized route is a WooCommerce REST webhooks route: null if not, else whether it must be blocked.
 * $paramSets: request parameter arrays to check for batch ids; null among them means a body that could not be parsed.
 */
function webhook_route_blocked(string $route,array $paramSets): ?bool {
 if(!preg_match('~(?:^|/)wc/v\d+/webhooks(?:/(.*))?$~',$route,$m))return null;
 $rest=$m[1]??'';
 if(preg_match('~^(\d+)(?:/|$)~',$rest,$id))return is_protected_webhook_id($id[1]);
 if(!preg_match('~^batch(?:/|$)~',$rest))return false;
 foreach($paramSets as $params)if($params===null || batch_references_protected_id($params))return true;
 return false;
}
/**
 * $body: form body ($_POST). $override: X-HTTP-Method-Override header; blocks do not depend on the method, so it only
 * matters in that it never unblocks anything. $json: decoded JSON body ([] if none, null if sent but not parseable).
 */
function is_blocked_callback(string $uri,string $method,array $body=[],string $override='',?array $json=[]): bool {
 $u=strtolower($uri);
 if(str_contains($u,'wc-api'))return true;
 [$path,$query]=array_pad(explode('?',$uri,2),2,'');
 parse_str($query,$q);
 $paramSets=[$q,$body,$json];
 $routes=[];
 if(preg_match('~/wp-json(/.*)?$~s',normalize_route($path),$m))$routes[]=$m[1]??'/';
 foreach([$q,$body] as $src){
  if(!array_key_exists('rest_route',$src))continue;
  if(!is_string($src['rest_route']))return true;
  $routes[]=normalize_route($src['rest_route']);
 }
 $isWebhookApi=false;
 foreach($routes as $r){
  $blocked=webhook_route_blocked($r,$paramSets);
  if($blocked===true)return true;
  if($blocked===false)$isWebhookApi=true;
  elseif(str_contains($r,'webhook'))return true;
 }
 // Other /wp-json/ URIs mentioning 'webhook' (e.g. in the query) stay blocked as before.
 return !$isWebhookApi && str_contains($u,'/wp-json/') && str_contains($u,'webhook');
}
