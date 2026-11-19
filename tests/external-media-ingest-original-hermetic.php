<?php
/** Original source external image ingestion: plans and denial paths only.
 * Never downloads images, creates attachment files or invokes the real network.
 */
define('ABSPATH',__DIR__.'/');
class WP_Error {
 private $code;
 function __construct($code,$message=''){$this->code=$code;}
 function get_error_code(){return $this->code;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_parse_url($url){return parse_url($url);}
function wp_http_validate_url($url){return $GLOBALS['url_validation']??$url;}
function home_url($path='/'){return 'https://site.example.test'.$path;}
function absint($v){return abs((int)$v);}
function get_post($id){return array_key_exists('post_source',$GLOBALS)?$GLOBALS['post_source']:(object)['ID'=>$id];}
function current_user_can($cap,...$args){return $GLOBALS['permissions'][$cap]??false;}
function get_posts($q){$GLOBALS['get_posts_count']++;return $GLOBALS['existing']??[];}
function wp_get_environment_type(){return 'staging';}
function get_current_user_id(){return 11;}
class MAD4B_SCP_Site_Profile {
 static $enrolled=true;
 static function user_is_enrolled($id){return self::$enrolled;}
}
function check_ingest($v,$why){
 if(!$v){fwrite(STDERR,'FAIL: '.$why.PHP_EOL);exit(1);}
 $GLOBALS['checks']=($GLOBALS['checks']??0)+1;
}
$GLOBALS['get_posts_count']=0;
$GLOBALS['permissions']=['upload_files'=>true,'edit_post'=>true];
$GLOBALS['url_validation']=null;
$GLOBALS['existing']=[112];
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-external-media-ingest.php';
$c='MAD4B_SCP_External_Media_Ingest';
$valid=['source_url'=>'https://media.example.test/egypt.jpg',
 'operation_key'=>'licensed-image-2026-01','parent_post_id'=>44,
 'operator_responsibility_accepted'=>true,'confirmed'=>true];
$plan=$c::plan($valid);
check_ingest(is_array($plan)&&$plan['read_only']===true
 &&$plan['existing_attachment_id']===112
 &&$plan['mutation_performed']===false
 &&strlen($plan['scope_sha256'])===64,
 'Original valid licensed image plan stays read-only with exact idempotent attachment');
$unattached=$c::plan(array_replace($valid,['parent_post_id'=>0]));
check_ingest(is_array($unattached)&&$unattached['parent_post_id']===0,
 'Original unattached WordPress media import scope remains supported with explicit post ID zero');
$valid['expected_scope_sha256']=$plan['scope_sha256'];
$existing_receipt=$c::apply($valid);
check_ingest(is_array($existing_receipt)&&$existing_receipt['already_imported']===true
 &&$existing_receipt['mutation_performed']===false,
 'Exact original verified existing attachment never reimports an image');
foreach([new WP_Error('url_untrusted'),[],true,null,1] as $unknown){
 $n=$GLOBALS['get_posts_count'];
 $payload=array_replace($valid,['source_url'=>$unknown]);
 check_ingest(is_wp_error($c::plan($payload))&&$GLOBALS['get_posts_count']===$n,
  'Error-shaped or non-string external image URL cannot be passed to HTTP validation or imported');
}
foreach([new WP_Error('key_untrusted'),[],true,null,1] as $unknown){
 $n=$GLOBALS['get_posts_count'];
 check_ingest(is_wp_error($c::plan(array_replace($valid,['operation_key'=>$unknown])))
  &&$GLOBALS['get_posts_count']===$n,
  'Unknown idempotency operation key cannot be cast into a scoped import');
}
foreach([new WP_Error('id_untrusted'),[],true,'44junk',-1] as $unknown){
 $n=$GLOBALS['get_posts_count'];
 check_ingest(is_wp_error($c::plan(array_replace($valid,['parent_post_id'=>$unknown])))
  &&$GLOBALS['get_posts_count']===$n,
  'Invalid original WordPress post identity cannot inherit a foreign write permission');
}
foreach([new WP_Error('post_unknown'),(object)['ID'=>99],null,false] as $unknown){
 $GLOBALS['post_source']=$unknown;
 check_ingest(is_wp_error($c::plan($valid)),
  'An unverified original WordPress post response cannot become valid editing scope');
}
unset($GLOBALS['post_source']);
foreach([new WP_Error('editing_unknown'),1,'true',null] as $unknown){
 $GLOBALS['permissions']['edit_post']=$unknown;
 check_ingest(is_wp_error($c::plan($valid)),
  'Only exact true from WordPress edit_post permits an image import plan');
}
$GLOBALS['permissions']['edit_post']=true;
foreach([new WP_Error('upload_unknown'),1,'true',null] as $unknown){
 $GLOBALS['permissions']['upload_files']=$unknown;
 check_ingest(is_wp_error($c::apply($valid)),
  'Only exact true WordPress upload_files permission allows image attachment operations');
}
$GLOBALS['permissions']['upload_files']=true;
foreach([new WP_Error('enrollment_unknown'),1,'true',null] as $unknown){
 MAD4B_SCP_Site_Profile::$enrolled=$unknown;
 check_ingest(is_wp_error($c::apply($valid)),
  'Original operator enrollment requires exact true even on staging');
}
MAD4B_SCP_Site_Profile::$enrolled=true;
foreach([new WP_Error('acceptance_unknown'),1,'true',null] as $unknown){
 check_ingest(is_wp_error($c::apply(array_replace($valid,['operator_responsibility_accepted'=>$unknown]))),
  'Operator responsibility must be an actual explicit boolean true, not a truthy source error');
}
foreach([new WP_Error('confirmation_unknown'),1,'true',null] as $unknown){
 check_ingest(is_wp_error($c::apply(array_replace($valid,['confirmed'=>$unknown]))),
  'Original exact plan confirmation must be boolean true before import');
}
foreach([new WP_Error('scope_unknown'),[],true,null,1] as $unknown){
 check_ingest(is_wp_error($c::apply(array_replace($valid,['expected_scope_sha256'=>$unknown]))),
  'Error-shaped expected source checksum never authorizes media write');
}
foreach([new WP_Error('external_url_unknown'),'true',1] as $unknown){
 $GLOBALS['url_validation']=$unknown;
 check_ingest($c::url_allowed($valid['source_url'])===false,
  'Original WordPress SSRF URL validator must return an actual validated URL');
}
$GLOBALS['url_validation']=null;
$final_plan=$c::plan($valid);
if(is_wp_error($final_plan))fwrite(STDERR,'FINAL_PLAN_ERROR='.$final_plan->get_error_code().PHP_EOL);
check_ingest(is_array($final_plan)&&$final_plan['scope_sha256']===$plan['scope_sha256'],
 'Exact native plan identity remains stable after all failed admission attempts');
echo 'PASS: EXTERNAL_MEDIA_INGEST_ORIGINAL '.($GLOBALS['checks']??0).
 ' source URL, WordPress permissions, operator attestation and no-import cases'.PHP_EOL;
