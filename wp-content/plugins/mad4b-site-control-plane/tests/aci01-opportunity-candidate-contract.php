<?php
define('ABSPATH', __DIR__ . '/');
$GLOBALS['aci03_registered']=array();
function add_action($h,$cb,$p=10){}
function wp_has_ability($n){return false;}
function wp_register_ability($n,$d){$GLOBALS['aci03_registered'][$n]=$d;}
final class MAD4B_SCP_Policy { public static $read=true;public static function can_read(){return self::$read;} }
final class MAD4B_SCP_ACI01_Evidence_Preview {
    public static $denied=false;
    public static function preview($input) {
        if(self::$denied)return array('status'=>'DENIED');
        return array('status'=>'NEEDS_EVIDENCE','scope'=>array(
            'site_uuid'=>'123e4567-e89b-42d3-a456-426614174000',
            'origin'=>'https://example.org','environment'=>'staging',
            'brand_id'=>'b1','locale'=>'ar','market'=>'EG'),
            'preview_sha256'=>str_repeat('a',64),
            'reason_codes'=>array('context_not_ready','research_evidence_required'));
    }
}
final class MAD4B_SCP_ACI01_Intake_Preview {
    public static $relation=false;
    public static function preview($input) {
        return array('status'=>'NEEDS_EVIDENCE','scope'=>array(
            'site_uuid'=>'123e4567-e89b-42d3-a456-426614174000',
            'origin'=>'https://example.org','environment'=>'staging',
            'brand_id'=>$input['brand_id'],'locale'=>$input['locale'],'market'=>$input['market']),
            'candidate'=>array('post_type'=>$input['post_type'],
                'requires_native_relation_review'=>self::$relation),
            'plan_fingerprint_sha256'=>str_repeat('b',64),
            'reason_codes'=>array('governed_brand_and_source_receipts_not_supplied'));
    }
}
require __DIR__.'/../includes/class-mad4b-scp-aci01-opportunity-preview.php';
function check($ok,$why){if(!$ok){fwrite(STDERR,'FAIL '.$why."\n");exit(1);}}
$cls='MAD4B_SCP_ACI01_Opportunity_Preview';
$cls::boot();$cls::register_ability();
$def=$GLOBALS['aci03_registered']['mad4b/aci01-opportunity-preview'];
check($def['meta']['annotations']['readonly']===true&&$def['meta']['mcp']['surface']==='read','read only');
check($def['input_schema']['additionalProperties']===false,'schema bounded');
$input=array('job_id'=>'11111111-1111-4111-8111-111111111111','post_type'=>'post','goal'=>'Useful market guide');
$out=$cls::preview($input);
check($out['status']==='NEEDS_REVIEW','no auto approval');
check($out['candidate_kind']==='OpportunityHypothesis_BlueprintCandidate','candidate type');
check($out['target_post_type']==='post','exact target');
check($out['synthetic_candidate_only']===true&&$out['authorizing']===false,'non-authorizing');
check($out['mutation_performed']===false&&$out['provider_calls_performed']===false&&$out['paid_calls']===0,'no effects');
check(!in_array('native_relation_identity',$out['required_blueprint_sections'],true),'optional relation');
check(strpos(json_encode($out),'Useful market guide')===false,'no raw user goal echoed');
check(strlen($out['candidate_sha256'])===64,'content fingerprint');
check($out['candidate_sha256']===$cls::preview($input)['candidate_sha256'],'deterministic');
MAD4B_SCP_ACI01_Intake_Preview::$relation=true;
$tour=$cls::preview($input);
check(in_array('native_relation_identity',$tour['required_blueprint_sections'],true),'relation condition');
check($tour['candidate_sha256']!==$out['candidate_sha256'],'condition changes fingerprint');
MAD4B_SCP_Policy::$read=false;
check($cls::preview($input)['status']==='DENIED','revoked');
MAD4B_SCP_Policy::$read=true;
MAD4B_SCP_ACI01_Evidence_Preview::$denied=true;
check($cls::preview($input)['status']==='DENIED','denied evidence');
MAD4B_SCP_ACI01_Evidence_Preview::$denied=false;
check($cls::preview(array_merge($input,array('run_shell'=>true)))['status']==='DENIED','unknown field');
check($cls::preview(array_merge($input,array('goal'=>array())))['status']==='DENIED','invalid goal');
check($cls::compile(MAD4B_SCP_ACI01_Intake_Preview::preview($input),
    array_merge(MAD4B_SCP_ACI01_Evidence_Preview::preview($input),
        array('scope'=>array_merge(MAD4B_SCP_ACI01_Evidence_Preview::preview($input)['scope'],array('brand_id'=>'wrong')))),
    $input['goal'])['status']==='DENIED','cross-brand');
echo "ACI01_OPPORTUNITY_CANDIDATE: PASS\n";
