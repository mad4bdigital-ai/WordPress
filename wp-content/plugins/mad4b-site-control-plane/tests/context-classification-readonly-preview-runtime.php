<?php
/** Exact site-bound Context classification preview: no writes or grants. */
define('ABSPATH', __DIR__.'/');
class WP_Error {
    private $code;
    public function __construct($code,$message=''){ $this->code=$code; }
    public function get_error_code(){return $this->code;}
}
function is_wp_error($value){return $value instanceof WP_Error;}
function current_user_can($name){return $name==='manage_options' && $GLOBALS['preview_admin'];}
function sanitize_text_field($value){return trim(strip_tags((string)$value));}
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
function get_option($name,$default=false){return $GLOBALS['preview_options'][$name]??$default;}
function update_option(){++$GLOBALS['preview_writes'];throw new Exception('Preview wrote option');}
function add_option(){++$GLOBALS['preview_writes'];throw new Exception('Preview added option');}
function delete_option(){++$GLOBALS['preview_writes'];throw new Exception('Preview deleted option');}
function preview_assert($ok,$message){if(!$ok){fwrite(STDERR,'FAIL: '.$message.PHP_EOL);exit(1);}++$GLOBALS['checks'];}
class MAD4B_SCP_Site_Profile {
    public static $enrolled=true;
    public static function status(){
        return array('configured'=>true,'origin_match'=>self::$enrolled,
            'environment_match'=>self::$enrolled,
            'site_uuid'=>$GLOBALS['preview_site'],'environment'=>'staging');
    }
}
class MAD4B_SCP_Operational_Scope_Guard {
    public static $bound=true;
    public static function require_current(){
        return self::$bound ? array('site_uuid'=>$GLOBALS['preview_site'],
            'brand_ref'=>$GLOBALS['preview_brand']) : new WP_Error('mad4b_operational_scope_not_ready');
    }
    public static function record_metadata_matches($record,$scope){
        return ($record['site_uuid']??'')===$scope['site_uuid']
            && ($record['brand_id']??'')===$scope['brand_ref']
            && ($record['scoped']??true)!==false;
    }
}
$GLOBALS['preview_admin']=true;$GLOBALS['preview_writes']=0;$GLOBALS['checks']=0;
$GLOBALS['preview_site']='12345678-1234-4123-8123-123456789abc';
$GLOBALS['preview_brand']=str_repeat('b',32);
$src='drive-source-01';
$profile=array('contract'=>'mad4b.brand-context-profile.v1',
    'site_uuid'=>$GLOBALS['preview_site'],'brand_id'=>$GLOBALS['preview_brand'],
    'brand_name'=>'Test Brand','revision'=>1);
$source=array('contract'=>'mad4b.context-source.v1',
    'source_id'=>$src,'site_uuid'=>$GLOBALS['preview_site'],
    'brand_id'=>$GLOBALS['preview_brand'],'mode'=>'governed',
    'status'=>'partial_scan','provider'=>'google_drive',
    'external_root_id'=>'brand-folder-01','last_scan_generation'=>'scan-001');
$base=array('contract'=>'mad4b.context-asset.v1',
    'source_id'=>$src,'source_mode'=>'governed','site_uuid'=>$GLOBALS['preview_site'],
    'brand_id'=>$GLOBALS['preview_brand'],'file_id'=>'drive-file-01',
    'title'=>'Brand Strategy','path'=>'brand-strategy.pdf',
    'category'=>'uncategorized','authority_class'=>'reference','priority'=>40,
    'required'=>false,'classification_confidence'=>0.35,
    'classification_source'=>'automatic_heuristic',
    'review_status'=>'unreviewed','reviewed_content_hash'=>'','reviewed_at'=>'',
    'content_complete'=>true,'content_hash'=>str_repeat('a',64));
$auto=$base;$auto['asset_id']='asset-automatic';
$reviewed=$base;$reviewed['asset_id']='asset-reviewed';
$reviewed['file_id']='drive-file-02';
$reviewed['review_status']='approved';$reviewed['classification_source']='manual';
$reviewed['reviewed_content_hash']=str_repeat('c',64);$reviewed['reviewed_at']='2026-11-01T00:00:00Z';
$GLOBALS['preview_options']=array(
 'mad4b_scp_brand_context_profile_v1'=>$profile,
 'mad4b_scp_context_sources_v1'=>array($src=>$source),
 'mad4b_scp_context_assets_v1'=>array('asset-reviewed'=>$reviewed,'asset-automatic'=>$auto),
 'mad4b_scp_context_registry_revision_v1'=>3);
