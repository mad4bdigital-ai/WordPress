<?php
/** Extra selection and server-authoritative unit quote; isolated Lab preview only. */
if (!defined('ABSPATH') || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;
add_shortcode('extra_select_actions',static function(){
 global $wp_the_query;
 $extra=get_the_ID();$parent=$wp_the_query instanceof WP_Query && $wp_the_query->is_singular('tours-and-activities')?(int)$wp_the_query->get_queried_object_id():0;
 if(!$parent||get_post_type($extra)!=='tours-and-activities'||(string)get_post_meta($parent,'tour_extras_status',true)!=='1')return '';
 $raw=get_post_meta($parent,'tour_optional_extras',true);
 if(is_string($raw))$raw=maybe_unserialize($raw);
 if(is_string($raw))$raw=array_filter(array_map('trim',explode(',',$raw)));
 if(!is_array($raw)||!in_array((string)$extra,array_map('strval',$raw),true)||!in_array(get_post_meta($extra,'tour_mode',true),['activity','extra_trip'],true))return '';
 return '<div class="shared-extra-actions" data-extra-actions="'.esc_attr($extra).'">'
 .'<button type="button" data-extra-select="'.esc_attr($extra).'">Select extra</button>'
 .'<button type="button" data-extra-remove="'.esc_attr($extra).'" aria-label="Remove selected extra" hidden>Remove</button>'
 .'<output aria-live="polite"></output></div>';
});
add_action('rest_api_init',static function(){register_rest_route('shared-product/v1','extra-unit-preview',['methods'=>'POST','permission_callback'=>'__return_true','callback'=>static function(WP_REST_Request $req){
 $parent=absint($req['parent_rate_id']);$child=absint($req['extra_rate_id']);
 $tour=(int)get_post_meta($child,'related_tour_id',true);
 return shared_product_extra_policy_preview_response($parent,$tour,$child,sanitize_key((string)($req['audience']??'general')),sanitize_key((string)($req['occupancy']??'double')),$req['quantities'],$req['travel_date']);
 }]);});
add_action('wp_footer',static function(){if(!is_singular('tours-and-activities'))return; ?>
<script id="shared-extra-selection-handler">(function(){if(window.sharedExtraSelectionReady)return;window.sharedExtraSelectionReady=true;const selected=new Map();document.addEventListener('click',function(e){const button=e.target.closest('[data-extra-select],[data-extra-remove]');if(!button)return;const group=button.closest('[data-extra-actions]');if(!group)return;const id=group.dataset.extraActions;const remove=button.hasAttribute('data-extra-remove');if(remove)selected.delete(id);else selected.set(id,{tourId:Number(id)});group.dataset.selected=String(!remove);group.querySelector('[data-extra-select]').hidden=!remove;group.querySelector('[data-extra-remove]').hidden=remove;document.dispatchEvent(new CustomEvent('shared-extra-selection-change',{detail:{extraTourId:Number(id),selected:!remove,items:Array.from(selected.values())}}));});document.addEventListener('shared-extra-selection-change',e=>{
 if(!['booking-preview','booking-preview-restore','native-component-selection'].includes(e.detail?.source))return;
 const id=String(e.detail.extraTourId||'');
 if(!id)return;
 if(e.detail.selected)selected.set(id,{tourId:Number(id)});else selected.delete(id);
 document.querySelectorAll('[data-extra-actions]').forEach(group=>{
  if(group.dataset.extraActions!==id)return;
  const active=selected.has(id);
  group.dataset.selected=String(active);
  group.querySelector('[data-extra-select]').hidden=active;
  group.querySelector('[data-extra-remove]').hidden=!active;
 });
});document.addEventListener('jet-filter-content-rendered',()=>{document.querySelectorAll('[data-extra-actions]').forEach(group=>{const active=selected.has(group.dataset.extraActions);group.querySelector('[data-extra-select]').hidden=active;group.querySelector('[data-extra-remove]').hidden=!active;});});})();</script>
<?php },45);
/** Authoritative simple-extra quote: direct CPT price with explicit price unit. */
function shared_product_direct_extra_quote($parent_rate_id,$extra_tour_id,$audience,$quantities){
 $allowed=shared_product_allowed_optional_tour_ids($parent_rate_id);if(is_wp_error($allowed))return $allowed;
 if(!in_array((int)$extra_tour_id,$allowed,true))return new WP_Error('optional_tour_not_linked');
 $unit=(string)get_post_meta($extra_tour_id,'tour_price_unit',true);
 if(!in_array($unit,['per_person','per_trip','per_day','per_room','per_vehicle','per_cabin'],true))return new WP_Error('tour_price_unit_missing');
 if($audience==='egyptians'){$price=get_post_meta($extra_tour_id,'egyptians_start_price',true);$currency=get_post_meta($extra_tour_id,'tour_egyptians_currency',true);}
 elseif($audience==='general'){$price=get_post_meta($extra_tour_id,'start_price',true);$currency=get_post_meta($extra_tour_id,'tour_base_currency',true);}
 else return new WP_Error('invalid_audience');
 if(!is_scalar($price)||!is_numeric($price)||(float)$price<0||!is_string($currency)||!preg_match('/^[A-Z]{3}$/D',$currency))return new WP_Error('extra_direct_price_unavailable');
 $result=shared_product_unit_extended_amount($price,$unit,$quantities);if(is_wp_error($result))return $result;
 return ['extra_tour_id'=>(int)$extra_tour_id,'unit'=>$unit,'quantity'=>$result['quantity'],'currency'=>$currency,'total'=>$result['amount'],'source'=>'tour_post_meta','status'=>'preview_only_not_checkout_authority'];
}
add_action('rest_api_init',static function(){register_rest_route('shared-product/v1','extra-direct-preview',['methods'=>'POST','permission_callback'=>'__return_true','callback'=>static function(WP_REST_Request $request){$quantities=$request['quantities'];if(!is_array($quantities))return new WP_Error('missing_unit_quantities');return shared_product_extra_policy_preview_response(absint($request['parent_rate_id']),absint($request['extra_tour_id']),null,sanitize_key((string)($request['audience']??'general')),sanitize_key((string)($request['occupancy']??'double')),$quantities,$request['travel_date']);}]);});
/** Policy-aware optional tour price preview, no client-supplied monetary amounts. */
add_action('rest_api_init',static function(){register_rest_route('shared-product/v1','extra-policy-preview',['methods'=>'POST','permission_callback'=>'__return_true','callback'=>static function(WP_REST_Request $request){
 return shared_product_extra_policy_preview_response(absint($request['parent_rate_id']),absint($request['extra_tour_id']),$request['extra_rate_id']?absint($request['extra_rate_id']):null,sanitize_key((string)($request['audience']??'general')),sanitize_key((string)($request['occupancy']??'double')),$request['quantities'],$request['travel_date']);
 }]);});

/** All legacy Extra preview routes use the same source decision as Booking v2. */
function shared_product_extra_policy_preview_response($parent_rate,$tour,$rate,$audience,$occupancy,$quantities,$date=null){
 if(!is_array($quantities))return new WP_Error('missing_unit_quantities','Unit quantities required',['status'=>400]);
 $parent=shared_product_tour_context($parent_rate);if(is_wp_error($parent))return $parent;
 $line=shared_product_extra_source_quote((int)$parent['tour_id'],['tour_id'=>$tour,'rate_id'=>$rate],$audience,$occupancy,$quantities,$date);
 if(is_wp_error($line))return $line;
 return array_merge($line,['extra_tour_id'=>$line['tour_id'],'extra_rate_id'=>$line['rate_id'],'total'=>$line['amount'],'policy'=>shared_product_price_source_policy((string)get_post_meta($tour,'tour_mode',true))]);
}
