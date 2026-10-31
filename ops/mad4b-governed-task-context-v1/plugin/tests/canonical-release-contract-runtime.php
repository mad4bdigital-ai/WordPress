<?php
/** Standalone QA/rights/identity release assessment contract, no DB or WordPress writes. */
define('ABSPATH',__DIR__.'/');
class WP_Error {
    private $code;
    public function __construct($code,$message='',$data=array()){$this->code=$code;}
    public function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function current_user_can($cap){return true;}
function sanitize_key($x){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$x));}
function sanitize_text_field($x){return trim((string)$x);}
function wp_strip_all_tags($x){return strip_tags($x);}
function wp_kses_post($x){return strip_tags($x,'<p><a><strong><em><img>');}
function wp_trim_words($x,$n){return implode(' ',array_slice(explode(' ',trim($x)),0,$n));}
function wp_json_encode($x,$flags=0){return json_encode($x,$flags);}
function get_option($k,$d=false){return $d;}
class MAD4B_Hub_Control_Ingestion {public static function allowed(){return true;}}
class MAD4B_SCP_Operational_Integrity {
    public static function capture() {return array('scope'=>array(
        'site_uuid'=>'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
        'brand_ref'=>'sourcebrand'));}
}
class MAD4B_Hub_Canonical_Store {
    public static $changed=false;
    public static function read_record($key) {return array(
        'record_key'=>$key,'state'=>self::$changed?'approved':'research_candidate',
        'version_sha256'=>str_repeat('a',64),'source_sha256'=>str_repeat('b',64),
        'site_uuid'=>'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
        'brand_id'=>'sourcebrand',
        'source_url'=>'https://example.invalid/source',
        'locale'=>'en','job_id'=>'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb');}
}
class MAD4B_SCP_Artifacts {
    public static $failComponent=false;
    public static function get_artifact($input) {
        $id=$input['artifact_id'];
        $job='bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';
        $draft='dddddddd-dddd-dddd-dddd-dddddddddddd';
        $ids=array(
            $draft=>'draft',
            'ffffffff-ffff-ffff-ffff-ffffffffffff'=>'final_qa',
            '11111111-1111-1111-1111-111111111111'=>'fact_ledger',
            '22222222-2222-2222-2222-222222222222'=>'editorial_qa',
            '33333333-3333-3333-3333-333333333333'=>'seo_qa',
        );
        if(!isset($ids[$id]))return new WP_Error('artifact_missing');
        $type=$ids[$id];
        $payload=array('draft_artifact_id'=>$draft,'pass'=>true,'hard_blockers'=>array());
        if($type==='draft')$payload=array('contract'=>'mad4b.article-draft.v1',
            'draft_only'=>true,'content'=>str_repeat('Evidence-linked original prose. ',12));
        if($type==='final_qa')$payload=array(
            'contract'=>'mad4b.final-qa.v1','draft_artifact_id'=>$draft,
            'pass'=>true,'can_store_in_hub'=>true,'hard_blockers'=>array(),
            'component_artifact_ids'=>array('fact_ledger'=>'11111111-1111-1111-1111-111111111111',
                'editorial_qa'=>'22222222-2222-2222-2222-222222222222',
                'seo_qa'=>'33333333-3333-3333-3333-333333333333'));
        if(self::$failComponent && $type==='fact_ledger')$payload['pass']=false;
        return array('artifact'=>array('artifact_id'=>$id,'job_id'=>$job,
            'artifact_type'=>$type,'status'=>'active','payload'=>$payload));
    }
}
require dirname(__DIR__).'/includes/class-mad4b-hub-usage-rights.php';
require dirname(__DIR__).'/includes/class-mad4b-hub-release-gate.php';
function check($actual,$expected,$name) {
    if($actual!==$expected)throw new RuntimeException('assertion_'.$name);
}
$input=array(
    'record_key'=>str_repeat('c',64),
    'expected_version_sha256'=>str_repeat('a',64),
    'draft_artifact_id'=>'dddddddd-dddd-dddd-dddd-dddddddddddd',
    'final_qa_artifact_id'=>'ffffffff-ffff-ffff-ffff-ffffffffffff',
    'title'=>'Synthetic QA-qualified external content example',
    'rights'=>array('origin'=>'external_reference','external_media'=>true),
);
$result=MAD4B_Hub_Release_Gate::evaluate($input);
if(is_wp_error($result))throw new RuntimeException('expected_quality_pass:'.$result->get_error_code());
check($result['eligible'],true,'eligible');
check($result['normalized']['rights_state'],'not_verified','not_verified_allowed');
check($result['normalized']['external_media'],true,'external_media_allowed');
check($result['provenance']['rights_handling'],'company_responsibility','company_rights');
check($result['wordpress_post_created'],false,'no_post');
$bad=$input;$bad['expected_version_sha256']=str_repeat('e',64);
$r=MAD4B_Hub_Release_Gate::evaluate($bad);
check($r->get_error_code(),'mad4b_hub_release_stale_research_version','stale');
$bad=$input;$bad['draft_artifact_id']='99999999-9999-9999-9999-999999999999';
$r=MAD4B_Hub_Release_Gate::evaluate($bad);
check($r->get_error_code(),'artifact_missing','missing_draft');
MAD4B_SCP_Artifacts::$failComponent=true;
$r=MAD4B_Hub_Release_Gate::evaluate($input);
check($r->get_error_code(),'mad4b_hub_release_component_qa_failed','component_gate');
echo "CANONICAL_RELEASE_CONTRACT=PASS; unverified_license=allowed, qa_scope=verified, no_publication\n";
