<?php
define( 'ABSPATH', __DIR__ );
$GLOBALS['aci01_registered'] = array();
function add_action($hook,$callback,$priority=10){ $GLOBALS['aci01_hook']=array($hook,$callback,$priority); }
function wp_has_ability($name){return false;}
function wp_register_ability($name,$definition){$GLOBALS['aci01_registered'][$name]=$definition;}
final class MAD4B_SCP_Policy { public static $read=true; public static function can_read(){return self::$read;} }
final class MAD4B_SCP_Site_Profile {
    public static $configured=true;
    public static function configured(){return self::$configured;}
    public static function site_uuid(){return '123e4567-e89b-42d3-a456-426614174000';}
    public static function site_origin(){return 'https://staging.example.org';}
    public static function current_environment(){return 'staging';}
}
class WP_Error { public function __construct( $code, $message='', $context=array() ) {} }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
final class MAD4B_SCP_ACI01_Runtime_Binding {
    public static $drift = false;
    public static function current() {
        if ( ! MAD4B_SCP_Site_Profile::$configured ) return new WP_Error('site_unconfigured');
        return array( 'contract'=>'mad4b.aci01.runtime-binding.v1',
            'site_uuid'=>MAD4B_SCP_Site_Profile::site_uuid(),
            'origin'=>MAD4B_SCP_Site_Profile::site_origin(),
            'environment'=>MAD4B_SCP_Site_Profile::current_environment(),
            'runtime_generation'=>str_repeat(self::$drift?'e':'a',64), 'restore_epoch'=>1 );
    }
    public static function is_valid( $binding ) { return is_array( $binding ) && isset( $binding['restore_epoch'] ); }
    public static function same( $a, $b ) { return self::is_valid($a) && self::is_valid($b) && $a===$b; }
}
final class MAD4B_SCP_Content_Experience_Profiles {
    public static function discover($input=array()){return array('post_types'=>array(
        array('post_type'=>'post','show_ui'=>true,'can_create'=>true,'can_publish'=>false,'taxonomies'=>array()),
        array('post_type'=>'excursion','show_ui'=>true,'can_create'=>true,'can_publish'=>false,'taxonomies'=>array(array('name'=>'region'),array('name'=>'tour_type'))),
        array('post_type'=>'private_internal','show_ui'=>false,'can_create'=>false,'can_publish'=>false,'taxonomies'=>array()),
    ));}
}
require_once __DIR__.'/../includes/class-mad4b-scp-aci01-intake-preview.php';
function check($truth,$msg){if(!$truth){fwrite(STDERR,"FAIL: $msg\n");exit(1);}}
$cls='MAD4B_SCP_ACI01_Intake_Preview';
$cls::boot();
check($GLOBALS['aci01_hook'][0]==='wp_abilities_api_init','hook');
$cls::register_ability();
$def=$GLOBALS['aci01_registered']['mad4b/aci01-intake-preview'];
check($def['meta']['annotations']['readonly']===true && $def['meta']['mcp']['surface']==='read','read annotation');
check($def['permission_callback']===array('MAD4B_SCP_Policy','can_read'),'registered policy');
check($def['input_schema']['additionalProperties']===false,'bounded input schema');
$input=array('post_type'=>'post','brand_id'=>'b1','locale'=>'ar','market'=>'EG');
$r=$cls::preview($input);
check($r['status']==='NEEDS_EVIDENCE','no forged readiness');
check($r['candidate']['content_recipe_key']==='native:post','native recipe');
check($r['candidate']['requires_native_relation_review']===false,'article relation optional');
check(!in_array('native_relation_review',array_column($r['stages'],'id'),true),'article skips native QA');
check($r['mutation_performed']===false && $r['authorizing']===false && $r['paid_calls']===0,'no effects');
check($r['available_post_types']===array('excursion','post'),'no inaccessible hidden CPT');
check($r['plan_fingerprint_sha256']===$cls::preview($input)['plan_fingerprint_sha256'],'determinism');
$tour=$cls::preview(array('post_type'=>'excursion','brand_id'=>'b1','locale'=>'ar-EG','market'=>'eg'));
check($tour['candidate']['requires_native_relation_review']===true,'native taxonomy proof');
check(in_array('native_relation_review',array_column($tour['stages'],'id'),true),'tour QA stage dynamically activated');
check(in_array('native_relation_policy_unverified',$tour['reason_codes'],true),'relation denial');
check($tour['candidate']['taxonomies']===array('region','tour_type'),'sorted taxonomy');
check($cls::preview(array())['status']==='NEEDS_REVIEW','missing target review');
check($cls::preview(array('post_type'=>'private_internal'))['status']==='DENIED','private invisible target denied');
check($cls::preview(array('post_type'=>'missing'))['status']==='DENIED','unknown target denied');
check($cls::preview(array('brand_id'=>array('oops')))['status']==='DENIED','type safety');
check($cls::preview(array('command'=>'write'))['status']==='DENIED','unknown input rejected');
check($cls::plan_from_discovery($input,array('site_uuid'=>MAD4B_SCP_Site_Profile::site_uuid(),'origin'=>'http://evil','environment'=>'staging'),MAD4B_SCP_Content_Experience_Profiles::discover())['status']==='DENIED','insecure origin');
check($cls::plan_from_discovery($input,array('site_uuid'=>MAD4B_SCP_Site_Profile::site_uuid(),'origin'=>'https://me:pw@example.org','environment'=>'staging'),MAD4B_SCP_Content_Experience_Profiles::discover())['status']==='DENIED','origin credentials denied');
$badInventory=array('post_types'=>array_fill(0,97,array('post_type'=>'post')));
check($cls::plan_from_discovery($input,array('site_uuid'=>MAD4B_SCP_Site_Profile::site_uuid(),'origin'=>'https://example.org','environment'=>'disposable'),$badInventory)['status']==='DENIED','bounded discovery');
MAD4B_SCP_Policy::$read=false;
check($cls::preview($input)['status']==='DENIED','revoked grant');
MAD4B_SCP_Policy::$read=true;
MAD4B_SCP_Site_Profile::$configured=false;
check($cls::preview($input)['status']==='DENIED','unenrolled site');
MAD4B_SCP_Site_Profile::$configured=true;
$multi=$cls::plan_from_discovery(
    array('post_type'=>'post','brand_id'=>'b1','locale'=>'ar','market'=>'EG'),
    array('site_uuid'=>MAD4B_SCP_Site_Profile::site_uuid(),
          'origin'=>MAD4B_SCP_Site_Profile::site_origin(),
          'environment'=>'local', 'multilingual_detected'=>true),
    MAD4B_SCP_Content_Experience_Profiles::discover());
check($multi['status']==='NEEDS_EVIDENCE','local environment supported');
check(in_array('native_relation_review',array_column($multi['stages'],'id'),true),
      'WPML detected even when taxonomy absent');
check(in_array('runtime_binding_unverified',$multi['reason_codes'],true),
      'unbound synthetic preview is not certified');
check($cls::preview($input)['scope']['binding']['restore_epoch']===1,
      'live preview carries exact restore epoch');
echo "ACI01_READONLY_INTAKE_CONTRACT: PASS (20+ assertions; no native writes)\n";