require dirname(__DIR__).'/includes/class-mad4b-scp-context-authority.php';
$c='MAD4B_SCP_Context_Authority';
preview_assert(!method_exists($c,'classification_reconcile_apply'),
    'Reviewed preview must not restore native WP-CLI write or approval handler');
$GLOBALS['preview_admin']=false;
$x=$c::classification_reconcile_plan($src);
preview_assert(is_wp_error($x)&&$x->get_error_code()==='mad4b_reconcile_preview_admin_required',
    'Nonadministrator cannot enumerate classification changes');
$GLOBALS['preview_admin']=true;
MAD4B_SCP_Site_Profile::$enrolled=false;
$x=$c::classification_reconcile_plan($src);
preview_assert(is_wp_error($x)&&$x->get_error_code()==='mad4b_context_site_profile_not_enrolled',
    'Wrong origin or deployment environment denied before source preview');
MAD4B_SCP_Site_Profile::$enrolled=true;
MAD4B_SCP_Operational_Scope_Guard::$bound=false;
$x=$c::classification_reconcile_plan($src);
preview_assert(is_wp_error($x)&&$x->get_error_code()==='mad4b_reconcile_preview_scope_unverified',
    'Missing exact operational scope cannot enumerate assets');
MAD4B_SCP_Operational_Scope_Guard::$bound=true;
$x=$c::classification_reconcile_plan('foreign-source');
preview_assert(is_wp_error($x)&&$x->get_error_code()==='mad4b_reconcile_source_not_eligible',
    'Foreign governed source denied');
$x=$c::classification_reconcile_plan($src);
preview_assert(is_array($x)&&$x['read_only']===true
    &&$x['mutation_authorized']===false&&$x['native_apply_available']===false,
    'Approved source only returns a nonauthorizing preview');
preview_assert($x['source_asset_count']===2&&$x['blocked_reviewed_count']===1
    &&count($x['changes'])===1&&$x['changes'][0]['asset_id']==='asset-automatic',
    'Reviewed assets remain excluded; one automatic source change is previewed');
preview_assert($x['changes'][0]['after']['category']==='brand_strategy'
    &&$x['changes'][0]['after']['required']===true
    &&$x['changes'][0]['after']['priority']===100,
    'Classification preview uses current unapproved title classifier');
$sha=$x['plan_sha256'];
preview_assert(preg_match('/^[a-f0-9]{64}$/D',$sha)===1
    &&$c::classification_reconcile_plan($src)['plan_sha256']===$sha,
    'Preview sorted and content-addressed, stable without state changes');
preview_assert($GLOBALS['preview_writes']===0,'Read-only preview may not update WordPress options');
$foreign=$auto;$foreign['site_uuid']='00000000-0000-4000-8000-000000000000';
$GLOBALS['preview_options']['mad4b_scp_context_assets_v1']['asset-automatic']=$foreign;
$x=$c::classification_reconcile_plan($src);
preview_assert(is_wp_error($x)&&$x->get_error_code()==='mad4b_reconcile_asset_scope_invalid',
    'Mixed source ID cannot pull foreign-company assets into a preview');
$foreign=$auto;$foreign['brand_id']=str_repeat('c',32);
$GLOBALS['preview_options']['mad4b_scp_context_assets_v1']['asset-automatic']=$foreign;
$x=$c::classification_reconcile_plan($src);
preview_assert(is_wp_error($x)&&$x->get_error_code()==='mad4b_reconcile_asset_scope_invalid',
    'Mixed brand assets rejected rather than silently adopted');
$GLOBALS['preview_options']['mad4b_scp_context_assets_v1']['asset-automatic']=$auto;
$GLOBALS['preview_options']['mad4b_scp_context_sources_v1'][$src]['status']='published';
$x=$c::classification_reconcile_plan($src);
preview_assert(is_wp_error($x)&&$x->get_error_code()==='mad4b_reconcile_source_not_eligible',
    'Only approved partial scan state eligible, no automatic reclassification of live source');
preview_assert($GLOBALS['preview_writes']===0,'All branches are nonmutating');
echo 'PASS: CONTEXT_CLASSIFICATION_READONLY_PREVIEW '.($GLOBALS['checks']??0).' scoped/no-write assertions'.PHP_EOL;
