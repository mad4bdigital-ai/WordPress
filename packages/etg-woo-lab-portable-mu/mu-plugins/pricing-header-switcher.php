<?php
/** Plugin Name: Responsive Currency Header Switcher (Lab) */
if (!defined('ABSPATH')) return;
add_shortcode('pricing_header_switcher', static function () {
 $settings=get_option('_wcml_settings',[]);
 if(($settings['enable_multi_currency']??0)!=2 || !function_exists('pricing_public_fx_snapshot') || !pricing_public_fx_snapshot())return '';
 $codes=array_values(array_intersect(['USD','EUR','EGP','GBP'],array_keys($settings['currency_options']??[])));
 if(count($codes)<2)return '';
 $selected=function_exists('pricing_display_currency')?pricing_display_currency():'USD';
 $id=wp_unique_id('pricing-currency-select-');
 $out='<div class="pricing-currency-switcher clean-wpml"><label class="screen-reader-text" for="'.esc_attr($id).'">'.esc_html__('Currency','woocommerce').'</label><select id="'.esc_attr($id).'" class="pricing-currency-select" aria-label="'.esc_attr__('Currency','woocommerce').'">';
 foreach($codes as $code)$out.='<option value="'.esc_attr($code).'"'.selected($selected,$code,false).'>'.esc_html($code).'</option>';
 return $out.'</select></div>';
});
add_action('wp_enqueue_scripts',static function(){
 wp_register_script('pricing-header-currency-js',false,[],null,true);wp_enqueue_script('pricing-header-currency-js');
 $endpoint=wp_json_encode(admin_url('admin-ajax.php'));
 $js='document.addEventListener("change",async function(e){const select=e.target.closest(".pricing-currency-select");if(!select)return;const currency=select.value;if(!/^(USD|EUR|EGP|GBP)$/.test(currency))return;const previous=select.dataset.current||"USD";select.disabled=true;try{const d=new FormData();d.append("action","wcml_switch_currency");d.append("currency",currency);const response=await fetch('.$endpoint.',{method:"POST",body:d,credentials:"same-origin"});if(!response.ok)throw Error("HTTP "+response.status);const result=await response.json();if(!result || result.error || (result.success===false))throw Error("Currency change rejected");const u=new URL(window.location.href);u.searchParams.set("wcmlc",currency);window.location.assign(u.toString());}catch(err){select.value=previous;select.disabled=false;console.error("Currency switch failed",err);}});document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll(".pricing-currency-select").forEach(s=>s.dataset.current=s.value);});';
 wp_add_inline_script('pricing-header-currency-js',$js);
 wp_register_style('pricing-header-currency',false,[],null);wp_enqueue_style('pricing-header-currency');
 wp_add_inline_style('pricing-header-currency','.pricing-currency-switcher{display:inline-flex;align-items:center;max-width:100%;min-width:72px;direction:ltr}.pricing-currency-select{appearance:auto;-webkit-appearance:menulist;min-height:36px;width:100%;max-width:105px;font:inherit;font-size:13px;line-height:1.4;padding:5px 7px;border:1px solid currentColor;border-radius:7px;background:transparent;color:inherit;cursor:pointer}.pricing-currency-select:focus-visible{outline:2px solid currentColor;outline-offset:2px}.pricing-currency-select option{color:#222;background:#fff}@media(max-width:1024px){.pricing-currency-select{min-height:34px;max-width:90px;font-size:12px;padding:4px 5px}}@media(max-width:767px){.pricing-currency-switcher{min-width:68px}.pricing-currency-select{min-height:32px;max-width:80px;font-size:12px;padding:3px 4px}}');
});
