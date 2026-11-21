<?php
/** Isolated destination term link audit, no WordPress boot/DB/HTTP/writes. */
if (!defined('ABSPATH')) define('ABSPATH','/');
if (!function_exists('mb_strtolower')) { function mb_strtolower($s,$e='UTF-8') {return strtolower($s);} }
if (!function_exists('mb_substr')) { function mb_substr($s,$start,$length=null) {return substr($s,$start,$length);} }
if (!function_exists('mb_strlen')) { function mb_strlen($s) {return strlen($s);} }
if (!function_exists('str_contains')) { function str_contains($a,$b) {return strpos($a,$b)!==false;} }
if (!function_exists('str_starts_with')) { function str_starts_with($a,$b) {return strpos($a,$b)===0;} }
class WP_Term {
 public $term_id=665;public $taxonomy='destinations';public $name='Egypt';
 public $description='Egypt is a destination with ancient monuments and Nile cruises.';
}
class WP_Error {}
function is_wp_error($v) {return $v instanceof WP_Error;}
function absint($x) {return abs((int)$x);}
function get_term($id) {if($id!==665 && $id!==666)return null;$t=new WP_Term();$t->term_id=$id;return $t;}
function get_term_meta($id,$key='',$single=false) {$data=array(
 'featured_image'=>array('https://staging.egypttourgates.com/wp-content/uploads/2026/02/example.jpg'),
 'image_gellery'=>array('https://staging.egypttourgates.com/wp-content/uploads/2026/02/first.jpg,https://staging.egypttourgates.com/wp-content/uploads/2026/02/second.jpg'),'destination_contents'=>array(serialize(array(
 'further_reading'=>array(
  array('title'=>'Cairo Tours','url'=>'https://other-tour-operator.example/cairo-tours'),
  array('title'=>'Wayfa trip','url'=>'https://wayfatours.com/tours/cairo'),
  array('title'=>'Book source','content'=>'<a href="https://another-operator.example/book">Cairo Tours</a>')
 ),
 'editorial_reference'=>array('url'=>'https://museum.example/research'),
 'flat_contents'=>'<p><a href="https://flat-commercial.example/cairo">Cairo Tours</a></p>'
))));if($id===666)$data=array('destination_contents'=>array(serialize(array('further_reading'=>array(array('url'=>'https://wayfatours.com/es/egypt'))))));if($key==='')return $data;return $single?maybe_unserialize($data[$key][0]??''):($data[$key]??array());}
function maybe_unserialize($v) {return is_string($v)&&preg_match('/^a:\d+:/',$v)?unserialize($v):$v;}
function get_bloginfo($key) {return 'Wayfa Tours';}
function home_url($path='/') {return 'https://wayfatours.com'.$path;}
function wp_parse_url($url,$component=-1) {return parse_url($url,$component);}
function wp_strip_all_tags($s) {return strip_tags($s);}
function remove_accents($s) {return $s;}
function wp_json_encode($v) {return json_encode($v);}
function apply_filters($n,$v) {return $v;}
function get_taxonomies($args=array(),$output='names') {return array('destinations');}
function get_terms($args=array()) {if(($args['fields']??'')==='ids')return array_slice(array(665,666),(int)($args['offset']??0),(int)($args['number']??2));return array('Egypt');}
function taxonomy_exists($tax) {return $tax==='destinations';}
function get_taxonomy($tax) {return (object)array('public'=>$tax==='destinations');}
function sanitize_key($s) {return strtolower((string)$s);}
function wp_get_upload_dir() {return array('basedir'=>sys_get_temp_dir(),'error'=>false);}
function get_current_blog_id() {return 1;}
function must($ok,$why) {if(!$ok){fwrite(STDERR,"FAIL: ".$why."\n");exit(1);}}
require_once dirname(__DIR__).'/includes/class-mad4b-scp-brand-integrity.php';
$brand=array('scope'=>array('brand_name'=>'Wayfa Tours','site_uuid'=>'fixture-wayfa'));
$r=MAD4B_SCP_Brand_Integrity::scan_term_report(665,$brand);
must($r['complete']&&$r['mutation_performed']===false,'readonly term scan complete');
must($r['object_id']===665&&$r['taxonomy']==='destinations','term identity retained');
must($r['field_count']>=4,'nested term repeater inspected');
$hosts=array();
foreach($r['issues'] as $issue)$hosts[$issue['source_evidence']['matched_entity']]=$issue;
must(isset($hosts['other-tour-operator.example']),'URL-only competitor link detected');
must(isset($hosts['another-operator.example']),'HTML competitor link detected');
must(isset($hosts['flat-commercial.example'])&&$hosts['flat-commercial.example']['severity']==='high','commercial link in flat HTML term field detected');
must($hosts['other-tour-operator.example']['severity']==='high','Further reading internal by default');
must(!isset($hosts['wayfatours.com']),'first-party URL allowed');
must(isset($hosts['museum.example'])&&$hosts['museum.example']['severity']==='medium','external citation review-only');
must(!MAD4B_SCP_Brand_Integrity::analyze_links('<a href="https://museum.example/source">Museum study</a>','post_content',$brand['scope']),'ordinary editorial citations stay allowed');
must(MAD4B_SCP_Brand_Integrity::scan_term_report(999,$brand)['complete']===false,'missing term fails closed');
$batch=MAD4B_SCP_Brand_Integrity::scan_term_batch(array('destinations'),0,1,$brand);
must($batch['scanned']===1&&!$batch['pagination_complete']&&$batch['next_offset']===1,'bounded census first page');
$second=MAD4B_SCP_Brand_Integrity::scan_term_batch(array('destinations'),1,1,$brand);
must($second['scanned']===1&&$second['pagination_complete']&&$second['complete'],'WPML translation batch complete');
must($second['issues']===array(),'second translated term has first-party URL only');
must(!MAD4B_SCP_Brand_Integrity::scan_term_batch(array('private_tax'),0,5,$brand)['complete'],'private taxonomy fails closed');
$original=get_term_meta(665,'destination_contents',true);
$unchanged=MAD4B_SCP_Brand_Integrity::term_meta_candidate_preflight(665,'destination_contents',$original,$brand);
must(!is_wp_error($unchanged)&&$unchanged['allowed']&&!$unchanged['mutation_performed'],'unchanged historic issue remains repairable');
$bad=array('further_reading'=>array(array('title'=>'Cairo Tours','url'=>'https://unapproved-operator.example/cairo')));
must(is_wp_error(MAD4B_SCP_Brand_Integrity::term_meta_candidate_preflight(665,'destination_contents',$bad,$brand)),'new external promotional field blocked');
$good=array('further_reading'=>array(array('url'=>'https://wayfatours.com/es/cairo')));
must(!is_wp_error(MAD4B_SCP_Brand_Integrity::term_meta_candidate_preflight(666,'destination_contents',$good,$brand)),'first party WPML term accepted');
$editorial=array('editorial_reference'=>array('url'=>'https://museum.example/research'));
$advisory=MAD4B_SCP_Brand_Integrity::term_meta_candidate_preflight(665,'destination_contents',$editorial,$brand);
must(!is_wp_error($advisory)&&$advisory['review_issues']===1,'editorial external citation review only');
must(is_wp_error(MAD4B_SCP_Brand_Integrity::term_meta_candidate_preflight(665,'destination_contents',$bad,array('scope'=>array()))),'missing identity denied');
must(is_wp_error(MAD4B_SCP_Brand_Integrity::term_meta_candidate_preflight(999,'destination_contents',$bad,$brand)),'missing term denied');

