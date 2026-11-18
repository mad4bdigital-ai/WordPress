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
function get_term($id) {return $id===665?new WP_Term():null;}
function get_term_meta($id) {return array('destination_contents'=>array(serialize(array(
 'further_reading'=>array(
  array('title'=>'Cairo Tours','url'=>'https://other-tour-operator.example/cairo-tours'),
  array('title'=>'Wayfa trip','url'=>'https://wayfatours.com/tours/cairo'),
  array('title'=>'Book source','content'=>'<a href="https://another-operator.example/book">Cairo Tours</a>')
 ),
 'editorial_reference'=>array('url'=>'https://museum.example/research')
))));}
function maybe_unserialize($v) {return is_string($v)&&preg_match('/^a:\d+:/',$v)?unserialize($v):$v;}
function get_bloginfo($key) {return 'Wayfa Tours';}
function home_url($path='/') {return 'https://wayfatours.com'.$path;}
function wp_parse_url($url,$component=-1) {return parse_url($url,$component);}
function wp_strip_all_tags($s) {return strip_tags($s);}
function remove_accents($s) {return $s;}
function wp_json_encode($v) {return json_encode($v);}
function apply_filters($n,$v) {return $v;}
function get_taxonomies($args=array(),$output='names') {return array('destinations');}
function get_terms($args=array()) {return array('Egypt');}
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
must($hosts['other-tour-operator.example']['severity']==='high','Further reading internal by default');
must(!isset($hosts['wayfatours.com']),'first-party URL allowed');
must(isset($hosts['museum.example'])&&$hosts['museum.example']['severity']==='medium','external citation review-only');
must(!MAD4B_SCP_Brand_Integrity::analyze_links('<a href="https://museum.example/source">Museum study</a>','post_content',$brand['scope']),'ordinary editorial citations stay allowed');
must(MAD4B_SCP_Brand_Integrity::scan_term_report(999,$brand)['complete']===false,'missing term fails closed');
echo "BRAND_DESTINATION_TERM_LINKS: PASS\n";
