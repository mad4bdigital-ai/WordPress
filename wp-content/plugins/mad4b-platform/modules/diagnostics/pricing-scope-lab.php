<?php
/** Plugin Name: Pricing Scope (Woo Lab)
 * Hierarchical override UI and resolution contract; does not activate commercial price calculation.
 */
if (!defined('ABSPATH') || (!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || MAD4B_COMMERCE_RUNTIME_ENABLED!==true)) return;
function pricing_scope_fields() { return [
 'default_currency'=>['Currency','text'], 'tax_percent'=>['Tax %','number'],
 'service_fee_percent'=>['Service fee %','number'], 'minimum_booking_lead_hours'=>['Lead time (hours)','number'],
 'quote_ttl_minutes'=>['Quote TTL (minutes)','number'], 'require_availability'=>['Require availability','bool'],
 'allow_manual_overrides'=>['Allow manual overrides','bool'], 'stop_sale_global'=>['Stop sale','bool'],
 'tour_formula'=>['Tour formula (stored; execution disabled)','textarea'] ]; }
function pricing_scope_mode($id) { $m=get_post_meta($id,'_pricing_scope_mode',true); return in_array($m,['inherit','isolated'],true)?$m:'inherit'; }
function pricing_scope_values($id) { $v=get_post_meta($id,'_pricing_scope_values',true);return is_array($v)?$v:[]; }
function pricing_scope_resolve($tour_id,$rate_id=0,$global=[]) {
 $values=$global; $source=array_fill_keys(array_keys($values),'global');
 foreach (['tour'=>(int)$tour_id,'rate'=>(int)$rate_id] as $level=>$id) {
  if (!$id || pricing_scope_mode($id)!=='isolated') continue;
  foreach(pricing_scope_values($id) as $key=>$value) {
   if (!array_key_exists($key,pricing_scope_fields()) || $value===null || $value==='')continue;
   $values[$key]=$value;$source[$key]=$level;
  }
 }
 return ['values'=>$values,'sources'=>$source,'commercial_execution_enabled'=>false];
}
add_action('add_meta_boxes',static function() {
 foreach(['tours-and-activities','tour-rates'] as $type) add_meta_box('pricing-scope','ETG Pricing Scope','pricing_scope_render',$type,'normal','default');
});
function pricing_scope_render($post) {
 wp_nonce_field('pricing_scope_save','pricing_scope_nonce');$mode=pricing_scope_mode($post->ID);$values=pricing_scope_values($post->ID);
 echo '<p><strong>Inherit:</strong> use parent settings. <strong>Isolated:</strong> override only filled fields; empty fields inherit. Saving does not enable checkout or pricing formulas.</p>';
 echo '<label for="pricing_scope_mode">Scope</label> <select id="pricing_scope_mode" name="pricing_scope_mode"><option value="inherit"'.selected($mode,'inherit',false).'>Inherit</option><option value="isolated"'.selected($mode,'isolated',false).'>Isolated / custom</option></select><hr>';
 foreach(pricing_scope_fields() as $key=>$def){ [$label,$type]=$def; $val=$values[$key]??'';
  echo '<p><label for="pricing_scope_'.esc_attr($key).'">'.esc_html($label).' (blank = inherit)</label> ';
  if($type==='bool') echo '<select id="pricing_scope_'.esc_attr($key).'" name="pricing_scope_values['.esc_attr($key).']"><option value="">Inherit</option><option value="1"'.selected((string)$val,'1',false).'>Yes</option><option value="0"'.selected((string)$val,'0',false).'>No</option></select>';
  elseif($type==='textarea') echo '<textarea id="pricing_scope_'.esc_attr($key).'" name="pricing_scope_values['.esc_attr($key).']" rows="2" style="width:90%">'.esc_textarea((string)$val).'</textarea>';
  else echo '<input id="pricing_scope_'.esc_attr($key).'" name="pricing_scope_values['.esc_attr($key).']" type="'.esc_attr($type).'" value="'.esc_attr((string)$val).'" />';
  echo '</p>';
 }
}
add_action('save_post',static function($post_id,$post){
 if(!in_array($post->post_type,['tours-and-activities','tour-rates'],true) || wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE))return;
 if(!isset($_POST['pricing_scope_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['pricing_scope_nonce'])),'pricing_scope_save')||!current_user_can('edit_post',$post_id))return;
 $mode=isset($_POST['pricing_scope_mode'])?sanitize_key(wp_unslash($_POST['pricing_scope_mode'])):'inherit';
 update_post_meta($post_id,'_pricing_scope_mode',$mode==='isolated'?'isolated':'inherit');
 $raw=isset($_POST['pricing_scope_values'])&&is_array($_POST['pricing_scope_values'])?wp_unslash($_POST['pricing_scope_values']):[];
 $clean=[];foreach(pricing_scope_fields() as $key=>$def){if(!isset($raw[$key])||!is_scalar($raw[$key])||$raw[$key]==='')continue;$v=(string)$raw[$key];
  if($def[1]==='number'){if(!is_numeric($v)|| (float)$v<0)continue;$clean[$key]=(float)$v;}
  elseif($def[1]==='bool'){$clean[$key]=$v==='1'?'1':'0';}
  elseif($key==='default_currency'){$v=strtoupper(sanitize_text_field($v));if(!preg_match('/^[A-Z]{3}$/',$v))continue;$clean[$key]=$v;}
  else $clean[$key]=sanitize_textarea_field($v);
 }
 update_post_meta($post_id,'_pricing_scope_values',$clean);
},10,2);