$neutral_external='<ul><li><a href="https://foreign-operator.example/cairo">Learn more</a></li></ul>';
$neutral=MAD4B_SCP_Brand_Integrity::analyze_links(
    $neutral_external,'termmeta.internal_links.0',$brand['scope']);
must(count($neutral)===1&&$neutral[0]['confidence']==='high',
    'internal_links requires first-party ownership even with neutral anchor text');
must(is_wp_error(MAD4B_SCP_Brand_Integrity::term_meta_candidate_preflight(
    665,'internal_links',$neutral_external,$brand)),
    'governed preflight blocks neutral-label external internal-link');
$unstable='<a href="/tours-and-activities/jsf/jet-engine:tours_query_archive/tax/destinations:cairo/">Cairo Tours</a>';
must(is_wp_error(MAD4B_SCP_Brand_Integrity::term_meta_candidate_preflight(
    665,'internal_links',$unstable,$brand)),
    'root-relative colon-bearing JetSmartFilters href rejected before save');
$canonical='<a href="/destination/africa-containent/egypt/cairo/">Cairo Tours</a>';
$canonical_result=MAD4B_SCP_Brand_Integrity::term_meta_candidate_preflight(
    665,'internal_links',$canonical,$brand);
must(!is_wp_error($canonical_result)&&$canonical_result['allowed']===true,
    'canonical relative destination link accepted');

$media=MAD4B_SCP_Brand_Integrity::scan_term_media_origin_report(665,$brand);
must($media['complete']&&$media['media_urls_scanned']===3,
 'CSV gallery URLs are individually counted plus featured image');
must($media['foreign_origin_count']===3 &&
 $media['local_file_missing_count']===3,'raw foreign media origins are not inferred as present');
must($media['mutation_performed']===false&&count($media['samples'])===3,
 'media audit never mutates or silently rewrites URLs');
must(MAD4B_SCP_Brand_Integrity::scan_term_media_origin_report(999,$brand)['complete']===false,
 'media audit unknown term fails closed');
must(MAD4B_SCP_Brand_Integrity::scan_term_media_origin_report(665,array('scope'=>array()))['complete']===false,
 'media audit requires company identity');
echo "PASS: BRAND_DESTINATION_TERM_LINKS\n";
