<?php
require __DIR__ . '/indexflow-managed-url-smoke.php';
use ETG\DynamicFilterSEOBridge\IndexFlow\RouteDocumentStore;
$options = array(); $writes = 0; $failWrite = false; $failDelete = false;
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['writes']++; if ($GLOBALS['failWrite']) return false; $GLOBALS['options'][$key] = $value; return true; }
function delete_option($key) { if ($GLOBALS['failDelete']) return false; unset($GLOBALS['options'][$key]); return true; }
function wp_cache_delete($key, $group) {}
function fails(callable $f, string $message) { try { $f(); } catch (RuntimeException $e) { expect(true, $message); return; } throw new RuntimeException('Expected persistence failure: ' . $message); }
$store = new RouteDocumentStore($make, static function () { return $GLOBALS['options'][RouteDocumentStore::OPTION] ?? null; });
$empty = $store->snapshot();
$plan = $store->plan($doc);
expect($writes === 0 && !$empty['exists'], 'Planning does not store even an empty option');
$applied = $store->apply($doc, $plan);
expect($applied['verified'] && $writes === 1 && $store->snapshot()['document'] === $doc, 'Apply with exact readback');
$noop = $store->plan($doc); $store->apply($doc, $noop);
expect($writes === 1, 'Identical reviewed apply is a structural no-op');
denied(static function () use ($store, $doc, $plan) { $store->apply($doc, $plan); }, 'Stale before-state revision denied');
$next = $doc; $next['profiles']['tours']['bases']['en'] = '/en/new-tours/';
$nextPlan = $store->plan($next);
expect($nextPlan['migration']['migration_required'], 'Changed base requires reviewed migration fingerprint');
$forged = $nextPlan; $forged['expected_migration_sha256'] = str_repeat('0',64);
denied(static function () use ($store, $next, $forged) { $store->apply($next,$forged); }, 'Unreviewed migration denied');
$GLOBALS['terms']['location'][1][2] = 'new-cairo';
denied(static function () use ($store,$next,$nextPlan) { $store->apply($next,$nextPlan); }, 'Real translated slug drift invalidates plan');
$GLOBALS['terms']['location'][1][2] = 'cairo';
$failWrite = true;
fails(static function () use ($store,$next,$nextPlan) { $store->apply($next,$nextPlan); }, 'False storage return cannot fake success');
$failWrite = false;
$before = $store->snapshot(); $result = $store->apply($next,$nextPlan);
$after = $store->snapshot();
$store->restore($before,RouteDocumentStore::digest($after));
expect($store->snapshot() === $before, 'Undo restores exact captured document');
denied(static function () use ($store,$before,$after) { $store->restore($before,RouteDocumentStore::digest($after)); }, 'Undo state drift denied');
$store->restore($empty,RouteDocumentStore::digest($before));
expect($store->snapshot() === $empty && !array_key_exists(RouteDocumentStore::OPTION,$options), 'Undo of first write restores absence');
$store->apply($doc,$store->plan($doc)); $failDelete = true;
fails(static function () use ($store,$empty) { $store->restore($empty,RouteDocumentStore::digest($store->snapshot())); }, 'Failed delete cannot fake undo');
$failDelete = false;
$oversize=$doc;$oversize['extra']=str_repeat('x',32769);
denied(static function () use ($store,$oversize) { $store->plan($oversize); }, 'Bounded document prior to journal');
echo "Route storage: $checks total checks PASS (fixtures; no publication or provider certification)\n";
