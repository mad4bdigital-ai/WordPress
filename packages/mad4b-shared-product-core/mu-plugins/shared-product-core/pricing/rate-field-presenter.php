<?php
/** Render legacy listing field via audience-aware core quote resolution. */
if (!defined('ABSPATH')) return;
function pricing_rate_field_html($id,$field,$role='current'){
 if(!preg_match('/^(egyptians_)?(single|double|triple)_(sale_)?price$/D',$field,$m)||!in_array($role,['current','sale','previous'],true))return '';
 $audience=$m[1]==='egyptians_'?'egyptians':'general';
 $quote=pricing_audience_quote($id,$audience,$m[2]);
 if(($quote['status']??null)!=='available')return '';
 // Preserve legacy Elementor's mutually exclusive regular/sale/strikethrough positions.
 if($role==='current'&&$quote['discounted'])return '';
 if(($role==='sale'||$role==='previous')&&!$quote['discounted'])return '';
 $markup=pricing_audience_display($quote,$role==='previous'?'regular':($role==='current'?'regular':'sale'));
 if($markup==='')return '';
 return $role==='previous'?'<del class="pricing-previous">'.$markup.'</del>':'<span class="pricing-field-amount">'.$markup.'</span>';
}
add_shortcode('pricing_rate_field',static function($args){$a=shortcode_atts(['field'=>'','role'=>'current','rate_id'=>0],$args);return pricing_rate_field_html(absint($a['rate_id'])?:get_the_ID(),sanitize_key($a['field']),sanitize_key($a['role']));});
