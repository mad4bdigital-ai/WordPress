<?php
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['aci02_abilities']=array();
function add_action($a,$b,$c=10){}
function wp_has_ability($id){return false;}
function wp_register_ability($id,$v){$GLOBALS['aci02_abilities'][$id]=$v;}
function is_wp_error($x){return $x instanceof WP_Error;}
class WP_Error { public $code; function __construct($c){$this->code=$c;} }
final class MAD4B_SCP_Policy { public static $read=true; public static function can_read(){return self::$read;} }
final class MAD4B_SCP_Site_Profile {
    public static $configured=true;
    public static function configured(){return self::$configured;}
    public static function site_uuid(){return '123e4567-e89b-42d3-a456-426614174000';}
    public static function site_origin(){return 'https://staging.example.org';}
    public static function current_environment(){return 'staging';}
}
final class MAD4B_SCP_Content_Jobs {
    public static function get_job($input){return array('job'=>array(
        'job_id'=>$input['job_id'],'brand_id'=>'brand_a','language'=>'ar','country'=>'EG','content_type'=>'article'));}
}
final class MAD4B_SCP_Context_Pack {
    public static function preview($input){return array('job_id'=>$input['job_id'],'brand_id'=>'brand_a',
        'language'=>'ar','country'=>'EG','ready'=>false,
        'missing_required_classes'=>array('brand.core','voice.language'),
        'job_requirements_sha256'=>str_repeat('a',64),'context_pack_sha256'=>str_repeat('b',64),
        'source_assets'=>array(array('excerpt'=>'sensitive never output')));}
}
final class MAD4B_SCP_Artifacts {
    public static function get_artifact($input){return array('artifact'=>array(
        'artifact_id'=>$input['artifact_id'], 'job_id'=>'11111111-1111-4111-8111-111111111111',
        'artifact_type'=>'serp_research','status'=>'active',
        'payload'=>array('provider_id'=>'fixture-serp','request_fingerprint'=>str_repeat('d',64),
            'collected_at'=>'2026-10-01T10:00:00Z','source_refs'=>array('site:page'),
            'normalized_data'=>array('raw_html'=>'private text never output'))));}
}
require_once __DIR__ . '/../includes/class-mad4b-scp-aci01-evidence-preview.php';
function check($ok,$msg){if(!$ok){fwrite(STDERR,'FAIL '.$msg."\n");exit(1);}}
$service='MAD4B_SCP_ACI01_Evidence_Preview';
$job='11111111-1111-4111-8111-111111111111';
$art='22222222-2222-4222-8222-222222222222';
$scope=array('site_uuid'=>MAD4B_SCP_Site_Profile::site_uuid(),'origin'=>MAD4B_SCP_Site_Profile::site_origin(),'environment'=>'staging');
$service::boot();$service::register_ability();
$d=$GLOBALS['aci02_abilities']['mad4b/aci01-evidence-preview'];
check($d['meta']['annotations']['readonly']===true&&$d['meta']['mcp']['surface']==='read','ability registration');
check($d['input_schema']['additionalProperties']===false,'input schema');
$r=$service::preview(array('job_id'=>$job,'artifact_ids'=>array($art)));
check($r['status']==='NEEDS_EVIDENCE','never auto ready');
check(in_array('required_brand_context_missing',$r['reason_codes'],true),'brand context gap');
check(in_array('research_usage_rights_not_independently_verified',$r['reason_codes'],true),'rights gap');
check(isset($r['context']['context_digest'])&&count($r['evidence_summaries'])===1,'summaries');
check(strpos(json_encode($r),'sensitive')===false&&strpos(json_encode($r),'private text')===false,'no raw leak');
check($r['authorizing']===false&&$r['mutation_performed']===false&&$r['paid_calls']===0,'no side effects');
check($r['preview_sha256']===$service::preview(array('job_id'=>$job,'artifact_ids'=>array($art)))['preview_sha256'],'determinism');
check($service::preview(array('job_id'=>'invalid'))['status']==='DENIED','job invalid');
check($service::preview(array('job_id'=>$job,'new_grant'=>true))['status']==='DENIED','unknown input');
check($service::preview(array('job_id'=>$job,'artifact_ids'=>array_fill(0,13,$art)))['status']==='DENIED','artifact bounded');
check($service::preview(array('job_id'=>$job,'artifact_ids'=>array($art,$art)))['status']==='DENIED','duplicate refs');
check($service::preview(array('job_id'=>$job,'max_age_seconds'=>604801))['status']==='DENIED','ttl upper');
MAD4B_SCP_Policy::$read=false;
check($service::preview(array('job_id'=>$job))['status']==='DENIED','permission revoked');
MAD4B_SCP_Policy::$read=true;
MAD4B_SCP_Site_Profile::$configured=false;
check($service::preview(array('job_id'=>$job))['status']==='DENIED','unenrolled');
MAD4B_SCP_Site_Profile::$configured=true;
$jobRow=MAD4B_SCP_Content_Jobs::get_job(array('job_id'=>$job))['job'];
$ctx=MAD4B_SCP_Context_Pack::preview(array('job_id'=>$job));
$a=MAD4B_SCP_Artifacts::get_artifact(array('artifact_id'=>$art))['artifact'];
$p=$service::project($job,$jobRow,$ctx,array($a),604800,$scope,strtotime('2026-10-01T11:00:00Z'));
check($p['evidence_summaries'][0]['freshness_valid']===true,'fresh expected');
$p=$service::project($job,$jobRow,$ctx,array($a),604800,$scope,strtotime('2026-10-09T11:00:00Z'));
check(in_array('research_evidence_expired_or_future',$p['reason_codes'],true),'expired denied');
$a['job_id']='33333333-3333-4333-8333-333333333333';
check($service::project($job,$jobRow,$ctx,array($a),604800,$scope)['status']==='DENIED','cross job');
$a['job_id']=$job;$a['payload']['source_refs']=array();
check(in_array('research_source_refs_missing',$service::project($job,$jobRow,$ctx,array($a),604800,$scope)['reason_codes'],true),'source references gap');
$ctx['brand_id']='other';
check($service::project($job,$jobRow,$ctx,array(),604800,$scope)['status']==='DENIED','cross brand context');
$ctx['brand_id']='brand_a';$ctx['ready']=true;$ctx['missing_required_classes']=array();
check($service::project($job,$jobRow,$ctx,array(),604800,$scope)['status']==='NEEDS_EVIDENCE','read observed cannot grant');
echo "ACI01_EVIDENCE_READ_CONTRACT: PASS\n";
