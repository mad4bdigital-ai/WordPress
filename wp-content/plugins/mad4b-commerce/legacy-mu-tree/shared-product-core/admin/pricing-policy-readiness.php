<?php
/** runtime-authorized diagnostic of current JetEngine pricing policies. No writes. */
if (!defined('ABSPATH') || ( ( !defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED )
    && ( !defined('MAD4B_PRESENTATION_RUNTIME_ENABLED') || !MAD4B_PRESENTATION_RUNTIME_ENABLED ) )) return;
function shared_product_mode_readiness_inventory(){
 if (!function_exists('shared_product_mode_policies') || !function_exists('shared_product_financial_field_choices')) {
  return new WP_Error('pricing_readiness_provider_unavailable');
 }
 $policies=shared_product_mode_policies();if(is_wp_error($policies))return $policies;
 $result=[];
 foreach(['tour','activity','cruise_tour','extra_trip'] as $mode){
  $row=$policies[$mode]??null;
  $missing=[];
  if(!is_array($row))$missing[]='missing_mode_pricing_policy';
  elseif(empty($row['enabled']))$missing[]='mode_pricing_disabled';
  foreach(array_keys(shared_product_financial_field_choices()) as $field){
   if(!is_array($row)||!isset($row[$field])||$row[$field]===''||$row[$field]==='inherit')$missing[]='missing_financial_'.$field;
  }
  $configuration_complete=empty($missing);
  if(in_array($mode,(array)get_option('shared_lab_inferred_mode_policy_pending_approval_20261024',[]),true))$missing[]='inferred_mode_policy_pending_approval';
  $result[$mode]=['strategy'=>is_array($row)?($row['strategy']??null):null,'configuration_complete'=>$configuration_complete,'review_pending'=>in_array('inferred_mode_policy_pending_approval',$missing,true),'ready'=>!$missing,'blockers'=>$missing];
 }
 return $result;
}
if (!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED) return;
add_action('admin_notices',static function(){
 if(!current_user_can('manage_options')||!is_admin())return;
 $screen=function_exists('get_current_screen')?get_current_screen():null;
 if(!$screen||!str_contains((string)$screen->id,'jet-engine'))return;
 $inventory=shared_product_mode_readiness_inventory();if(is_wp_error($inventory))return;
 $pending=array_filter($inventory,static fn($x)=>!$x['ready']);if(!$pending)return;
 echo '<div class="notice notice-warning"><p><strong>Pricing policy readiness:</strong> '.esc_html(implode(', ',array_keys($pending))).' have incomplete configuration or a pending policy review. No defaults were applied.</p></div>';
});
