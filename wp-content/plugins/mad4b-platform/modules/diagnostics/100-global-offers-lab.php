<?php
/** Global Offer campaign management and presentation. Local Lab only. */
if(!defined('ABSPATH')||(!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED))return;
add_action('init',static function(){
 register_post_type('offer-campaign',['label'=>'Offer Campaigns','labels'=>['singular_name'=>'Offer Campaign'],'public'=>false,'show_ui'=>true,'show_in_rest'=>true,'menu_icon'=>'dashicons-megaphone','supports'=>['title','editor']]);
 foreach(['promotion_rule_id','cta_label','cta_url','headline','starts_at','ends_at'] as $key)register_post_meta('offer-campaign',$key,['single'=>true,'type'=>'string','show_in_rest'=>true,'auth_callback'=>static fn()=>current_user_can('edit_posts')]);
 foreach(['eligible_services','eligible_product_ids'] as $key)register_post_meta('offer-campaign',$key,['single'=>true,'type'=>'string','show_in_rest'=>true,'auth_callback'=>static fn()=>current_user_can('edit_posts')]);
});
add_action('add_meta_boxes_offer-campaign',static function(){add_meta_box('global-offer-fields','Offer details',static function($post){wp_nonce_field('global_offer_save','global_offer_nonce');foreach(['promotion_rule_id'=>'Shared Promotion Rule ID','headline'=>'Headline','cta_label'=>'Button Label','cta_url'=>'Button URL','starts_at'=>'Starts at (YYYY-MM-DD)','ends_at'=>'Ends at (YYYY-MM-DD)','eligible_services'=>'Services (comma-separated)','eligible_product_ids'=>'Eligible CPT IDs (comma-separated)'] as $key=>$label){echo '<p><label for="'.esc_attr($key).'">'.esc_html($label).'</label><input class="widefat" id="'.esc_attr($key).'" name="global_offer_'.esc_attr($key).'" value="'.esc_attr(get_post_meta($post->ID,$key,true)).'"></p>';}});});
add_action('save_post_offer-campaign',static function($id){if(!isset($_POST['global_offer_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['global_offer_nonce'])),'global_offer_save')||!current_user_can('edit_post',$id)||wp_is_post_revision($id))return;foreach(['promotion_rule_id','headline','cta_label','cta_url','starts_at','ends_at','eligible_services','eligible_product_ids'] as $key){if(isset($_POST['global_offer_'.$key]))update_post_meta($id,$key,sanitize_text_field(wp_unslash($_POST['global_offer_'.$key])));}});
/** Strict certification envelope for the campaign's explicitly eligible products. */
function global_offer_certified_discount_valid($cert,$eligible_ids){
 if(!is_array($cert)||($cert['verified']??null)!==true||empty($cert['message'])||!is_string($cert['message']))return false;
 if(($cert['authority']??'')!=='shared_product_core'||empty($cert['quotes'])||!is_array($cert['quotes']))return false;
 $allowed=array_fill_keys(array_map('intval',$eligible_ids),true);
 $seen=[];
 foreach($cert['quotes'] as $q){
  if(!is_array($q))return false;
  $id=filter_var($q['product_id']??null,FILTER_VALIDATE_INT);
  if(!$id||!isset($allowed[$id])||isset($seen[$id]))return false;
  $seen[$id]=true;
  if(($q['source']??'')!=='shared_product_core'||empty($q['quote_reference'])||!is_string($q['quote_reference']))return false;
  if(!is_numeric($q['original_amount']??null)||!is_numeric($q['discounted_amount']??null))return false;
  $before=(float)$q['original_amount'];$after=(float)$q['discounted_amount'];
  if(!is_finite($before)||!is_finite($after)||$before<=0||$after<0||$after>=$before)return false;
  if(!is_string($q['currency']??null)||!preg_match('/^[A-Z]{3}$/D',$q['currency']))return false;
 }
 return count($seen)===count($allowed);
}
function global_offer_resolved(){
 $o=get_option('global-offers',[]);if(!is_array($o)||empty($o['offers_enabled']))return null;
 $id=absint($o['active_offer_id']??0);$campaign=$id?get_post($id):null;if(!$campaign||$campaign->post_type!=='offer-campaign'||$campaign->post_status!=='publish')return null;
 if(!apply_filters('shared_offer/woo_campaign_eligible',true,$id))return null;
 $today=wp_date('Y-m-d');$from=get_post_meta($id,'starts_at',true);$until=get_post_meta($id,'ends_at',true);if(($from&&$today<$from)||($until&&$today>$until))return null;
 $rule_id=(string)get_post_meta($id,'promotion_rule_id',true);if(!$rule_id)return null;
 $rules=function_exists('shared_product_structured_policy_rows')?shared_product_structured_policy_rows('shared_promotion_rows'):new WP_Error('core_unavailable');if(is_wp_error($rules)||!is_array($rules))return null;$rule=null;
 foreach($rules as $r){if(is_array($r)&&(string)($r['id']??'')===$rule_id){if($rule!==null)return null;$rule=$r;}}
 if(!$rule||!in_array($rule['enabled']??null,[true,1,'1','true','on'],true))return null;
 // Payment, country and nationality scoped promotions cannot be promised on an unqualified global bar.
 foreach(['payment_methods','country_ids','nationalities'] as $scope){$values=$rule[$scope]??[];if(is_string($values))$values=array_filter(array_map('trim',explode(',',$values)));if(!empty($values))return null;}
 $raw_pct=$rule['percent']??null;if(!is_numeric($raw_pct))return null;$pct=(float)$raw_pct;if(!is_finite($pct)||$pct<=0||$pct>=100)return null;
 foreach(['start','end'] as $key){if(!empty($rule[$key])){$day=substr((string)$rule[$key],0,10);if(($key==='start'&&$today<$day)||($key==='end'&&$today>$day))return null;}}
 $services=array_filter(array_map('trim',explode(',',(string)get_post_meta($id,'eligible_services',true))));
 $ids=array_values(array_unique(array_filter(array_map('absint',explode(',',(string)get_post_meta($id,'eligible_product_ids',true))))));
 if(!$ids||count($ids)>100)return null; // Never promise an offer without explicit linked products and price verification.
 $valid=[];foreach($ids as $product_id){if(function_exists('global_offer_product_eligible')&&global_offer_product_eligible($product_id)){$p=get_post($product_id);$map=['tours-and-activities'=>'tours','properties'=>'properties','transportations'=>'transportations','travel-visa'=>'visas'];if(!$services||in_array($map[$p->post_type]??'',$services,true))$valid[]=$product_id;}}
 if(!$valid||count($valid)!==count($ids))return null;
 // Shared Product Core must certify sale prices; rule percentage alone never authorizes an amount or percentage claim.
 $cert=apply_filters('global_offer/verified_discount',null,$campaign,$rule,$valid);
 // A message or a percentage by itself is not price evidence.
 if(!global_offer_certified_discount_valid($cert,$valid))return null;
 // An arbitrary filter result is not certification: only the Core itself may verify issued quotes.
 if(!function_exists('shared_product_verify_global_offer_quote')||shared_product_verify_global_offer_quote($cert,$campaign,$rule,$valid)!==true)return null;
 return ['campaign'=>$campaign,'message'=>sanitize_text_field($cert['message']),'url'=>get_post_meta($id,'cta_url',true),'button'=>get_post_meta($id,'cta_label',true)?:($o['cta_label']??''),'headline'=>get_post_meta($id,'headline',true)?:($o['headline']??'')];
}
add_shortcode('global_offer_top_bar',static function(){
 if(is_admin()&&!wp_doing_ajax())return '';
 $r=global_offer_resolved();if(!$r)return '';
 $o=get_option('global-offers',[]);
 if(is_front_page()&&empty($o['display_home']))return '';
 if(is_archive()&&empty($o['display_archive']))return '';
 if(is_singular()&&!is_front_page()&&empty($o['display_single']))return '';
 $campaign_id=(int)$r['campaign']->ID;$url=wp_validate_redirect($r['url'],home_url('/'));
 $html='<div class="global-offer-topbar" data-offer-campaign="'.esc_attr((string)$campaign_id).'" role="region" aria-label="Current offer"><div class="global-offer-topbar__inner">';
 $html.='<span class="global-offer-topbar__message">'.esc_html($r['headline']?:$r['campaign']->post_title).' — '.esc_html($r['message']).'</span>';
 $html.='<a class="global-offer-topbar__cta" href="'.esc_url($url).'">'.esc_html($r['button']?:'View offers').'</a>';
 if(!empty($o['dismissible']))$html.='<button type="button" class="global-offer-topbar__dismiss" aria-label="Dismiss offer">&times;</button>';
 return $html.'</div></div>';
});
add_action('wp_head',static function(){if(!global_offer_resolved())return;
 $options=get_option('global-offers',[]);$vars=[];
 foreach(['background_color'=>'--offer-bg','text_color'=>'--offer-text','accent_color'=>'--offer-accent','button_text_color'=>'--offer-button-text'] as $name=>$css){$v=$options[$name]??null;if(is_string($v)&&preg_match('/^#[a-fA-F0-9]{6}$/D',$v))$vars[]=$css.':'.$v;}
 $pad=$options['bar_padding']??null;if(is_numeric($pad)&&$pad>=0&&$pad<=80)$vars[]='--offer-padding:'.(int)$pad.'px';
 if($vars)echo '<style id="global-offer-options-vars">.global-offer-topbar{'.esc_html(implode(';',$vars)).'}</style>';
 echo '<style>.global-offer-topbar{width:100%;background:var(--offer-bg,#a5262c);color:var(--offer-text,#fff);padding:var(--offer-padding,8px) 16px}.global-offer-topbar__inner{width:100%;display:flex;align-items:center;justify-content:center;gap:24px;flex-wrap:wrap;text-align:center}.global-offer-topbar__cta{background:var(--offer-accent,#e5b344);color:var(--offer-button-text,#241f17);padding:6px 16px;border-radius:24px;text-decoration:none;font-weight:600}@media(max-width:600px){.global-offer-topbar__inner{gap:8px}.global-offer-topbar__message{width:100%;font-size:13px}}</style>';});
// Hide the Elementor wrapper as well as the shortcode when no certified offer exists.
add_filter('elementor/frontend/builder_content_data',static function($elements,$document_id){
 if(!is_array($elements)||is_admin()||(class_exists('Elementor\\Plugin')&&\Elementor\Plugin::$instance->preview->is_preview_mode()))return $elements;
 if(global_offer_resolved()||(function_exists('shared_offer_seasonal_visible')&&shared_offer_seasonal_visible()))return $elements;
 $walk=static function($nodes)use(&$walk){$result=[];foreach($nodes as $node){if(!is_array($node))continue;if(!empty($node['settings']['global_offer_bar_section']))continue;if(isset($node['elements']))$node['elements']=$walk($node['elements']);$result[]=$node;}return $result;};
 return $walk($elements);
},1200,2);
// Changes to settings, campaigns or scheduled offer windows must not serve stale banners.
$global_offer_purge=static function(){do_action('mad4b/public_html/safe_purge_all', '100_global_offers_lab');}; // Public HTML only; no Redis FLUSHDB.
add_action('update_option_global-offers',$global_offer_purge,20);
add_action('save_post_offer-campaign',$global_offer_purge,30);
add_action('before_delete_post',static function($id)use($global_offer_purge){if(get_post_type($id)==='offer-campaign')$global_offer_purge();});
add_action('wp',static function(){
 $settings=get_option('global-offers',[]);
 if(is_array($settings)&&!empty($settings['offers_enabled'])){
  do_action('litespeed_control_set_nocache','Dynamic Global Offer requires current schedule and certified price');
 }
},20);
// Device visibility comes from the Offers Page, while Elementor remains responsible for styling.
add_action('wp_head',static function(){
 $o=get_option('global-offers',[]);if(!is_array($o)||empty($o['offers_enabled']))return;
 $css='';
 if(empty($o['desktop_enabled']))$css.='@media(min-width:1025px){.global-offer-topbar{display:none!important}}';
 if(empty($o['tablet_enabled']))$css.='@media(min-width:768px) and (max-width:1024px){.global-offer-topbar{display:none!important}}';
 if(empty($o['mobile_enabled']))$css.='@media(max-width:767px){.global-offer-topbar{display:none!important}}';
 if($css)echo '<style id="global-offer-device-visibility">'.$css.'</style>';
},30);

/** Admin-only readiness report. Never enables a campaign or changes prices. */
function global_offer_readiness_report(){
 $o=get_option('global-offers',[]);$out=['environment'=>wp_get_environment_type(),'enabled'=>!empty($o['offers_enabled']),'campaign_id'=>absint($o['active_offer_id']??0),'template_id'=>(int)get_option('global_offer_elementor_template_id',0),'blockers'=>[]];
 if(!$out['enabled'])$out['blockers'][]='offers_disabled';
 $campaign=$out['campaign_id']?get_post($out['campaign_id']):null;
 if(!$campaign||$campaign->post_type!=='offer-campaign'||$campaign->post_status!=='publish')$out['blockers'][]='active_campaign_missing_or_unpublished';
 $rows=function_exists('shared_product_structured_policy_rows')?shared_product_structured_policy_rows('shared_promotion_rows'):new WP_Error('core_unavailable');
 if(is_wp_error($rows))$out['blockers'][]='shared_rules_'.$rows->get_error_code();elseif(!is_array($rows)||!$rows)$out['blockers'][]='no_shared_promotion_rules';
 if($campaign){
  $ids=array_values(array_unique(array_filter(array_map('absint',explode(',',(string)get_post_meta($campaign->ID,'eligible_product_ids',true))))));
  if(!$ids)$out['blockers'][]='campaign_products_missing';
  elseif(!array_filter($ids,'global_offer_product_eligible'))$out['blockers'][]='all_campaign_products_ineligible';
 }
 if(!function_exists('shared_product_verify_global_offer_quote'))$out['blockers'][]='shared_core_offer_quote_verifier_missing';
 $cptwi=get_option('cptwooint_settings',[]);
 $registered=array_keys((array)($cptwi['selected_post_types']??[]));
 foreach(['tours-and-activities','tour-rates'] as $required)if(!in_array($required,$registered,true))$out['blockers'][]='cptwi_missing_'.$required;
 foreach(['properties','transportations','travel-visa'] as $unmapped)if(!in_array($unmapped,$registered,true))$out['blockers'][]='product_adapter_pending_'.$unmapped;
 $out['cptwoo_supported_types']=$registered;
 $out['woo_native_coupon_adapter']=function_exists('shared_product_global_offer_coupon_context');
 $out['jetwoobuilder_active']=is_plugin_active('jet-woo-builder/jet-woo-builder.php');
 $out['blockers'][]='live_verified_discount_provider_not_certified';
 return $out;
}
add_action('admin_menu',static function(){add_submenu_page('edit.php?post_type=offer-campaign','Global Offer Readiness','Readiness','manage_options','global-offer-readiness',static function(){
 $report=global_offer_readiness_report();echo '<div class="wrap"><h1>Global Offer Readiness</h1><p>Read-only Lab diagnostics. No discount or campaign is activated here.</p><table class="widefat striped"><tbody>';
 foreach($report as $key=>$value)echo '<tr><th>'.esc_html($key).'</th><td>'.esc_html(is_array($value)?implode(', ',$value):var_export($value,true)).'</td></tr>';
 echo '</tbody></table></div>';
 });});

// Session-only dismissal scoped by campaign ID. Reapplies after Ajax/Elementor fragment replacement.
add_action('wp_footer',static function(){
 $o=get_option('global-offers',[]);if(!is_array($o)||empty($o['offers_enabled'])||empty($o['dismissible'])||!global_offer_resolved())return;
 $js=<<<'JS'
(function(){'use strict';
 function sync(){document.querySelectorAll('.global-offer-topbar[data-offer-campaign]').forEach(function(el){var id=el.getAttribute('data-offer-campaign');try{if(sessionStorage.getItem('global-offer-dismiss:'+id)==='1')el.hidden=true;}catch(e){}});}
 document.addEventListener('click',function(e){var button=e.target.closest('.global-offer-topbar__dismiss');if(!button)return;var el=button.closest('.global-offer-topbar[data-offer-campaign]');if(!el)return;el.hidden=true;try{sessionStorage.setItem('global-offer-dismiss:'+el.getAttribute('data-offer-campaign'),'1');}catch(e){}});
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',sync);else sync();
 if(window.MutationObserver){new MutationObserver(sync).observe(document.documentElement,{childList:true,subtree:true});}
})();
JS;
 echo '<script id="global-offer-dismiss">'.$js.'</script>';
},40);
