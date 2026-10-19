<?php
require __DIR__ . '/indexflow-managed-url-smoke.php';
use ETG\DynamicFilterSEOBridge\IndexFlow\ManagedRoutesRuntime;
use ETG\DynamicFilterSEOBridge\JetSmartFilters\FilterUrlParser;
$hooks = array(); $removed = array(); $rewrites = array(); $authority = false;
function add_action($name, $callback, $priority = 10) { $GLOBALS['hooks'][] = array($name,$callback,$priority); if ('template_redirect' === $name && 'redirect_canonical' === $callback) $GLOBALS['redirectActive'] = true; }
function add_filter($name, $callback, $priority = 10) { $GLOBALS['hooks'][] = array($name,$callback,$priority); }
function remove_action($name, $callback, $priority = 10) { $GLOBALS['removed'][] = array($name,$callback,$priority); if ('template_redirect' === $name && 'redirect_canonical' === $callback) $GLOBALS['redirectActive'] = false; }
function has_action($name, $callback) { return !empty($GLOBALS['redirectActive']) ? 10 : false; }
function remove_filter($name, $callback, $priority = 10) { $GLOBALS['removed'][] = array($name,$callback,$priority); }
function add_rewrite_rule($regex, $query, $position) { $GLOBALS['rewrites'][] = array($regex,$query,$position); }
function home_url($path) { return 'https://example.test'.$path; }
function get_option($key, $default=false) { return 'home' === $key ? 'https://example.test' : $default; }
$runtime = new ManagedRoutesRuntime($service, static function ($row) { return 'rank_math' === $row['seo_owner']; }, static function () { return $GLOBALS['authority']; });
$runtime->register(); expect(!$hooks, 'Uncertified runtime adds no routing or SEO hooks');
$authority = true; $runtime->register(); expect((bool)$hooks, 'Independently authorized fixture registers owned hooks');
$runtime->rewrites(); expect(count($rewrites) === 2, 'Exact virtual rules only');
$_SERVER['REQUEST_URI'] = $url;
$wp = (object)array('query_vars'=>array('error'=>'404'));
$runtime->parseRequest($wp);
expect($wp->query_vars['page_id'] === 10 && !isset($wp->query_vars['error']), 'Exact-path fallback uses approved template without a rewrite flush');
expect($wp->query_vars['jsf'] === 'jet-engine:Tours_01/tax/location:cairo;style:luxury/', 'Real vendor permalink payload, preserving query identity and AND dimensions');
$parsed = (new FilterUrlParser(array('location','style'), array(), array(), array($runtime,'technicalUriFor')))->parse($url);
expect($parsed['active'] && $parsed['filters'] === array('location'=>'cairo','style'=>'luxury'), 'Existing parser/context receives the same native filter state');
expect($runtime->canonical($service->technicalUrl($state)) === $url, 'Existing canonical/sitemap/hreflang output mapping');
expect($runtime->contextCanonical('https://example.test/wrong-prefix/', array('request_path'=>(string)parse_url($service->technicalUrl($state), PHP_URL_PATH)), 'filtered') === $url, 'Canonical maps actual context path independently of home-url concatenation');
expect($runtime->contextCanonical('archive', array('request_path'=>(string)parse_url($service->technicalUrl($state), PHP_URL_PATH)), 'archive') === 'archive', 'Archive policy is not silently converted');
expect(false === $runtime->redirectCanonical('https://example.test/en/template-10/'), 'WordPress canonical redirect cannot discard managed state');
expect(false === $runtime->disableRankMath(true), 'Only managed Rank Math route owns integration decision');
$frontend = new stdClass(); $runtime->rememberSeoFrontend($frontend); $runtime->scopeSeoOwnership();
expect(count($removed) === 3 && $removed[2][1] === array($frontend,'modify_canonical_url'), 'Only exact JSF frontend output callbacks removed for managed route');
$browser = $runtime->browserPlan();
expect(count($browser['routes']) === 1 && $browser['archive_url'] === 'https://example.test/en/tours', 'Browser map is locale/profile scoped');
$spoofed = (object)array('query_vars'=>array('indexflow_route'=>'cairo-ar'));
$runtime->parseRequest($spoofed); expect($spoofed->query_vars === array('error'=>'404'), 'Wrong route query var gets 404');
$_SERVER['REQUEST_URI'] = '/unmanaged/';
$before = count($removed); $runtime->scopeSeoOwnership();
expect(count($removed) === $before && $runtime->disableRankMath(true) === true, 'Unmanaged SEO hooks and ownership unchanged');
expect($runtime->redirectCanonical('native') === 'native', 'Unmanaged WordPress redirect unchanged');
$authority = false; $_SERVER['REQUEST_URI']=$url;
expect($runtime->technicalUriFor($url) === null && !$runtime->browserPlan(), 'Revoked runtime authority cannot resolve or enqueue');
$ghost=(object)array('query_vars'=>array('page_id'=>10,'indexflow_route'=>'cairo-en'));
$runtime->parseRequest($ghost); expect($ghost->query_vars === array('error'=>'404'), 'Revoked authority rejects persisted rewrite targets');
$ghost=(object)array('query_vars'=>array('page_id'=>10,'indexflow_route'=>'retired'));
ManagedRoutesRuntime::guardInactiveRequest($ghost); expect($ghost->query_vars === array('error'=>'404'), 'Global OFF/orphaned rules cannot expose unfiltered templates');
$nativeRequest=(object)array('query_vars'=>array('page_id'=>99));
ManagedRoutesRuntime::guardInactiveRequest($nativeRequest); expect($nativeRequest->query_vars === array('page_id'=>99), 'Inactive guard preserves native requests');
expect(null === ManagedRoutesRuntime::fromEnvironment(new class { public function enabled() { return false; } }, null), 'Global OFF avoids runtime access');
echo "Managed runtime: $checks total checks PASS (fixture certification; no live acceptance)\n";
