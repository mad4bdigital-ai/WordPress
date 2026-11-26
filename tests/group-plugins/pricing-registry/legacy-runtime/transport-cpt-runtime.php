<?php
/** Read-only integration checks; run with WP-CLI against staging after CPT migration. */
if (!defined('ABSPATH')) exit(2);
if (rtrim(home_url('/'), '/') !== 'https://tours.dev.mad4b.com') {
    echo "STATUS=SKIP staging runtime fixture not available on this site\n";
    exit(0);
}
if (!class_exists('MAD4B_Transport_CPT_Readiness_V1')) {
    require dirname(__DIR__,4) . '/wp-content/plugins/mad4b-pricing-registry/includes/transport-cpt-readiness.php';
}
$checks=array();
$add=static function($name,$ok) use (&$checks){$checks[$name]=(bool)$ok;};
$s=MAD4B_Transport_CPT_Readiness_V1::snapshot();
$add('canonical_cpt',get_post_type_object('transport-rates')!==null);
$add('wpml_fallback_equals_property_rate',$s['wpml_post_type_mode']==2 &&
    apply_filters('wpml_sub_setting',null,'custom_posts_sync_option','property-rates')==2);
$add('fields_migrated',empty($s['missing_cpt_fields']));
$add('financial_meta_copied',empty($s['wpml_missing_copy_fields']));
$add('cpt_rate_count_preserved',$s['rates_count']===5);
$add('cct_rows_migrated',$s['cct_migrated_count']===2);
$add('cct_duplicates_absent',$s['legacy_cct_duplicate_count']===0);
$add('legacy_one_link',get_post_meta(66570,'source_cct_id',true)==='1');
$add('legacy_two_link',get_post_meta(66571,'source_cct_id',true)==='2');
$add('legacy_financial_amount_unchanged',(float)get_post_meta(66570,'base_amount',true)===1300.0);
$add('language_grouping',$s['translation_groups']===5 && ($s['languages']['en']??0)===5);
$add('market_rules_inspected',$s['market_profile_rules_count']===1);
$add('existing_invalid_legacy_flagged',count(array_filter($s['rate_reviews'],
    static fn($item)=>in_array('mode_mismatch',$item['issues'],true)))===2);
$add('no_checkout_changed',!$s['checkout_authorized']&&!$s['published_price_changed']
    && !$s['mutation_performed']);
$add('no_automatic_price_role',get_post_meta(66570,'trans_price_role',true)==='');
$add('market_meta_copied',get_option('icl_sitepress_settings')
   ['translation-management']['custom_fields_translation']['_booking_experience_transport_market_rates_v1']===1);
$add('language_specific_notes',get_option('icl_sitepress_settings')
   ['translation-management']['custom_fields_translation']['note']===2);
$add('no_unapproved_rate_approval',function_exists('BookingExperience\transport_rate_approval_verified')
    && !\BookingExperience\transport_rate_approval_verified(66570)
    && !\BookingExperience\transport_rate_approval_verified(66571));
foreach($checks as $name=>$ok) echo $name.'='.($ok?'PASS':'FAIL')."\n";
echo 'TOTAL='.count(array_filter($checks)).'/'.count($checks)."\n";
if(in_array(false,$checks,true))exit(1);
