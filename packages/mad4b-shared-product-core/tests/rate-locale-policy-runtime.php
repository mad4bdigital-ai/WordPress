<?php
/** Hermetic shared Rate policy contract, no WordPress boot or writes. */
define('ABSPATH',__DIR__);
$rateMeta=[
 10=>['related_tour_id'=>20,'base_currency'=>'USD'],
 11=>['related_tour_id'=>21,'base_currency'=>'EUR',
  '_shared_generated_locale_policy'=>'independent',
  '_shared_generated_locale_language'=>'fr',
  '_shared_generated_price_authority'=>'explicit_locale_amount_and_currency'],
 12=>['related_tour_id'=>21,'base_currency'=>'EUR',
  '_shared_generated_locale_policy'=>'invalid'],
 13=>['related_tour_id'=>21,'base_currency'=>'EUR',
  '_shared_generated_locale_policy'=>'independent',
  '_shared_generated_locale_language'=>'fr']
];
$rateLanguages=[11=>'fr',13=>'fr'];$tourLanguages=[21=>'fr'];
function get_post_meta($id,$key,$single=true){global $rateMeta;return $rateMeta[$id][$key]??'';}
function get_post_type($id){return in_array((int)$id,[10,11,12,13],true)?'tour-rates':(in_array((int)$id,[20,21],true)?'tours-and-activities':false);}
function apply_filters($hook,$value,$id=null){global $rateLanguages,$tourLanguages;
 if($hook!=='wpml_post_language_details')return $value;
 $code=$rateLanguages[$id]??$tourLanguages[$id]??'';
 return $code!==''?['language_code'=>$code]:null;
}
require __DIR__.'/../mu-plugins/shared-product-core/pricing/rate-locale-policy.php';
$check=[];
$legacy=shared_product_rate_locale_decision(10,20);
$check['legacy_default_allowed']=$legacy['allowed']&&$legacy['mode']==='inherit';
$check['legacy_no_auto_reprice']=$legacy['source_currency']==='USD';
$approved=shared_product_rate_locale_decision(11,21);
$check['explicit_valid']=$approved['allowed']&&$approved['mode']==='independent';
$check['explicit_currency_original']=$approved['source_currency']==='EUR';
$check['unrelated_parent_refused']=!shared_product_rate_locale_decision(11,20)['allowed'];
$check['unknown_mode_refused']=!shared_product_rate_locale_decision(12,21)['allowed'];
$check['missing_provenance_refused']=!shared_product_rate_locale_decision(13,21)['allowed'];
$rateLanguages[11]='de';
$check['mismatched_rate_language_refused']=!shared_product_rate_locale_decision(11,21)['allowed'];
$rateLanguages[11]='fr';$tourLanguages[21]='en';
$check['mismatched_parent_language_refused']=!shared_product_rate_locale_decision(11,21)['allowed'];
$bad=array_keys(array_filter($check,static fn($v)=>!$v));
echo 'RATE_LOCALE_POLICY_RUNTIME '.($bad?'FAIL '.implode(',',$bad):'PASS').'/'.count($check)." checks\n";
exit($bad?1:0);
