<?php
declare(strict_types=1);
define('ABSPATH','/fixture/');
define('DAY_IN_SECONDS',86400);
define('WP_CONTENT_DIR','/tmp/mad4b-native-lazy-parity-fixture');
@mkdir(WP_CONTENT_DIR.'/uploads/elementor/css',0770,true);
$css_file=WP_CONTENT_DIR.'/uploads/elementor/css/post-45938.css';
file_put_contents($css_file,str_repeat('first-class-authoring-generated-css;',30));
$GLOBALS['style_file']=$css_file;
function add_action(...$args){}function add_filter(...$args){}
function absint($v){return abs((int)$v);}
function wp_is_mobile(){return false;} // Regression: desktop UA must still reserve CSS.
function get_post_status($id){return 'publish';}
function apply_filters($hook,$value,...$args){if($hook==='wpml_current_language')return $GLOBALS['locale'];return $value;}
function get_option($k,$default=false){return $GLOBALS['options'][$k]??$default;}
function get_post_meta($id,$key,$single=true){return $GLOBALS['meta'][$id][$key]??null;}
function home_url($path=''){return 'https://tours.dev.mad4b.com'.$path;}
function wp_parse_url($raw,$component=-1){return parse_url($raw,$component);}
function mad4b_render_query_market_identity(){return $GLOBALS['market'];}
function mad4b_render_query_generation_for_id($id){return $GLOBALS['effective_query_generation']??'';}

