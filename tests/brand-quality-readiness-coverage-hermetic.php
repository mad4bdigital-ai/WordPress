<?php
/**
 * Load original Brand Experience and original Content Readiness classes.
 * Review only source-scoped brand evidence and exact issue-ledger closure
 * eligibility. No WordPress DB, email, ContentJob creation or publication.
 */
define('ABSPATH',__DIR__.'/');
function wp_strip_all_tags($v){return strip_tags($v);}
function absint($v){return abs((int)$v);}
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
function get_option($key,$default=array()){return $default;}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function get_current_blog_id(){return 1;}
function get_post_meta($id,$key,$single=false){return array();}
class MAD4B_SCP_Context_Authority {
 public static $context=array();
 public static $assets=array();
 public static function profile(){return self::$context;}
 public static function assets(){return self::$assets;}
}
$root=dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/';
require $root.'class-mad4b-scp-brand-experience-quality.php';
require $root.'class-mad4b-scp-content-readiness-alerts.php';
function brand_assert($ok,$why){
 if(!$ok)throw new RuntimeException('FAIL BRAND COVERAGE: '.$why);
}
$br='MAD4B_SCP_Brand_Experience_Quality';
$rd='MAD4B_SCP_Content_Readiness_Alerts';
$missing=$br::brand_context();
brand_assert($missing['authority_level']==='provisional'
 && $missing['positioning_evidence']==='not_established',
 'Missing exact Brand Context cannot become approved');
MAD4B_SCP_Context_Authority::$context=array(
 'site_uuid'=>'site-1','brand_id'=>'brand-A',
 'brand_name'=>'Demonstration Brand','revision'=>5,
 'status'=>'reviewed','context_fingerprint'=>str_repeat('a',64)
);
$hash=str_repeat('f',64);
MAD4B_SCP_Context_Authority::$assets=array(
 array('category'=>'brand_strategy','status'=>'ready','review_status'=>'approved',
  'content_complete'=>true,'content_hash'=>$hash,
  'reviewed_content_hash'=>$hash,'content_excerpt'=>'Premium private tours with a guide'),
 array('category'=>'tone_of_voice','status'=>'ready','review_status'=>'approved',
  'content_complete'=>true,'content_hash'=>$hash,
  'reviewed_content_hash'=>$hash,'content_excerpt'=>'Respectful guest-first writing'),
 array('category'=>'editorial_guidelines','status'=>'ready','review_status'=>'approved',
  'content_complete'=>true,'content_hash'=>$hash,
  'reviewed_content_hash'=>$hash,'content_excerpt'=>'Factual attractions only'),
);
$approved=$br::brand_context();
brand_assert($approved['authority_level']==='approved_brand_context'
 && $approved['positioning_evidence']==='approved_brand_documents'
 && $approved['scope']['site_uuid']==='site-1'
 && count($approved['approved_categories'])===3,
 'Brand Context approval requires three exact signed-current source categories');
MAD4B_SCP_Context_Authority::$assets[1]['reviewed_content_hash']=str_repeat('0',64);
$partial=$br::brand_context();
brand_assert($partial['authority_level']==='partial_approved_brand_context'
 && count($partial['approved_categories'])===2,
 'Changed tone source hash must remove its authority, not inherit stale review');

$method=new ReflectionMethod($rd,'rule_enabled_for');
if(PHP_VERSION_ID<80100)$method->setAccessible(true);
$issue=array(
 'source'=>'mad4b-brand-experience','code'=>'brand_identity_attribution_mismatch',
 'post_type'=>'tours-and-activities',
 'source_evidence'=>array('brand_scope'=>array('site_uuid'=>'site-1'))
);
$coverage=array('site_uuid'=>'site-1','complete'=>true,
 'coverage_complete'=>true,'scan_post_types'=>array('tours-and-activities'));
brand_assert($method->invoke(null,$issue,array(),$coverage)===true,
 'Exact same-site complete source coverage should permit historical resolution');
foreach(array(
 array_replace($coverage,array('complete'=>false)),
 array_replace($coverage,array('coverage_complete'=>false)),
 array_replace($coverage,array('site_uuid'=>'foreign-site')),
 array_replace($coverage,array('scan_post_types'=>array('page'))),
 array_replace($coverage,array('site_uuid'=>'')),
) as $invalid) {
 brand_assert($method->invoke(null,$issue,array(),$invalid)===false,
  'Incomplete, different-site or other post-type coverage closed historical issue');
}
$stale=$issue;$stale['source_evidence']['brand_scope']['site_uuid']='foreign-site';
brand_assert($method->invoke(null,$stale,array(),$coverage)===false,
 'Old or cross-site brand issue must remain open');
$linked=$issue;$linked['code']='brand_external_link_attribution_mismatch';
brand_assert($method->invoke(null,$linked,array(),$coverage)===true,
 'Both attribution issue classes must obey the same complete-scan contract');
brand_assert(!empty($partial['brand_evidence_sha256'])
 && $approved['brand_evidence_sha256']!==$partial['brand_evidence_sha256'],
 'Brand issue revision fingerprint must change when approved source becomes stale');
echo "BRAND_QUALITY_LEDGER_SOURCE_GUARD: PASS approval revision + scoped closure\n";
echo "PASS: brand quality exact context revision and same-site issue closure\n";
