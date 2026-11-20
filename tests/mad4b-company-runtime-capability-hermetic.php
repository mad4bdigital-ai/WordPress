<?php
/** Capability snapshot proves per-site policies do not become write grants. */
declare(strict_types=1);
$mode = $argv[1] ?? 'presentation';
$policy = $argv[2] ?? 'inquiry_only';
if (!in_array($mode, ['commerce','presentation','disabled','conflict'], true)) exit(3);
define('ABSPATH', '/');
if ($mode === 'commerce' || $mode === 'conflict') define('MAD4B_COMMERCE_RUNTIME_ENABLED', true);
if ($mode === 'presentation' || $mode === 'conflict') define('MAD4B_PRESENTATION_RUNTIME_ENABLED', true);
function get_option($name, $default = false) { return $GLOBALS['test_policy'] ?? $default; }
function tour_component_structured_price_value() { return 'From 120 USD'; }
function tour_component_price_evidence() { return ['amount'=>120]; }
function shared_lab_tour_price_resolve() { return ['amount'=>120]; }
$GLOBALS['test_policy'] = $policy;
require dirname(__DIR__).'/wp-content/plugins/mad4b-commerce/modules/presentation/company-runtime-capability-contract-v1.php';
$s=MAD4B_Company_Runtime_Capability_Contract_V1::snapshot();
$active=in_array($mode, ['commerce','presentation'], true);
$expectedMode=$mode==='conflict'?'conflict_hold':$mode;
$effective=in_array($policy,['inquiry_only','request_to_book','instant_booking'],true)?$policy:'inquiry_only';
$pass=$s['runtime_mode']===$expectedMode && $s['booking_policy']===$effective
 && $s['readiness']['native_price_display']===$active
 && $s['readiness']['public_indicative_quote']===$active
 && $s['intents']['request_candidate']===($active && $effective!=='inquiry_only')
 && $s['intents']['instant_candidate']===($active && $effective==='instant_booking')
 && $s['intents']['request_authorized']===false
 && $s['intents']['instant_authorized']===false
 && $s['execution_authority']['granted_by_this_contract']===false
 && $s['price_data_written']===false && $s['booking_created']===false;
echo json_encode(['mode'=>$mode,'policy'=>$policy,'passed'=>$pass,'snapshot'=>$s],JSON_UNESCAPED_SLASHES),"\n";
exit($pass?0:1);
