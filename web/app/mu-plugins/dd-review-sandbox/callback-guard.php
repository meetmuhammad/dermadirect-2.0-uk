<?php
/**
 * Pure callback guard for 000-dd-review-sandbox.php. No plugin header on purpose: the Bedrock autoloader only loads
 * files with a Plugin Name header, so this file is loaded once, by require_once from the review sandbox mu-plugin.
 *
 * Blocks provider callbacks: any 'wc-api' URI, any /wp-json/ URI containing 'webhook', and any rest_route query
 * containing 'webhook'. Single exception: GET or POST to the WooCommerce REST webhooks collection route
 * (/wc/v1|v2|v3/webhooks, optional trailing slash) so an approved test channel can list and create its own webhook.
 * Item, batch and deliveries routes, PUT/PATCH/DELETE and method overrides stay blocked.
 */
namespace DermaDirect\ReviewSandbox;
defined('ABSPATH') || exit;
function is_blocked_callback(string $uri,string $method,array $body=[],string $override=''): bool {
 $u=strtolower($uri);
 [$path,$query]=array_pad(explode('?',$uri,2),2,'');
 parse_str($query,$q);
 $rr=$q['rest_route']??null;
 if(str_contains($u,'wc-api'))return true;
 $rrHook=$rr!==null && (!is_string($rr) || str_contains(strtolower($rr),'webhook'));
 if(!$rrHook && !(str_contains($u,'/wp-json/') && str_contains($u,'webhook')))return false;
 // Exception: webhooks collection only, GET or POST, no method override, no rest_route supplied via the body.
 if(!in_array($method,['GET','POST'],true) || $override!=='' || isset($q['_method']) || isset($body['_method']) || isset($body['rest_route']))return true;
 if($rr===null)return !preg_match('~^/wp-json/wc/v[123]/webhooks/?$~D',$path);
 return !(in_array($path,['/','/index.php'],true) && is_string($rr) && preg_match('~^/wc/v[123]/webhooks/?$~D',$rr));
}
