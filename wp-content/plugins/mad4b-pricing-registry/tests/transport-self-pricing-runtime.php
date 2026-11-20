<?php
/** Read-only + deliberate idempotent save validation: wp eval-file ... */
defined('ABSPATH') || exit(2);
$site=rtrim(home_url('/'),'/');
if(!in_array($site,['https://tours.dev.mad4b.com','https://wayfatours.com'],true)){
 echo "STATUS=SKIP\n";return;
}
wp_set_current_user(1);
$c='MAD4B_Transport_Self_Pricing_Controls_V1';
$tests=[];
$t=static function($name,$pass)use(&$tests){$tests[$name]=(bool)$pass;};
$source=$c::source();
$policy=$c::current();
$t('pdf_51_source_rows',count($source['rows']??[])===51);
$t('pdf_sha_locked',$policy['source_sha256']===$c::SHA);
$t('pdf_is_trusted',$policy['source_type']==='trusted_original_pdf_2026');
$t('provider_is_company_self',$policy['provider']==='self');
$t('currency_not_guessed',$policy['currency']==='');
$t('reference_role_not_guessed',$policy['price_role']==='reference_only');
$t('source_date_2026_04_01',$policy['effective_from']==='2026-04-01');
$t('pdf_total_210_price_cells',array_sum(array_map(static fn($r)=>
  count(array_filter($r['vehicle_rates_unverified'],static fn($v)=>$v!==null)),
  $source['rows']))===210);
$t('document_5pct_active',$policy['apply_upper_egypt_five_pct']===true);
$full=$c::catalog('','en');
$t('catalog_all_51',count($full['items']??[])===51);
$t('catalog_currency_not_auto',$full['policy']['currency']==='');
$t('catalog_no_public_price',$full['public_price_written']===false);
$t('catalog_read_only_checkout',$full['checkout_authorized']===false);
$cairo=$c::preview(['key'=>'Cairo:1','vehicle'=>'limousine','quantity'=>1]);
$t('cairo_original_amount_1300',$cairo['source_amount']===1300);
$t('cairo_no_discount',$cairo['source_discount_pct']===0.0);
$t('cairo_self_preview_1300',$cairo['unit_reference_amount']===1300.0);
$aswan=$c::preview(['key'=>'Aswan:1','vehicle'=>'limousine','quantity'=>2]);
$t('aswan_document_amount_430',$aswan['source_amount']===430);
$t('aswan_document_discount_5',$aswan['source_discount_pct']===5.0);
$t('aswan_net_408_5',$aswan['after_discount']===408.5);
$t('aswan_qty2_817',$aswan['extended_reference_amount']===817.0);
$luxor=$c::preview(['key'=>'Luxor:7','vehicle'=>'limousine','quantity'=>1]);
$t('luxor_row7_original_5030_preserved',$luxor['source_amount']===5030);
$t('luxor_row7_net_4778_5',$luxor['after_discount']===4778.5);
$h1=$c::preview(['key'=>'Aswan:1','vehicle'=>'h1','quantity'=>1]);
$t('unpriced_h1_not_zero',$h1['status']==='no_price_in_pdf' && !$h1['missing_is_zero']);
$missing=$c::preview(['key'=>'Luxor:23','vehicle'=>'hiace','quantity'=>1]);
$t('luxor23_missing_stays_missing',$missing['status']==='no_price_in_pdf');
$invalid=$c::preview(['key'=>'Cairo:1','vehicle'=>'h1','quantity'=>1]);
$t('cairo_h1_not_in_supplier_pdf',is_wp_error($invalid));
$t('all_previews_not_checkout',!$aswan['checkout_ready']&&!$luxor['checkout_ready']);
$t('market_adjustment_not_invented',$aswan['market_adjustment_applied']===false);
$invalidPercent=$c::validate([
 'currency'=>'','price_role'=>'reference_only','apply_upper_egypt_five_pct'=>true,
 'global_markup_pct'=>-2,'city_markup_pct'=>$policy['city_markup_pct'],
 'vehicle_markup_pct'=>$policy['vehicle_markup_pct']]);
$t('invalid_negative_markup_rejected',is_wp_error($invalidPercent));
$valid=$c::validate([
 'currency'=>'','price_role'=>'reference_only','apply_upper_egypt_five_pct'=>true,
 'global_markup_pct'=>0,'city_markup_pct'=>$policy['city_markup_pct'],
 'vehicle_markup_pct'=>$policy['vehicle_markup_pct']]);
$t('validated_defaults',is_array($valid));
$currencyBad=$valid;
$currencyBad['currency']='XYZ';
$t('unknown_currency_rejected',is_wp_error($c::validate($currencyBad)));
$drafts=get_posts(['post_type'=>'transportations','post_status'=>['draft','publish'],
 'posts_per_page'=>600,'fields'=>'ids','suppress_filters'=>true,
 'meta_key'=>'_mad4b_transfer_pdf_2026_provider_id','meta_value'=>'self']);
$expected=$site==='https://wayfatours.com'?102:306;
$t('all_localized_posts_self_provider',count($drafts)===$expected);
foreach($drafts as $id)if(get_post_meta($id,
 '_mad4b_transfer_pdf_2026_source_trust',true)!=='trusted_original_pdf'){
    $tests['all_localized_posts_trusted']=false;break;
}
if(!array_key_exists('all_localized_posts_trusted',$tests))
    $t('all_localized_posts_trusted',true);
$t('old_transport_rate_1_safe',get_post_status(66570)==='draft');
$t('old_transport_rate_2_safe',get_post_status(66571)==='draft');
$t('site_currency_usd_not_inferred',$policy['currency']!==get_option('woocommerce_currency'));
$fail=array_keys(array_filter($tests,static fn($pass)=>!$pass));
echo wp_json_encode(['site'=>$site,'total'=>count($tests),
 'passed'=>count($tests)-count($fail),'failed'=>$fail,
 'provider'=>'self','source_trusted'=>true,'currency_guessed'=>false,
 'localized_self_posts'=>count($drafts),'checkout_authorized'=>false],
 JSON_UNESCAPED_SLASHES)."\n";
if($fail)exit(1);
