<?php
define('ABSPATH', __DIR__ . '/');
$GLOBALS['aci03_registered']=array();
function add_action($h,$cb,$p=10){}
function wp_has_ability($n){return false;}
function wp_register_ability($n,$d){$GLOBALS['aci03_registered'][$n]=$d;}
function is_wp_error($v){return $v instanceof WP_Error;}
class WP_Error{function __construct($code=''){ } }
final class MAD4B_SCP_ACI01_Runtime_Binding{
    public static function current(){return array('contract'=>'mad4b.aci01.runtime-binding.v1',
        'site_uuid'=>'123e4567-e89b-42d3-a456-426614174000',
        'origin'=>'https://example.org','environment'=>'staging',
        'runtime_generation'=>str_repeat('f',64),'restore_epoch'=>1);}
    public static function is_valid($b){return is_array($b)&&isset($b['restore_epoch']);}
    public static function same($a,$b){return self::is_valid($a)&&self::is_valid($b)&&$a===$b;}
}
final class MAD4B_SCP_Content_Experience_Profiles {
    public static function profile_status($input=array()){return array(
        'contract'=>'mad4b.content-experience-profiles.v1','profiles'=>array(
            array('slug'=>'article','post_type'=>'post','enabled'=>true,
                'runtime_post_type_ready'=>true,'helper_catalog_match'=>true,
                'authority_current'=>true,'migration_required'=>false,
                'revision'=>1,'authority_sha256'=>str_repeat('d',64)),
        ));}
}
final class MAD4B_SCP_Policy { public static $read=true;public static function can_read(){return self::$read;} }
final class MAD4B_SCP_ACI01_Evidence_Preview {
    public static $denied=false;
    public static function preview($input) {
        if(self::$denied)return array('status'=>'DENIED');
        return array('contract'=>'mad4b.aci01.evidence-preview.v1','authorizing'=>false,'mutation_performed'=>false,'status'=>'NEEDS_EVIDENCE','scope'=>array(
            'site_uuid'=>'123e4567-e89b-42d3-a456-426614174000',
            'origin'=>'https://example.org','environment'=>'staging',
            'brand_id'=>'b1','locale'=>'ar','market'=>'EG',
            'content_type'=>'article', 'binding'=>MAD4B_SCP_ACI01_Runtime_Binding::current()),
            'preview_sha256'=>str_repeat('a',64),
            'job_id'=>$input['job_id'],
            'evidence_summaries'=>array(),
            'reason_codes'=>array('context_not_ready','research_evidence_required'));
    }
}
final class MAD4B_SCP_ACI01_Intake_Preview {
    public static $relation=false;
    public static function preview($input) {
        return array('contract'=>'mad4b.aci01.intake-preview.v1','authorizing'=>false,'mutation_performed'=>false,'status'=>'NEEDS_EVIDENCE','scope'=>array(
            'site_uuid'=>'123e4567-e89b-42d3-a456-426614174000',
            'origin'=>'https://example.org','environment'=>'staging',
            'brand_id'=>$input['brand_id'],'locale'=>$input['locale'],'market'=>$input['market'],
            'binding'=>MAD4B_SCP_ACI01_Runtime_Binding::current()),
            'candidate'=>array('post_type'=>$input['post_type'],
                'requires_native_relation_review'=>self::$relation),
            'plan_fingerprint_sha256'=>str_repeat('b',64),
            'reason_codes'=>array('governed_brand_and_source_receipts_not_supplied'));
    }
}
require __DIR__.'/../includes/class-mad4b-scp-aci01-semantic-recipe.php';
require __DIR__.'/../includes/class-mad4b-scp-aci01-recipe-gap.php';
require __DIR__.'/../includes/class-mad4b-scp-aci01-opportunity-preview.php';
function check($ok,$why){if(!$ok){fwrite(STDERR,'FAIL '.$why."\n");exit(1);}}
$cls='MAD4B_SCP_ACI01_Opportunity_Preview';
$cls::boot();$cls::register_ability();
$def=$GLOBALS['aci03_registered']['mad4b/aci01-opportunity-preview'];
check($def['meta']['annotations']['readonly']===true&&$def['meta']['mcp']['surface']==='read','read only');
check($def['input_schema']['additionalProperties']===false,'schema bounded');
$input=array('job_id'=>'11111111-1111-4111-8111-111111111111','post_type'=>'post','goal'=>'Useful market guide');
$out=$cls::preview($input);
check($out['status']==='NEEDS_EVIDENCE'&&$out['review_status']==='NEEDS_REVIEW','no auto approval');
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
$badt= MAD4B_SCP_ACI01_Evidence_Preview::preview($input);
$badt['scope']['binding']['restore_epoch']=2;
check($cls::compile(MAD4B_SCP_ACI01_Intake_Preview::preview($input), $badt, $input['goal'],
    MAD4B_SCP_ACI01_Semantic_Recipe::current(array('content_type'=>'article'),
        MAD4B_SCP_ACI01_Intake_Preview::preview($input)))['status']==='DENIED', 'restore epoch mismatch');