$GLOBALS['effective_query_generation']='generation-a';
$GLOBALS['locale']='en';
$GLOBALS['market']=['country'=>'DE','currency'=>'EUR'];
$GLOBALS['meta']=[
 25927=>['_elementor_data'=>str_repeat('correct-authoring-payload;',20)],
 45938=>['_elementor_data'=>str_repeat('listing-source-data;',20)],
 44=>['_elementor_page_settings'=>['viewport_mobile'=>767]],
];
$GLOBALS['options']=[
 'elementor_active_kit'=>44,
 'mad4b_css_ai_epoch_v1'=>123,
 'mad4b_render_query_data_generation'=>'generation-a'
];
$docSha=hash('sha256',$GLOBALS['meta'][25927]['_elementor_data']);
$linksHash=hash('sha256','the verified link sequence');
$contentHash=hash('sha256','the verified content sequence');
$listingHash=hash('sha256',$GLOBALS['meta'][45938]['_elementor_data']);
$styleHash=hash_file('sha256',$GLOBALS['style_file']);
function proof($band) {
 global $docSha,$linksHash,$contentHash,$listingHash,$styleHash;
 return [
  'status'=>'pass','listing_id'=>45938,'locale'=>'en','device'=>$band,
  'listing_source_sha256'=>$listingHash,'listing_css_sha256'=>$styleHash,
  'height_px'=>$band==='mobile'?464:500,
  'ajax_parity_by_market'=>['de-eur'=>[
   'contract'=>'mad4b.native-listing.ajax-parity.v2',
   'status'=>'pass','query_id'=>291,'document_id'=>25927,
   'document_sha256'=>$docSha,'query_generation'=>'generation-a',
   'locale'=>'en','market'=>'de-eur','signed_ajax_verified'=>true,
   'semantic_output_equivalent'=>true,'max_cls'=>0.018,
   'audited_at'=>time(),'ssr_items'=>14,'ajax_items'=>14,
   'ssr_links_sha256'=>$linksHash,'ajax_links_sha256'=>$linksHash,
   'ssr_content_sha256'=>$contentHash,'ajax_content_sha256'=>$contentHash,
   'tested_widths'=>$band==='mobile'?[320,390,767]:[768,1440,1920],
   'viewport_evidence'=>array_combine(
      $band==='mobile'?['320','390','767']:['768','1440','1920'],
      array_map(static function($w)use($band,$linksHash,$contentHash){
       return [
        'width'=>$w,'status'=>'pass','signed_ajax_verified'=>true,
        'ssr_items'=>14,'ajax_items'=>14,
        'ssr_height_px'=>$band==='mobile'?464:500,
        'reserved_height_px'=>$band==='mobile'?464:500,
        'ssr_width_px'=>$w-20,'reserved_width_px'=>$w-20,
        'max_cls'=>0.018,
        'ssr_links_sha256'=>$linksHash,'ajax_links_sha256'=>$linksHash,
        'ssr_content_sha256'=>$contentHash,'ajax_content_sha256'=>$contentHash
       ];
      },$band==='mobile'?[320,390,767]:[768,1440,1920])
   ),
  ]]
 ];
}
$GLOBALS['options']['mad4b_secondary_grid_lazy_layout_proofs_v1']=[
 'contract'=>'mad4b.native-listing.layout-proof.v1',
 'css_epoch'=>123,'site_host'=>'tours.dev.mad4b.com','mobile_breakpoint'=>767,
 'entries'=>[
  '45938|en|mobile'=>proof('mobile'),
  '45938|en|wide'=>proof('wide')
 ]
];
require $argv[1];
function check(bool $expected,string $name):void {
 $actual=mad4b_secondary_grid_lazy_ajax_parity_proven(291,45938,25927);
 if($actual!==$expected){fwrite(STDERR,"FAIL $name expected=".json_encode($expected)." actual=".json_encode($actual)."\n");exit(1);}
 echo "PASS $name\n";
}
$origin=$GLOBALS['options']['mad4b_secondary_grid_lazy_layout_proofs_v1'];
check(true,'complete trusted country/locale/CSS/content/viewport parity');
$GLOBALS['market']['country']='US';check(false,'foreign market cannot share country proof');
$GLOBALS['market']['country']='DE';
$GLOBALS['effective_query_generation']='generation-b';
check(false,'stale query generation denied');$GLOBALS['effective_query_generation']='generation-a';
$GLOBALS['options']['mad4b_render_query_data_generation']='unrelated-global-fragment-epoch';
check(true,'unrelated legacy fragment update preserves valid scoped certificate');
$GLOBALS['locale']='es';check(false,'missing translated locale proof denied');$GLOBALS['locale']='en';
$GLOBALS['options']['mad4b_css_ai_epoch_v1']=124;check(false,'stale css epoch denied');$GLOBALS['options']['mad4b_css_ai_epoch_v1']=123;
$GLOBALS['meta'][25927]['_elementor_data'].='changed';check(false,'modified Elementor document denied');$GLOBALS['meta'][25927]['_elementor_data']=str_repeat('correct-authoring-payload;',20);
unset($GLOBALS['options']['mad4b_secondary_grid_lazy_layout_proofs_v1']['entries']['45938|en|wide']);
check(false,'missing wide viewport proof denied');
$GLOBALS['options']['mad4b_secondary_grid_lazy_layout_proofs_v1']=$origin;
$ref=&$GLOBALS['options']['mad4b_secondary_grid_lazy_layout_proofs_v1']['entries']['45938|en|mobile']['ajax_parity_by_market']['de-eur'];
$ref['ajax_items']=2;check(false,'different native AJAX item count denied');$ref['ajax_items']=14;
$ref['ajax_links_sha256']=hash('sha256','different list');check(false,'different link order denied');$ref['ajax_links_sha256']=$linksHash;
$ref['ajax_content_sha256']=hash('sha256','different content');check(false,'different content denied');$ref['ajax_content_sha256']=$contentHash;
$ref['max_cls']=0.455;check(false,'layout shift denied');$ref['max_cls']=0.018;
$ref['signed_ajax_verified']=false;check(false,'unsigned AJAX denied');$ref['signed_ajax_verified']=true;
$ref['audited_at']=time()-(8*DAY_IN_SECONDS);check(false,'expired browser QA denied');$ref['audited_at']=time();
$ref['tested_widths']=[390];check(false,'partial viewport QA denied');$ref['tested_widths']=[320,390,767];
$ref['query_generation']='generation-old';check(false,'incorrect query generation denied');$ref['query_generation']='generation-a';
check(true,'restored valid evidence allowed');
$GLOBALS['meta'][45938]['_elementor_data'].='new-content';check(false,'modified listing source denied');$GLOBALS['meta'][45938]['_elementor_data']=str_repeat('listing-source-data;',20);
file_put_contents($GLOBALS['style_file'],'modified style '.str_repeat('x',100));check(false,'modified Elementor generated CSS denied');
file_put_contents($GLOBALS['style_file'],str_repeat('first-class-authoring-generated-css;',30));
$GLOBALS['meta'][44]['_elementor_page_settings']['viewport_mobile']=768;check(false,'changed native Elementor breakpoint denied');$GLOBALS['meta'][44]['_elementor_page_settings']['viewport_mobile']=767;
check(true,'full source/CSS/breakpoint proof restored');
unset($ref);
$original=unserialize(serialize($GLOBALS['options']['mad4b_secondary_grid_lazy_layout_proofs_v1']));
$detail=&$GLOBALS['options']['mad4b_secondary_grid_lazy_layout_proofs_v1']['entries']['45938|en|wide']['ajax_parity_by_market']['de-eur'];
unset($detail['viewport_evidence']['1920']);
check(false,'missing certified viewport denied');unset($detail);$GLOBALS['options']['mad4b_secondary_grid_lazy_layout_proofs_v1']=unserialize(serialize($original));
$detail=&$GLOBALS['options']['mad4b_secondary_grid_lazy_layout_proofs_v1']['entries']['45938|en|wide']['ajax_parity_by_market']['de-eur'];
$detail['viewport_evidence']['768']['ajax_items']=13;
check(false,'per-viewport count drift denied');unset($detail);$GLOBALS['options']['mad4b_secondary_grid_lazy_layout_proofs_v1']=unserialize(serialize($original));
$detail=&$GLOBALS['options']['mad4b_secondary_grid_lazy_layout_proofs_v1']['entries']['45938|en|wide']['ajax_parity_by_market']['de-eur'];
$detail['viewport_evidence']['1920']['ajax_links_sha256']=hash('sha256','wrong footer');
check(false,'responsive link drift denied');unset($detail);$GLOBALS['options']['mad4b_secondary_grid_lazy_layout_proofs_v1']=unserialize(serialize($original));
$detail=&$GLOBALS['options']['mad4b_secondary_grid_lazy_layout_proofs_v1']['entries']['45938|en|mobile']['ajax_parity_by_market']['de-eur'];
$detail['viewport_evidence']['390']['reserved_height_px']=36;
check(false,'unreserved placeholder denied');unset($detail);$GLOBALS['options']['mad4b_secondary_grid_lazy_layout_proofs_v1']=unserialize(serialize($original));
check(true,'all source and responsive per-viewport certificates restored');
echo "PASS 26/26 native conditional term SSR/AJAX evidence tests\n";
function checkInner(bool $expected,string $name):void{
 $got=mad4b_secondary_grid_inner_completed_delivery_proven(291,45938,25927,3);
 if($got!==$expected){fwrite(STDERR,"FAIL Inner ".$name."\n");exit(1);}
 echo "PASS Inner ".$name."\n";
}
checkInner(false,'no independent pagination proof defaults to full first AJAX');
$proof=[
 'status'=>'pass',
 'mode'=>'native-signed-get-listing-and-load-more',
 'query_generation'=>'generation-a',
 'document_sha256'=>hash('sha256',$GLOBALS['meta'][25927]['_elementor_data']),
 'listing_sha256'=>hash('sha256',$GLOBALS['meta'][45938]['_elementor_data']),
 'listing_css_sha256'=>hash_file('sha256',$GLOBALS['style_file']),
 'first_ajax_signed'=>true,'every_load_more_signed'=>true,
 'terminal_page_verified'=>true,'same_order_and_content_as_ssr'=>true,
 'max_cls'=>0.018,'audited_at'=>time(),
 'ssr_items'=>8,'all_pages_items'=>8,'page_item_counts'=>[3,3,2],
 'ssr_links_sha256'=>$linksHash,'loaded_links_sha256'=>$linksHash,
 'ssr_content_sha256'=>$contentHash,'loaded_content_sha256'=>$contentHash,
 'tested_widths'=>[320,390,767,768,1024,1920],
];
$k='291|45938|25927|en|de-eur|3';
$GLOBALS['options']['mad4b_secondary_grid_inner_pagination_proofs_v1']=[
 'contract'=>'mad4b.native-inner.complete-pagination.v1',
 'site_host'=>'tours.dev.mad4b.com','css_epoch'=>123,'entries'=>[$k=>$proof]
];
checkInner(true,'complete matching native get_listing and load-more accepted');
$GLOBALS['market']['country']='US';checkInner(false,'foreign market denied');$GLOBALS['market']['country']='DE';
$GLOBALS['locale']='es';checkInner(false,'different WPML locale denied');$GLOBALS['locale']='en';
$GLOBALS['effective_query_generation']='changed';checkInner(false,'stale Query Builder generation denied');$GLOBALS['effective_query_generation']='generation-a';
$GLOBALS['options']['mad4b_css_ai_epoch_v1']=124;checkInner(false,'new CSS epoch denied');$GLOBALS['options']['mad4b_css_ai_epoch_v1']=123;
$GLOBALS['meta'][45938]['_elementor_data'].='changed';checkInner(false,'stale listing fingerprint denied');$GLOBALS['meta'][45938]['_elementor_data']=str_repeat('listing-source-data;',20);
$ref=&$GLOBALS['options']['mad4b_secondary_grid_inner_pagination_proofs_v1']['entries'][$k];
$ref['every_load_more_signed']=false;checkInner(false,'unsigned additional AJAX page denied');$ref['every_load_more_signed']=true;
$ref['terminal_page_verified']=false;checkInner(false,'missing terminal page denied');$ref['terminal_page_verified']=true;
$ref['all_pages_items']=6;checkInner(false,'incomplete delivered counts denied');$ref['all_pages_items']=8;
$ref['page_item_counts']=[3,3];checkInner(false,'missing page denied');$ref['page_item_counts']=[3,3,2];
$ref['loaded_content_sha256']=hash('sha256','wrong content');checkInner(false,'wrong body order denied');$ref['loaded_content_sha256']=$contentHash;
$ref['loaded_links_sha256']=hash('sha256','wrong links');checkInner(false,'wrong link order denied');$ref['loaded_links_sha256']=$linksHash;
$ref['max_cls']=0.34;checkInner(false,'excessive CLS denied');$ref['max_cls']=0.018;
$ref['tested_widths']=[320,390,767,1024,1920];checkInner(false,'missing 768 boundary denied');$ref['tested_widths']=[320,390,767,768,1024,1920];
$ref['audited_at']=time()-8*DAY_IN_SECONDS;checkInner(false,'stale pagination proof denied');$ref['audited_at']=time();
checkInner(true,'full E2E proof can be re-admitted');
echo "PASS 41/41 combined outer and inner lazy evidence assertions\n";
$settings=['lisitng_id'=>45938];
$registry=$GLOBALS['options']['mad4b_secondary_grid_lazy_layout_proofs_v1'];
$registry['entries']['45938|en|mobile']['sampled_widths']=[320,390,767];
$registry['entries']['45938|en|wide']['sampled_widths']=[768,820,1024,1440,1920];
$GLOBALS['options']['mad4b_secondary_grid_lazy_layout_proofs_v1']=$registry;
$mh=mad4b_secondary_grid_lazy_measured_mobile_height($settings,'mobile');
$wh=mad4b_secondary_grid_lazy_measured_mobile_height($settings,'wide');
if($mh!==464 || $wh!==500){fwrite(STDERR,"FAIL desktop viewport reservation $mh/$wh\n");exit(1);}
echo "PASS desktop UA reserves BOTH native viewport footprints $mh/$wh\n";
$GLOBALS['options']['mad4b_css_ai_epoch_v1']=124;
if(mad4b_secondary_grid_lazy_measured_mobile_height($settings,'mobile')!==0
    ||mad4b_secondary_grid_lazy_measured_mobile_height($settings,'wide')!==0){
 fwrite(STDERR,"FAIL stale desktop CSS epoch admission\n");exit(1);
}
echo "PASS stale desktop CSS epoch blocks automatic lazy reservation\n";
echo "PASS 43/43 complete evidence and all-device viewport guard assertions\n";
