<?php
// Standalone, read-only acceptance checks of typed score-provider contracts.
// No WordPress bootstrap, network requests, user sessions, or database writes.
define('ABSPATH','/tmp/');
$GLOBALS['fip_hook']=null;
$GLOBALS['fip_booking_id_hook']=null;
function add_filter($name,$callback,$priority=10,$accepted=1) {
    if($name==='mad4b/frontend_impact/score_unmapped_issue')$GLOBALS['fip_hook']=$callback;
    if($name==='mad4b/frontend_impact/booking_form_ids')$GLOBALS['fip_booking_id_hook']=$callback;
}
function apply_filters($name,$value) {
    if($name==='mad4b/frontend_impact/booking_form_ids'&&is_callable($GLOBALS['fip_booking_id_hook']))
        return ($GLOBALS['fip_booking_id_hook'])($value);
    return $value;
}
function shared_presentation_form6_price_quote() {return null;}
function get_post_type($id) {return $id===60800?'tour-rates':($id===60629?'tours-and-activities':null);}
function get_post_status($id) {return $id===60629?'publish':null;}
function get_post_meta($id,$field,$single=false) {
    if($id!==60800)return null;
    return $field==='related_tour_id'?60629:
        ($field==='tour_rate_end_date'?time()-7200:null);
}
class MAD4B_SCP_Content_Readiness_Alerts {
    public static function rules() {return ['form_id'=>6];}
}
require '/var/www/html/wp-content/mu-plugins/mad4b-frontend-impact-commerce-evidence.php';
$callback=$GLOBALS['fip_hook'];
if(!is_callable($callback))throw new RuntimeException('Filter not registered');
$u=['score'=>null,'confidence'=>'not_mapped'];
$pages=['es'=>[
    ['url'=>'https://example.test/es/tour/','kind'=>'tour','status'=>200,
     'post_ids'=>[60629],'forms'=>3,'form_ids'=>[6,2],'listing_grids'=>12,'price_surfaces'=>2],
    ['url'=>'https://example.test/es/tours/','kind'=>'archive','status'=>200,
     'post_ids'=>[60629],'listing_grids'=>11,'filters'=>20]
]];
$issue=static fn($code,$source='mad4b-live-performance',$type='site',$id=0)=>
    ['code'=>$code,'source'=>$source,'post_type'=>$type,'object_id'=>$id];
$cases=[
  ['expired_rate_verifiable',$issue('expired_rate_renewal_required','core','tour-rates',60800),$pages,80],
  ['expired_rate_unseen',$issue('expired_rate_renewal_required','core','tour-rates',60800),['es'=>[]],null],
  ['form_followup_verified',$issue('form_submissions_unread','core'),$pages,54],
  ['form_followup_unlinked',$issue('form_submissions_unread','core'),['es'=>[['url'=>'https://example.test/','kind'=>'home','status'=>200,'form_ids'=>[2]]]],null],
  ['catalog_layout_verified',$issue('perf_catalog_initial_layout'),$pages,74],
  ['rate_locale_surface',$issue('perf_rate_locale_parity'),$pages,79],
  ['rate_css_surface',$issue('perf_ratecss_source_missing'),$pages,62],
  ['site_ttfb_exposure',$issue('perf_ttfb_site'),['en'=>[['url'=>'https://example.test/','kind'=>'home','status'=>200]]],69],
  ['stale_probe_instrumentation',$issue('perf_probe_stale'),[],29],
  ['unknown_untouched',$issue('not_existing'),$pages,null],
];
$checks=[];
foreach($cases as [$label,$i,$p,$expected]){
 $value=$callback($u,$i,$p);
 $actual=$value['score']??null;
 $pass=$actual===$expected;
 $checks[]=['case'=>$label,'expected'=>$expected,'actual'=>$actual,'pass'=>$pass];
 if(!$pass)throw new RuntimeException('FAILED: '.$label);
 if($actual!==null && (($value['publication_authorized']??true)!==false ||
    ($value['booking_authorized']??true)!==false))throw new RuntimeException('Unsafe mutation metadata');
}
// A fake HTML fixture verifies only the static DOM contract. Real staging
// page acceptance is separately demonstrated using a public GET.
require '/var/www/html/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-frontend-impact-priority.php';
$markup='<!doctype html><html><body><div class="elementor-element" data-id="one"><span class="etg-tour-price etg-price-native">150</span><form id="fluentform_6" data-form_id="6" class="frm-fluent-form"><button type="submit">Select</button></form></div></body></html>';
$parsed=MAD4B_SCP_Frontend_Impact_Priority::inspect_html($markup,
    'https://example.test/tours/example/','tour');
if(($parsed['booking_form_contracts']??[])!==[6] ||
   ($parsed['booking_controls']??0)!==1 ||
   ($parsed['price_surfaces']??0)!==1)
    throw new RuntimeException('Native booking/price DOM contract failed');
$checks[]=['case'=>'native_booking_form_and_price_dom','expected'=>true,'actual'=>true,'pass'=>true];
echo json_encode(['contract'=>'mad4b.frontend-impact-component-acceptance.v1',
    'checks'=>count($checks),'passed'=>count($checks),'results'=>$checks],JSON_UNESCAPED_SLASHES)."\n";
