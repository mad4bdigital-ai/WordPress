<?php
/** Bounded metadata-only Save proof: read-only production/staging acceptance. */
if (!defined('ABSPATH') || !defined('WP_CLI')) exit(1);
wp_set_current_user(1);
set_current_screen('dashboard');
$owner='MAD4B_Native_Pricing_Save_Proof_V1';
if(!class_exists($owner))WP_CLI::error('Native Save proof module not loaded');
$cas='MAD4B_JetEngine_Native_Pricing_CAS_V1';
if(!class_exists($cas))WP_CLI::error('Original CAS guard missing');
if(!method_exists($cas,'save_lock_held')||$cas::save_lock_held())
 WP_CLI::error('Native writer lock receipt safety contract changed');
if(has_action('jet-engine/options-pages/after-save/'.$cas::PAGE,[$owner,'native_saved'])!==998)
 WP_CLI::error('Proof callback is not registered before native CAS unlock');
if(has_action('jet-engine/options-pages/after-save/'.$cas::PAGE,[$cas,'unlock'])!==999)
 WP_CLI::error('CAS unlock order changed');
if(has_action('mad4b/pricing/native-save-denied',[$owner,'native_denied'])===false)
 WP_CLI::error('Stale denial proof callback missing');
if(has_action('admin_enqueue_scripts',[$owner,'admin_assets'])!==71)
 WP_CLI::error('Stale probe must be a restricted admin-only script');
$bad=$owner::entry('invented_success',str_repeat('a',64),str_repeat('b',64));
if($bad!==[]) WP_CLI::error('Unapproved status accepted as a proof');
$badHash=$owner::entry('native_source_changed','too-short',str_repeat('b',64));
if($badHash!==[])WP_CLI::error('Malformed revision accepted');
$a=str_repeat('a',64);$b=str_repeat('b',64);
$changed=$owner::entry('native_source_changed',$a,$b);
$unchanged=$owner::entry('native_source_unchanged',$a,$a);
$stale=$owner::entry('stale_rejected',$a,$b);
$probe=$owner::entry('stale_probe_rejected',$a,$b);
$sameRows=['item-0'=>['mode'=>'tour','enabled'=>'true']];
$changedRows=['item-0'=>['mode'=>'cruise_tour','enabled'=>'true']];
if(!$owner::exact_repeater_readback($sameRows,$sameRows) ||
   $owner::exact_repeater_readback($sameRows,$changedRows) ||
   $owner::exact_repeater_readback($sameRows,null))
 WP_CLI::error('Native Repeater exact readback comparator unsound');
$withProof=$owner::entry('native_source_changed',$a,$b,
 ['checked'=>9,'exact'=>8,'unexpected_field'=>'not-allowed']);
if(($withProof['repeater_checked']??null)!==9 ||
 ($withProof['repeater_exact']??null)!==8 ||
 array_key_exists('unexpected_field',$withProof))
 WP_CLI::error('Native Repeater proof payload unsafe');
if(($changed['source_changed']??null)!==true ||
 ($changed['original_jetengine_save']??null)!==true ||
 ($unchanged['source_changed']??null)!==false ||
 ($stale['original_jetengine_save']??null)!==false ||
 ($probe['original_jetengine_save']??null)!==false ||
 ($probe['source_changed']??null)!==false ||
 strlen($stale['submitted']??'')!==12 ||
 strlen($stale['current']??'')!==12)
 WP_CLI::error('Native Save proof misstates revision or writer state');
$before=get_option($owner::OPTION,[]);
// WP-CLI / synthetic requests must NEVER be recorded as browser evidence.
$owner::native_denied(new WP_Error('native_pricing_cas_stale_source'));
$owner::native_saved(null);
$sourceBefore=$cas::source_revision();
if(is_wp_error($sourceBefore))WP_CLI::error($sourceBefore->get_error_code());
$_GET['page']=$cas::PAGE;
$cas::enqueue();
$owner::admin_assets();
$script=wp_scripts()->registered['mad4b-native-pricing-stale-probe-v1']??null;
if(!$script ||
 !wp_script_is('mad4b-native-pricing-stale-probe-v1','enqueued') ||
 !in_array('mad4b-jetengine-native-pricing-cas-v1',(array)$script->deps,true))
 WP_CLI::error('Browser stale test script is not queued after original CAS');
ob_start();
$owner::admin_notice();
$markup=ob_get_clean();
if(!str_contains($markup,'Native JetEngine Save evidence') ||
 !str_contains($markup,'No pricing values are recorded') ||
 !str_contains($markup,'<details>') ||
 !str_contains($markup,'mad4b-native-stale-probe'))
 WP_CLI::error('Native JetEngine admin proof notice not rendered');
$after=get_option($owner::OPTION,[]);
$sourceAfter=$cas::source_revision();
if($before!==$after ||
 is_wp_error($sourceAfter) ||
 !hash_equals($sourceBefore,$sourceAfter))
 WP_CLI::error('Read-only proof acceptance modified pricing options or audit');
echo 'NATIVE_SAVE_PROOF_READONLY_ACCEPTED '.wp_json_encode([
 'site'=>home_url(),'proof_registered_before_cas_unlock'=>true,
 'strict_event_codes'=>true,'no_values_or_full_revisions_logged'=>true,
 'admin_receipt_view_read_only'=>true,'stale_probe_script_queued'=>true,
 'pricing_data_untouched'=>true,
 'native_save_performed'=>false
],JSON_UNESCAPED_SLASHES).PHP_EOL;
