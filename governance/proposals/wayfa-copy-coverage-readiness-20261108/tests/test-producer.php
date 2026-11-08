<?php
/**
 * Standalone, side-effect-free unit tests for the staged Copy Coverage producer.
 * No WordPress database, API calls, network access, or production writes.
 */
declare(strict_types=1);
define("ABSPATH", "/test-only/");
$GLOBALS['site_url']='https://wayfatours.com/';
$GLOBALS['snapshot']=[];
$GLOBALS['post_status']='publish';
function get_option($name,$default=[]){return $GLOBALS['snapshot'];}
function home_url($suffix='/'){return $GLOBALS['site_url'];}
function absint($value){return abs((int)$value);}
function get_post_type($id){return $id===26038?'elementor_library':'page';}
function get_post_status($id){return $GLOBALS['post_status'];}
function apply_filters($tag,$default,$id=null){return $tag==='wpml_post_language_details'?['language_code'=>'es']:$default;}
function get_current_blog_id(){return 1;}
function add_filter($hook,$cb,$priority,$accepted){return true;}
require dirname(__DIR__).'/mad4b-elementor-copy-readiness-producer.php';
function check($ok,$message){if(!$ok){fwrite(STDERR,"FAIL $message\n");exit(1);}echo "PASS $message\n";}
$finding=['code'=>'filter_week_typo','priority'=>75,'post_id'=>26038,
    'locale'=>'es','widget_id'=>'a1234bc','path'=>'settings.items[0].item_title'];
$GLOBALS['snapshot']=['contract'=>'mad4b.elementor-copy-coverage.v1',
 'site'=>'https://wayfatours.com/','alerts'=>[$finding,$finding]];
$rows=mad4b_copy_coverage_readiness_advisories([]);
check(count($rows)===1,'valid stored issue admitted once, duplicates rejected');
check($rows[0]['source']==='mad4b-elementor-copy-coverage' &&
      $rows[0]['priority']===75 && $rows[0]['severity']==='medium',
      'bounded source/severity/priority');
$expected=hash('sha256','1|elementor_library|26038|'.$rows[0]['code']);
check($rows[0]['id']===$expected,'stable WordPress Content Readiness fingerprint');
$cases=[
 ['bad_code',['code'=>'unapproved_source']],
 ['bad_priority',['priority'=>100]],
 ['bad_locale',['locale'=>'xx']],
 ['bad_widget',['widget_id'=>'<script>']],
 ['bad_post',['post_id'=>10]],
 ['empty_path',['path'=>'']],
];
foreach($cases as [$name,$change]){
 $GLOBALS['snapshot']['alerts']=[array_replace($finding,$change)];
 check(mad4b_copy_coverage_readiness_advisories([])===[],
       "$name rejected");
}
$GLOBALS['snapshot']['alerts']=[$finding];
$GLOBALS['post_status']='draft';
check(mad4b_copy_coverage_readiness_advisories([])===[],'unpublished template rejected');
$GLOBALS['post_status']='publish';
$GLOBALS['snapshot']['site']='https://other.invalid/';
check(mad4b_copy_coverage_readiness_advisories([])===[],'cross-company snapshot rejected');
$GLOBALS['snapshot']['site']='https://wayfatours.com/';
$GLOBALS['snapshot']['contract']='unknown-contract';
check(mad4b_copy_coverage_readiness_advisories([])===[],'unrecognized contract rejected');
echo "ALL_PRODUCER_TESTS_PASS\n";
