<?php
/** Site-neutral read-only WPML/price/media nonregression. Run via WP-CLI. */
defined('ABSPATH') || exit(2);
if (!class_exists('MAD4B_Transport_PDF_Review_V1')) {
    require_once dirname(__DIR__).'/includes/transport-pdf-review.php';
}
$site=rtrim(home_url('/'),'/');
if(!in_array($site,array('https://tours.dev.mad4b.com','https://wayfatours.com'),true)){
 echo "STATUS=SKIP unsupported site\n";return;
}
$isWayfa=$site==='https://wayfatours.com';
$active=MAD4B_Transport_PDF_Review_V1::active_languages();
sort($active);
$expected=$isWayfa?array('en','es'):array('ar','de','en','es','fr','it');
$checks=array();
$check=static function($name,$pass)use(&$checks){$checks[$name]=(bool)$pass;};
$check('active_languages_match_site',$active===$expected);
$source=require WP_PLUGIN_DIR.'/mad4b-pricing-registry/data/transport-pdf-2026-localized.php';
$check('source_pdf_sha',($source['source_sha256']??'')==='c3bde2be4ac89f1b7b9671720b552d39646c93ea9fa5a786fe2a9bde8f1644cd');
$check('source_51_rows',count($source['rows']??[])===51);
$check('source_210_filled_price_cells',array_sum(array_map(static fn($r)=>
    count(array_filter($r['vehicle_rates_unverified'],
        static fn($price)=>is_numeric($price) && $price>0)),$source['rows']))===210);
$check('supplier_currency_not_inferred',empty($source['currency_confirmed']) && empty($source['price_role_confirmed']));
$check('pdf_classes_separate',isset($source['rows'][0]['vehicle_rates_unverified']['luxury_7_seats'])
    && !isset($source['rows'][0]['vehicle_rates_unverified']['h1']));
$media=get_option('mad4b_transfer_pdf_2026_vehicle_media_map_v1',array());
$check('six_class_keys_five_licensed_photos',count((array)$media)===6 && count(array_unique($media))===5);
$meta=WP_PLUGIN_DIR.'/mad4b-pricing-registry/data/transport-pdf-2026-media-manifest.php';
$manifest=require $meta;
foreach ($manifest['items'] as $key=>$m) {
    $imageKey=$key==='luxury7_h1'?'luxury_7_seats':$key;
    $id=(int)($media[$imageKey]??0);
    $check('photo_'.$key.'_present',$id>0 && get_post_type($id)==='attachment' &&
       get_post_meta($id,'_mad4b_transport_media_license',true)===$m['license'] &&
       get_post_meta($id,'_mad4b_transport_media_source',true)===$m['source']);
    foreach($active as $lang) {
        $check('alt_'.$key.'_'.$lang,
            get_post_meta($id,'_mad4b_transport_media_alt_'.$lang,true)===$m['alt_localized'][$lang]);
    }
}
$check('h1_and_7seat_price_different_classes',$media['h1']===$media['luxury_7_seats']);
$totalLocalRows=0;
foreach($active as $lang) {
    $snapshot=MAD4B_Transport_PDF_Review_V1::snapshot('',$lang);
    $check('review_51_'.$lang,$snapshot['count']===51);
    $check('review_issues_zero_'.$lang,$snapshot['review_issues']===0);
    $check('review_no_commerce_'.$lang,$snapshot['checkout_authorized']===false &&
       $snapshot['mutation_performed']===false && $snapshot['price_role_confirmed']===false);
    $seen=array();
    foreach($snapshot['rows'] as $entry) {
        $key=$entry['source_key'];
        $id=(int)($entry['draft_ids'][0]??0);
        $seen[$key]=($seen[$key]??0)+1;
        $details=apply_filters('wpml_post_language_details',null,$id);
        $check('row_'.$lang.'_'.$key,
            $id>0 && $entry['draft_count']===1 &&
            get_post_status($id)==='draft' &&
            ($details['language_code']??null)===$lang &&
            get_post_meta($id,'_mad4b_transfer_pdf_2026_not_sellable',true)==1 &&
            get_post_meta($id,'trans_start_price',true)==='' &&
            get_post_meta($id,'trans_base_currency',true)==='' &&
            get_post_meta($id,'trans_type',true)==='');
        ++$totalLocalRows;
    }
    $check('unique_source_rows_'.$lang,count($seen)===51 && max($seen)===1);
}
$check('localized_post_count',$totalLocalRows===51*count($active));
$check('source_specific_example_cairo',
    (int)($source['rows'][0]['vehicle_rates_unverified']['limousine']??0)===1300 &&
    (int)($source['rows'][0]['vehicle_rates_unverified']['luxury_7_seats']??0)===1400 &&
    (int)($source['rows'][0]['vehicle_rates_unverified']['hiace']??0)===1600);
$missingRow=array_values(array_filter($source['rows'],
    static fn($r)=>$r['source_key']==='Luxor:23'));
$check('luxor_missing_prices_not_zero',count($missingRow)===1 &&
    !count(array_filter($missingRow[0]['vehicle_rates_unverified'],static fn($v)=>$v!==null)));
$check('source_5pct_aswan_luxor_unapplied',count(array_filter($source['rows'],
    static fn($r)=>in_array($r['city'],array('Aswan','Luxor'),true) &&
       $r['supplier_discount_note']==='before_5_percent_discount'))===38);
$check('old_rate_status_1',get_post_status(66570)==='draft');
$check('old_rate_status_2',get_post_status(66571)==='draft');
$check('transport_review_module_registered',class_exists('MAD4B_Transport_PDF_Review_V1'));
$check('drafts_not_public',!$source['published_rates_authorized']);
$failed=array_keys(array_filter($checks,static fn($pass)=>!$pass));
echo wp_json_encode(array('site'=>$site,'total'=>count($checks),
    'passed'=>count($checks)-count($failed),'failed'=>$failed,
    'active_languages'=>$active,'created_locale_drafts'=>$totalLocalRows,
    'vehicle_media_physical_files'=>count(array_unique($media)),
    'source_prices_approved'=>false,'checkout_activated'=>false),
    JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
if ($failed) exit(1);
