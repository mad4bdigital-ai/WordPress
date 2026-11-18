<?php
/** Visual/SEO source evidence is a proposal, never an alt-text or media write. */
error_reporting(E_ALL);define('ABSPATH',__DIR__.'/');
class WP_Error {private $c;public function __construct($c,$m=''){$this->c=$c;}public function get_error_code(){return $this->c;}}
function is_wp_error($x){return $x instanceof WP_Error;}
function absint($x){return abs((int)$x);}function sanitize_title($x){return strtolower((string)$x);}
function get_post($id){return (int)$id===17?(object)['post_status'=>'publish','post_type'=>'tours-and-activities','post_title'=>'Luxor Nile Experience']:null;}
function get_the_terms($id,$taxonomy){return [];}
function apply_filters($hook,$default,...$args){return $default;}
class MAD4B_SCP_Site_Profile {static $ready=false;static function status(){return ['site_uuid'=>'source-fixture','origin_match'=>self::$ready,'deployment_binding_match'=>self::$ready,'environment_match'=>self::$ready,'environment'=>'staging'];}}
class MAD4B_SCP_Context_Authority {static function profile(){return ['brand_id'=>'brand','brand_name'=>'Tour Source'];}static function review_policy(){return ['mode'=>'human_only'];}}
class MAD4B_SCP_Brand_Experience_Quality {static function brand_context(){return ['authority_level'=>'provisional'];}}
class MAD4B_SCP_Provider_Field_Intelligence {static function locale_media_audit($id){return ['complete'=>false,'wpml_alt_translation_mode'=>'unconfigured'];}}
function expect($ok,$message){if(!$ok){fwrite(STDERR,"FAIL: $message\n");exit(1);}}
require dirname(__DIR__).'/includes/class-mad4b-scp-visual-seo-strategy.php';
$c='MAD4B_SCP_Visual_SEO_Strategy';
expect(count($c::catalog())===7,'Seven deterministic strategy plans');
$x=$c::inspect(0);expect(!$x['ready']&&$x['reason']==='source_or_role_invalid','Unknown content not ready');
$x=$c::inspect(17,'delete');expect(!$x['ready'],'Unsupported media role denied');
$x=$c::inspect(17);expect(!$x['ready']&&$x['reason']==='site_profile_unverified','Site binding required');
MAD4B_SCP_Site_Profile::$ready=true;$x=$c::inspect(17,'featured');
expect($x['ready']===true&&!$x['proposed_text_written']&&!$x['automatic_approval'],'Read-only strategy context never mutates');
$x=$c::evaluate([]);expect(is_wp_error($x)&&$x->get_error_code()==='mad4b_visual_strategy_input_contract_invalid','Strict input schema enforced');
$fields=['post_id'=>17,'media_role'=>'featured','destination_slug'=>'luxor','image_sha256'=>str_repeat('a',64),'gallery_sha256'=>str_repeat('b',64),'visual_representation'=>'photograph','observed_objects'=>['landmark'],'alt_drafts'=>['en'=>'A clearly identified photo of Luxor with verifiable historic monument context'],'market'=>'inherit'];
$x=$c::evaluate($fields);expect(is_wp_error($x)&&$x->get_error_code()==='mad4b_visual_strategy_context_not_ready','Incomplete WPML provider audit cannot approve visual publication');
$fields['media_role']='gallery';$x=$c::evaluate($fields);expect(is_wp_error($x)&&$x->get_error_code()==='mad4b_visual_strategy_role_not_implemented','Unsupported gallery role cannot be silently implemented');
echo "PASS: Visual SEO read-only catalog, strict identity/role/locale readiness and no alt/image write\n";
