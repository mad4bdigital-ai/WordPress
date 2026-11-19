<?php
/** First-party tenant migration preview: in-memory WordPress, no DB writes. */
define('ABSPATH',__DIR__.'/');
define('ARRAY_A','ARRAY_A');
if(!function_exists('str_contains')){function str_contains($s,$t){return strpos($s,$t)!==false;}}
if(!function_exists('mb_strtolower')){function mb_strtolower($s){return strtolower($s);}}
function absint($id){return abs((int)$id);}
function get_option($key,$fallback=null){return $key==='wp_page_for_privacy_policy'?3:0;}
function get_post($id){return $GLOBALS['posts'][$id]??null;}
function get_post_meta($id,$key,$single=true){return $GLOBALS['post_meta'][$id][$key]??'';}
function has_filter($name){return false;}
function apply_filters($name,$value,...$args){return $value;}
function get_locale(){return 'en_US';}
function get_permalink($id){return 'https://tenant.example/page/'.$id.'/';}
function get_page_template_slug($id){return 'elementor_header_footer';}
function get_post_thumbnail_id($id){return 0;}
function wp_json_encode($item,$flags=0){return json_encode($item,$flags);}
function get_plugins(){return [
 'elementor/elementor.php'=>['Version'=>'3.30'],
 'elementor-pro/elementor-pro.php'=>['Version'=>'3.30'],
 'woocommerce/woocommerce.php'=>['Version'=>'10.0'],
 'random/random.php'=>['Version'=>'1.0']
];}
function is_plugin_active($name){return $name!=='random/random.php';}
function migration_check($ok,$why){
 if(!$ok){fwrite(STDERR,'FAIL: '.$why.PHP_EOL);exit(1);}
 $GLOBALS['cases']=($GLOBALS['cases']??0)+1;
}
class WpDbStub {
 public $posts='wp_posts',$postmeta='wp_postmeta',$prefix='wp_';
 public $writes=0;
 function esc_like($s){return $s;}
 function prepare($sql,...$args){return $sql;}
 function get_var($sql){return strpos($sql,'SHOW TABLES LIKE')===0?'':0;}
 function get_results($sql,$mode){return [
  ['ID'=>3,'post_name'=>'privacy-policy','post_title'=>'Privacy Policy'],
  ['ID'=>4,'post_name'=>'about-us','post_title'=>'About Us'],
  ['ID'=>5,'post_name'=>'product','post_title'=>'T-Shirt'],
  ['ID'=>6,'post_name'=>'contact','post_title'=>'Contact']
 ];}
}
$wpdb=new WpDbStub();
$GLOBALS['posts']=[
 3=>(object)['ID'=>3,'post_type'=>'page','post_status'=>'publish',
  'post_title'=>'Privacy Policy','post_name'=>'privacy-policy',
  'post_content'=>'Privacy content','post_modified_gmt'=>'2026-11-01 12:00:00'],
 4=>(object)['ID'=>4,'post_type'=>'page','post_status'=>'publish',
  'post_title'=>'About Us','post_name'=>'about-us',
  'post_content'=>'Company story','post_modified_gmt'=>'2026-11-02 12:00:00'],
 5=>(object)['ID'=>5,'post_type'=>'product','post_status'=>'publish',
  'post_title'=>'Product','post_name'=>'product',
  'post_content'=>'','post_modified_gmt'=>'2026-11-03 12:00:00'],
 6=>(object)['ID'=>6,'post_type'=>'page','post_status'=>'draft',
  'post_title'=>'Contact','post_name'=>'contact',
  'post_content'=>'Draft','post_modified_gmt'=>'2026-11-04 12:00:00']
];
$GLOBALS['post_meta']=[
 3=>['_elementor_data'=>'[]','_elementor_edit_mode'=>'builder']
];
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-migration-decision-catalog.php';
$c='MAD4B_SCP_Migration_Decision_Catalog';
migration_check(count($c::definitions())>=14 && count($c::choices())===6,
 'Original dynamic provider catalog and explicit human migration decisions are registered');
migration_check($c::asset_supported('special_pages')
 &&$c::asset_supported('page_3')
 &&$c::asset_supported('page_4')
 &&!$c::asset_supported('page_5')
 &&!$c::asset_supported('page_0')
 &&!$c::asset_supported('page_03')
 &&!$c::asset_supported('page_-1'),
 'Only exact native published/draft WordPress page assets are eligible for migration review');
migration_check($c::legal_page(3) &&!$c::legal_page(4)
 &&!$c::legal_page(5),'Original privacy policy keeps legal approval boundary separate from company About Us');
$pages=$c::special_pages();
migration_check(in_array(3,$pages,true)&&in_array(4,$pages,true)
 &&in_array(6,$pages,true)&&!in_array(5,$pages,true),
 'Source-only page discovery cannot treat unrelated products as editable pages');
$original=$c::comparison('page_3');
migration_check($original['requires_legal_signoff']===true
 &&$original['requires_company_specific_review']===true
 &&$original['target']['external_mutation_enabled']===false
 &&$original['target']['certification']==='NOT_VERIFIED_FOR_THIS_COMPANY'
 &&$original['existing']['details']['source_page_id_not_cross_site_portable']===true,
 'Original privacy page never inherits legal approval, external mutation or portable post IDs');
migration_check($original['existing']['details']['legal_review_required']===true
 &&$original['existing']['details']['elementor_edited']===true
 &&$original['existing']['details']['content_sha256']===hash('sha256','Privacy content'),
 'Original source readback preserves Elementor data, legal fields and immutable content hash');
$about=$c::comparison('page_4');
migration_check($about['requires_legal_signoff']===false
 &&$about['page_specific_choice']===true
 &&$about['existing']['details']['slug']==='about-us',
 'Brand About Us company authoring is reviewed without pretending it is a legal page');
$license=$c::current('licensed_plugins');
migration_check($license['status']==='inspected'
 &&strpos(json_encode($license),'secret')===false
 &&count($license['details']['installed_license_candidate_modules'])===1,
 'Read-only plugin inventory reports module metadata, never entitlement credentials');
$commerce=$c::comparison('commerce');
migration_check($commerce['target']['external_mutation_enabled']===false
 &&$commerce['requires_company_specific_review']===true,
 'Migration of Commerce records is an evaluation, not checkout or pricing mutation');
migration_check($c::comparison('page_5')===[] &&$c::comparison('unsupported')===[],
 'Unsupported or cross-type assets never become a migration plan');
migration_check($wpdb->writes===0,'Original comparison never writes production database rows');
echo 'PASS: MIGRATION_DECISION_CATALOG '.($GLOBALS['cases']??0).
 ' company Page, legal and no-mutation review cases'.PHP_EOL;
