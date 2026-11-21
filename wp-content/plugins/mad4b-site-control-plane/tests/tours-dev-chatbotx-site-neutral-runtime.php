<?php
/** ChatbotX custom-post-type writer defaults to deny, never travel CPT presets. */
error_reporting(E_ALL);define('ABSPATH',__DIR__.'/');
class WP_Error {private $code;function __construct($code,$message=''){$this->code=$code;}function get_error_code(){return $this->code;}}
function is_wp_error($x){return $x instanceof WP_Error;}
class MAD4B_SCP_Site_Profile {
    public static $ready=false;
    public static function origin_enrolled(){return self::$ready;}
    public static function site_urls_match_enrollment(){return self::$ready;}
}
$GLOBALS['configured_cpts']=array();
function apply_filters($name,$default){
    if($name!=='mad4b_scp_chatbotx_draft_cpt_allowlist')return $default;
    return $GLOBALS['configured_cpts'];
}
function get_post_type_object($type){
    if($type==='post')return (object)array('_builtin'=>true,'cap'=>(object)array('create_posts'=>'edit_posts'));
    if($type==='custom_itinerary')return (object)array('_builtin'=>false,'cap'=>(object)array('create_posts'=>'edit_posts'));
    return null;
}
function ensure_policy($ok,$message){if(!$ok){fwrite(STDERR,'FAIL: '.$message."\n");exit(1);}}
require dirname(__DIR__).'/includes/adapters/class-mad4b-scp-chatbotx-write-preflight.php';
$c='MAD4B_SCP_ChatbotX_Write_Preflight';
ensure_policy($c::allowed_post_types()===array(),'No unenrolled site allowlist');
MAD4B_SCP_Site_Profile::$ready=true;
ensure_policy($c::allowed_post_types()===array(),'No implicit tenant business model');
$GLOBALS['configured_cpts']=array('post');
ensure_policy($c::allowed_post_types()===array(),'Built-in posts may not be silently exposed');
$GLOBALS['configured_cpts']=array('custom_itinerary');
ensure_policy($c::allowed_post_types()===array('custom_itinerary'),'Enrolled exact custom type opt-in');
$GLOBALS['configured_cpts']=array('custom_itinerary','custom_itinerary');
ensure_policy($c::allowed_post_types()===array('custom_itinerary'),'Repeated custom type deduplicated');
$GLOBALS['configured_cpts']=array('custom_itinerary','invalid_tenant_type');
ensure_policy($c::allowed_post_types()===array(),'An unknown configured CPT fails closed');
$GLOBALS['configured_cpts']=array('custom_itinerary',7);
ensure_policy($c::allowed_post_types()===array(),'Non-string configuration fails closed');
$GLOBALS['configured_cpts']=array('0'=>'custom_itinerary');
ensure_policy($c::allowed_post_types()===array('custom_itinerary'),'Numeric list accepted');
$GLOBALS['configured_cpts']=array('name'=>'custom_itinerary');
ensure_policy($c::allowed_post_types()===array(),'Associative configuration fails closed');
$GLOBALS['configured_cpts']=array_fill(0,13,'custom_itinerary');
ensure_policy($c::allowed_post_types()===array(),'Oversize configuration fails closed');
$base=dirname(__DIR__).'/includes/adapters/';
$adapter=file_get_contents($base.'class-mad4b-scp-chatbotx-provider-adapter.php');
$writer=file_get_contents($base.'class-mad4b-scp-chatbotx-governed-writes.php');
ensure_policy(strpos($adapter,'if (MAD4B_SCP_ChatbotX_Write_Preflight::allowed_post_types())')!==false,
    'CPT write ability is not registered without explicit opt-in');
ensure_policy(strpos($writer,'MAD4B_SCP_ChatbotX_Write_Preflight::allowed_post_types()')!==false,
    'Writer must recheck the enrolled CPT allowlist');
echo "PASS: ChatbotX fail-closed site-neutral CPT policy, no business default or unapproved draft writer\n";
