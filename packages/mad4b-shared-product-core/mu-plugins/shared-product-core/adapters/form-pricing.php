<?php
/** Shared Product Core — generic, read-only form quote presentation, lab only. */
if (!defined('ABSPATH') || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;
function shared_product_form_price_breakdown($rate_id, $audience, $occupancy, $currency = null) {
    foreach (['pricing_engine_enabled','booking_enabled','tax_percent','service_fee_percent'] as $key) {
        $v=shared_product_pricing_setting($key);
        if (is_wp_error($v)) return new WP_Error('pricing_configuration_incomplete', 'Pricing option '.$key.' is not configured or invalid.');
        $settings[$key]=$v;
    }
    if (!$settings['pricing_engine_enabled'] || !$settings['booking_enabled']) return new WP_Error('pricing_disabled');
    $stop=shared_product_pricing_setting('stop_sale_global');
    if (is_wp_error($stop)) return new WP_Error('pricing_configuration_incomplete','Global stop-sale option missing.');
    if ($stop) return new WP_Error('booking_stopped');
    if (!function_exists('shared_product_quote_snapshot')) return new WP_Error('pricing_core_unavailable');
    $quote=shared_product_quote_snapshot($rate_id,$audience,$occupancy,$currency);
    if (is_wp_error($quote)) return $quote;
    $subtotal=(float)$quote['display_amount'];
    $tax=round($subtotal*(float)$settings['tax_percent']/100,2);
    $fee=round($subtotal*(float)$settings['service_fee_percent']/100,2);
    return ['rate_id'=>(int)$rate_id,'currency'=>$quote['display_currency'],'subtotal'=>$subtotal,
      'tax_percent'=>$settings['tax_percent'],'tax_amount'=>$tax,'service_fee_percent'=>$settings['service_fee_percent'],
      'service_fee_amount'=>$fee,'preview_total'=>round($subtotal+$tax+$fee,2),
      'status'=>'preview_only_not_checkout_authority'];
}
/** Public preview: only a published rate ID and server-side validated enum choices. */
add_action('rest_api_init',static function(){
    register_rest_route('shared-product/v1','/form-quote',[
      'methods'=>'GET','permission_callback'=>'__return_true',
      'args'=>['rate_id'=>['required'=>true,'type'=>'integer','minimum'=>1],
        'audience'=>['required'=>true,'type'=>'string','enum'=>['general','egyptians']],
        'occupancy'=>['required'=>true,'type'=>'string','enum'=>['single','double','triple']]],
      'callback'=>static function($req){
        $result=shared_product_form_price_breakdown((int)$req['rate_id'],$req['audience'],$req['occupancy']);
        if(is_wp_error($result))return new WP_Error($result->get_error_code(),__('Booking price is not available.','woocommerce'),['status'=>422]);
        return rest_ensure_response($result);
      }
    ]);
});
/** Interactive preview: bound to a tour-rate selector or a fixed rate; no client prices accepted. */
add_shortcode('shared_product_form_quote', static function($attrs) {
    $attrs=shortcode_atts(['rate_id'=>0,'audience'=>'general','occupancy'=>'double'], $attrs);
    $rate=absint($attrs['rate_id']);
    if ($rate && (get_post_type($rate)!=='tour-rates' || get_post_status($rate)!=='publish')) return '';
    $audience=in_array($attrs['audience'],['general','egyptians'],true)?$attrs['audience']:'general';
    $occupancy=in_array($attrs['occupancy'],['single','double','triple'],true)?$attrs['occupancy']:'double';
    $url=rest_url('shared-product/v1/form-quote');
    wp_enqueue_script('jquery');
    static $printed=false;
    if (!$printed) {
        $printed=true;
        add_action('wp_footer', static function() { ?>
<script id="shared-product-form-quote-interaction">
(function(){
  function getField(form,name){return form?.querySelector('[name="'+name+'"]')?.value || '';}
  function readField(form,names){for(const n of names){const v=getField(form,n);if(v)return v;}return '';}
  function refresh(output){
    const form=output.closest('form');
    const rate=output.dataset.rateId || readField(form,['shared_rate_id','rate_id']);
    const audience=readField(form,['shared_audience','audience'])||output.dataset.audience;
    const occupancy=readField(form,['shared_occupancy','occupancy'])||output.dataset.occupancy;
    if(!rate){output.textContent=output.dataset.unavailable;return;}
    const params=new URLSearchParams({rate_id:rate,audience:audience,occupancy:occupancy});
    const request=(output._quoteRequest||0)+1;output._quoteRequest=request;
    output.textContent=output.dataset.unavailable;
    fetch(output.dataset.endpoint+'?'+params.toString(),{credentials:'same-origin'}).then(r=>r.ok?r.json():Promise.reject(r.status))
      .then(result=>{if(output._quoteRequest===request && result.status==='preview_only_not_checkout_authority'){
        output.textContent=new Intl.NumberFormat(document.documentElement.lang||'en',{minimumFractionDigits:2,maximumFractionDigits:2}).format(result.preview_total)+' '+result.currency;
      }}).catch(()=>{});
  }
  document.querySelectorAll('.shared-product-quote-preview').forEach(output=>{
    const form=output.closest('form');
    form?.addEventListener('change',()=>refresh(output));
    form?.addEventListener('input',e=>{if(['shared_rate_id','rate_id','shared_audience','audience','shared_occupancy','occupancy'].includes(e.target.name))refresh(output);});
    refresh(output);
  });
})();
</script>
        <?php }, 99);
    }
    return '<output class="shared-product-quote-preview" aria-live="polite" data-endpoint="'.esc_url($url).'" data-rate-id="'.esc_attr($rate?:'').'" data-audience="'.esc_attr($audience).'" data-occupancy="'.esc_attr($occupancy).'" data-unavailable="'.esc_attr__('Booking price is not configured.','woocommerce').'">'.esc_html__('Booking price is not configured.','woocommerce').'</output>';
});
