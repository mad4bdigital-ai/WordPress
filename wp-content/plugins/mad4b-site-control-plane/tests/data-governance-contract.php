<?php

define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
 private $code; private $message;
 public function __construct($code,$message=''){ $this->code=$code; $this->message=$message; }
 public function get_error_code(){ return $this->code; }
}
function is_wp_error($v){return $v instanceof WP_Error;}
function add_action($h,$c,$p=10){}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',str_replace('.','_',trim((string)$v))));}
function sanitize_text_field($v){return trim((string)$v);}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}

final class MAD4B_SCP_Artifacts {
 public static $last=array();
 public static function append_artifact($input){
  self::$last=$input;
  return array('artifact'=>array(
   'artifact_id'=>'77777777-7777-4777-8777-777777777777',
   'job_id'=>$input['job_id'],
   'artifact_type'=>$input['artifact_type'],
   'payload'=>$input['payload'],
   'metadata'=>$input['metadata'],
   'status'=>'active',
  ));
 }
}

require dirname(__DIR__) . '/includes/class-mad4b-scp-data-governance.php';

$fail=static function($m){fwrite(STDERR,"FAIL data-governance-contract: $m\n");exit(1);};
$check=static function($c,$m) use($fail){if(!$c)$fail($m);};
$base=array(
 'purpose'=>'research',
 'data_classes'=>array('public_content'),
 'rights_records'=>array(array('rights_class'=>'PERMITTED_REFERENCE_ONLY','review_status'=>'approved')),
 'destination_profile'=>array(
  'provider_id'=>'research_vendor','processor_type'=>'research_external',
  'allowed_data_classes'=>array('public_content'),'prohibited_data_classes'=>array(),
  'region'=>'eu','training_use'=>false,'retention_days'=>7,
 ),
 'site_policy'=>array(
  'policy_revision'=>'7','allowed_processors'=>array('research_vendor'),
  'allowed_processing_regions'=>array('eu'),'training_use_allowed'=>false,'max_retention_days'=>30,
 ),
);
$ok=MAD4B_SCP_Data_Governance::evaluate($base);
$check('ALLOW'===$ok['decision'],'research reference should be allowed');
$check(false===$ok['authorizing'] && false===$ok['mutation_performed'],'decision became authorizing/mutating');
$check(1===preg_match('/^[a-f0-9]{64}$/',$ok['decision_fingerprint']),'decision fingerprint invalid');

$publish=$base;$publish['purpose']='publish';
$denied=MAD4B_SCP_Data_Governance::evaluate($publish);
$check('DENY'===$denied['decision'],'reference-only publication was not denied');
$check(in_array('reference_only_reuse_denied',$denied['hard_denials'],true),'rights denial reason missing');

$training=$base;$training['destination_profile']['training_use']=true;
$denied_training=MAD4B_SCP_Data_Governance::evaluate($training);
$check('DENY'===$denied_training['decision'],'training-use conflict was not denied');

$region=$base;$region['destination_profile']['region']='us';
$denied_region=MAD4B_SCP_Data_Governance::evaluate($region);
$check('DENY'===$denied_region['decision'],'residency mismatch was not denied');

$local=$base;
$local['destination_profile']['processor_type']='ai_external';
$local['site_policy']['no_external_ai']=true;
$local_only=MAD4B_SCP_Data_Governance::evaluate($local);
$check('LOCAL_ONLY'===$local_only['decision'],'no-external-AI policy did not force LOCAL_ONLY');

$redact=$base;
$redact['data_classes']=array('public_content','customer_email');
$redact['destination_profile']['allowed_data_classes'][]='customer_email';
$redact['site_policy']['redact_data_classes']=array('customer_email');
$redacted=MAD4B_SCP_Data_Governance::evaluate($redact);
$check('REDACT_THEN_ALLOW'===$redacted['decision'],'redaction policy did not produce REDACT_THEN_ALLOW');

$approval=$base;
$approval['data_classes']=array('public_content','internal_confidential');
$approval['destination_profile']['allowed_data_classes'][]='internal_confidential';
$approval['site_policy']['approval_required_data_classes']=array('internal_confidential');
$review=MAD4B_SCP_Data_Governance::evaluate($approval);
$check('REQUIRE_APPROVAL'===$review['decision'],'approval-required class did not block automatic allow');

$record_input=$base;
$record_input['job_id']='11111111-2222-4333-8444-555555555555';
$record_input['reason']='persist governed data decision evidence';
$record_input['rights_records']=array(array(
 'rights_class'=>'LICENSED',
 'review_status'=>'approved',
 'attribution_required'=>true,
 'raw_source_text'=>'TOP_SECRET_SOURCE_TEXT_SHOULD_NEVER_PERSIST',
 'license_document'=>'PRIVATE_LICENSE_DOCUMENT_SHOULD_NEVER_PERSIST',
));
$record=MAD4B_SCP_Data_Governance::record_decision($record_input);
$check(is_array($record) && true===$record['mutation_performed'],'durable data-governance decision was not recorded');
$check(false===$record['provider_call_performed'] && false===$record['authority_created'],'recording widened provider/authority behavior');
$check('data_governance_decision'===MAD4B_SCP_Artifacts::$last['artifact_type'],'wrong durable artifact type');
$persisted=json_encode(MAD4B_SCP_Artifacts::$last);
$check(false===strpos($persisted,'TOP_SECRET_SOURCE_TEXT_SHOULD_NEVER_PERSIST'),'raw source text leaked into durable evidence');
$check(false===strpos($persisted,'PRIVATE_LICENSE_DOCUMENT_SHOULD_NEVER_PERSIST'),'raw license document leaked into durable evidence');
$check(true===MAD4B_SCP_Artifacts::$last['payload']['payload_minimized'],'payload minimization evidence missing');
$check(false===MAD4B_SCP_Artifacts::$last['payload']['raw_rights_records_persisted'],'raw rights persistence flag widened');
$check(1===preg_match('/^[a-f0-9]{64}$/',$record['decision_fingerprint']),'recorded decision fingerprint invalid');

$artifact_source=file_get_contents(dirname(__DIR__) . '/includes/class-mad4b-scp-artifacts.php');
$check(false!==strpos($artifact_source,"'data_governance_decision'"),'data-governance artifact type not registered');

$source=file_get_contents(dirname(__DIR__) . '/includes/class-mad4b-scp-data-governance.php');
foreach(array('wp_insert_post(','wp_update_post(','$wpdb->','shell_exec(','exec(','proc_open(','curl_exec(') as $forbidden){
 $check(false===strpos($source,$forbidden),'mutation/network primitive present: '.$forbidden);
}
echo "mad4b.data-governance-decision.v1: PASS\n";