$badtype=MAD4B_SCP_ACI01_Evidence_Preview::preview($input);
$badtype['scope']['content_type']='comparison';
check($cls::compile(MAD4B_SCP_ACI01_Intake_Preview::preview($input), $badtype, $input['goal'],
    MAD4B_SCP_ACI01_Semantic_Recipe::current(array('content_type'=>'article'),
        MAD4B_SCP_ACI01_Intake_Preview::preview($input)))['status']==='DENIED', 'job content type mismatch');

$handoff=$out['blueprint_handoff'];
check($handoff['contract']==='mad4b.aci01.blueprint-handoff.v1',
    'typed handoff registered');
check($handoff['target_ability']==='mad4b/blueprint-build',
    'reuses actual Feature 007 Blueprint ability');
check($handoff['job_id']===$input['job_id'],
    'bound to source ContentJob');
check($handoff['dispatch_allowed']===false&&$handoff['artifact_created']===false
    &&$handoff['authorizing']===false,
    'Blueprint handoff must never execute or create');
check(in_array('context_artifact_id',$handoff['required_input_keys'],true)
    &&in_array('section_objectives',$handoff['required_input_keys'],true),
    'handoff lists required native pipeline fields');
check(in_array('research_rights_and_freshness',$handoff['unverified_prerequisites'],true),
    'observed research is not provider rights');
$intake=MAD4B_SCP_ACI01_Intake_Preview::preview($input);
$semantic=MAD4B_SCP_ACI01_Semantic_Recipe::current(
    array('content_type'=>'article'),$intake);
$evidence=MAD4B_SCP_ACI01_Evidence_Preview::preview($input);
$research_id='22222222-2222-4222-8222-222222222222';
$evidence['evidence_summaries']=array(
    array('artifact_id'=>$research_id,'artifact_type'=>'serp_research'));
$rich=$cls::compile($intake,$evidence,$input['goal'],$semantic);
check($rich['status']==='NEEDS_EVIDENCE' &&
    $rich['blueprint_handoff']['observed_research_artifact_ids']===array($research_id),
    'bounded research receipts stay observable, not authorized');
check($rich['blueprint_handoff']['dispatch_allowed']===false,
    'research presence cannot self-authorize Blueprint build');
$bad=$evidence;
$bad['evidence_summaries'][]=$bad['evidence_summaries'][0];
check($cls::compile($intake,$bad,$input['goal'],$semantic)['status']==='DENIED',
    'duplicate native research identity denied');
$bad=$evidence;
$bad['evidence_summaries'][0]['artifact_type']='content_recipe';
check($cls::compile($intake,$bad,$input['goal'],$semantic)['status']==='DENIED',
    'nonresearch artifacts are never admitted');
$bad=$evidence;
$bad['evidence_summaries'][0]['artifact_id']='../../wrong';
check($cls::compile($intake,$bad,$input['goal'],$semantic)['status']==='DENIED',
    'malformed native artifact ID denied');
$bad=$evidence;
$bad['job_id']='another-job';
check($cls::compile($intake,$bad,$input['goal'],$semantic)['status']==='DENIED',
    'malformed job ID cannot enter the handoff');
$bad=$evidence;
$bad['evidence_summaries']=array_fill(0,13,array('artifact_id'=>$research_id,
    'artifact_type'=>'serp_research'));
check($cls::compile($intake,$bad,$input['goal'],$semantic)['status']==='DENIED',
    'handoff remains bounded under volume pressure');
echo "ACI01_OPPORTUNITY_CANDIDATE: PASS\n";
