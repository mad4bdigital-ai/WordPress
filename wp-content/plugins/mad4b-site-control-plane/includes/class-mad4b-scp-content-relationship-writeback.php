<?php
/**
 * ContentJob typed relationship writeback registry.
 * Only registered original provider owner adapters, no generic metadata/PHP.
 */
defined('ABSPATH') || exit;
require_once __DIR__.'/class-mad4b-scp-central-write-acceptance.php';
require_once __DIR__.'/class-mad4b-scp-content-relationship-writeback-media.php';
require_once __DIR__.'/class-mad4b-scp-content-relationship-writeback-owner.php';
final class MAD4B_SCP_Content_Relationship_Writeback {
 const CONTRACT='mad4b.contentjob.typed-relationship-writeback.v1';
 private static function hold(string $why):array {
  return ['contract'=>self::CONTRACT,'state'=>'HOLD',
   'reason'=>$why,'mutation_performed'=>false];
 }
 private static function adapter(array $input) {
  $id=strtolower((string)($input['job_id']??''));
  $kind=(string)($input['relationship_kind']??'');
  $key=(string)($input['meta_key']??'');
  if(!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D',$id))
   return null;
  if($kind==='featured_image' && $key==='')return new MAD4B_SCP_Relationship_Media_Write_Adapter($id);
  if($kind==='elementor_template_refs' && $key==='')return new MAD4B_SCP_Relationship_Owner_Write_Adapter($id,'elementor');
  if($kind==='typed_meta' && $key!=='' && strlen($key)<=80 &&
     preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]+$/D',$key))
   return new MAD4B_SCP_Relationship_Owner_Write_Adapter($id,'typed_meta',$key);
  return null;
 }
 public static function plan($input=[]):array {
  if(!class_exists('MAD4B_SCP_Central_Write_Acceptance',false))
   return self::hold('central_write_acceptance_unavailable');
  $adapter=self::adapter((array)$input);
  if(!$adapter)return self::hold('original_owner_adapter_missing');
  $p=$adapter->plan();
  return ['contract'=>self::CONTRACT,'owner_plan'=>$p,
   'job_id'=>$input['job_id'],'relationship_kind'=>$input['relationship_kind'],
   'state'=>$p['state']??'HOLD','reason'=>$p['reason']??null,
   'write_performed'=>false];
 }
 public static function apply($input=[]):array {
  $input=(array)$input;
  if(!class_exists('MAD4B_SCP_Central_Write_Acceptance',false)||
    !MAD4B_SCP_Central_Write_Acceptance::permitted())
   return self::hold('current_actor_or_site_not_authorized');
  $adapter=self::adapter($input);
  if(!$adapter)return self::hold('unsupported_or_unregistered_original_owner');
  $hash=(string)($input['expected_source_sha256']??'');
  if(!MAD4B_SCP_Central_Write_Acceptance::sha($hash))
   return self::hold('original_source_sha_required');
  $plan=$adapter->plan();
  if(($plan['state']??null)!=='READY' ||
     !hash_equals((string)($plan['source_sha256']??''),$hash))
   return self::hold('owner_qa_or_artifact_binding_not_ready');
  try {return ['contract'=>self::CONTRACT,'owner_result'=>$adapter->run($hash)];}
  catch(Throwable $e){return self::hold('owner_source_or_runtime_changed');}
 }
}

/**
 * Existing native ContentJob commit event is the synchronous auto-write
 * trigger. Nothing is queued, and unqualified provider evidence is HOLD.
 */
add_action('mad4b_scp_content_job_transitioned',static function($event){
 if(!is_array($event)||empty($event['job_id'])||
   !MAD4B_SCP_Central_Write_Acceptance::permitted())return;
 $jobId=(string)$event['job_id'];
 $read=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$jobId]);
 if(is_wp_error($read)||in_array($read['job']['state']??'', ['COMPLETED','CANCELLED'],true)||
   (int)($read['job']['job_revision']??0)!==(int)($event['job_revision']??-1)||
   !ctype_digit((string)($read['job']['current_artifact_id']??'')))return;
 $graph=MAD4B_SCP_Content_Jobs::relationship_plan(['job_id'=>$jobId]);
 if(($graph['state']??'')!=='READY')return;
 foreach(['featured_image','elementor_template_refs'] as $kind) {
  $arg=['job_id'=>$jobId,'relationship_kind'=>$kind];
  $plan=MAD4B_SCP_Content_Relationship_Writeback::plan($arg);
  $sourceHash=$plan['owner_plan']['source_sha256']??null;
  if(($plan['state']??'')!=='READY'||
      !MAD4B_SCP_Central_Write_Acceptance::sha($sourceHash))continue;
  $result=MAD4B_SCP_Content_Relationship_Writeback::apply(
    $arg+['expected_source_sha256'=>$sourceHash]);
  MAD4B_SCP_Audit::record('mad4b/content-job-typed-relations-auto',
   ['job_id'=>$jobId,'kind'=>$kind,'owner_result'=>$result['owner_result']['reason']??
    ($result['reason']??'unconfirmed')]);
 }
 // Typed meta keys must come from an explicit owner field contract and are
 // never guessed from generic source/target numerical metadata.
},30,1);
