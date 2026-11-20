<?php
/**
 * Original Google Drive review-consumer integration, hermetic: no network,
 * no WordPress DB, no refresh, approval, publication or other writes.
 */
if ( ! defined('ABSPATH') ) define('ABSPATH','/');
$GLOBALS['case_count']=0;
$GLOBALS['asset_id']=str_repeat('a',64);
$GLOBALS['source_id']=str_repeat('b',64);
$GLOBALS['content']='Exact governed source text for read-only AI review.';
$GLOBALS['asset']=[
 'source_mode'=>'governed','source_id'=>$GLOBALS['source_id'],
 'file_id'=>'original-drive-file-01','content_hash'=>hash('sha256',$GLOBALS['content']),
 'title'=>'Source Title','category'=>'Brand Voice','site_uuid'=>'12345678-abcd-abcd-abcd-123456789abc'
];
$GLOBALS['source']=['provider'=>'google_drive','source_id'=>$GLOBALS['source_id'],
 'site_uuid'=>'12345678-abcd-abcd-abcd-123456789abc','mode'=>'governed'];
$GLOBALS['queue']=['registry_revision'=>11,'authority_manifest_fingerprint'=>str_repeat('d',64)];
class WP_Error {
 private $code;
 public function __construct($code,$message='',$data=[]){$this->code=$code;}
 public function get_error_code(){return $this->code;}
}
function is_wp_error($value){return $value instanceof WP_Error;}
class MAD4B_SCP_Context_Authority {
 public static function asset($id){return $GLOBALS['asset'];}
 public static function source($id){return $GLOBALS['source'];}
 public static function review_queue(){return $GLOBALS['queue'];}
}
class MAD4B_SCP_Google_Drive_Context {
 public static $calls=0;
 public static $forced_error='';
 public static $change_hash_after_read=false;
 public static function exact_review_text_evidence(array $asset,array $source) {
  ++self::$calls;
  if(self::$forced_error!=='')return new WP_Error(self::$forced_error);
  if(self::$change_hash_after_read)$GLOBALS['asset']['content_hash']=str_repeat('f',64);
  return [
   'contract'=>'mad4b.google-drive-exact-review-evidence.v1',
   'content_hash'=>$asset['content_hash'],'mime_type'=>'text/plain',
   'normalized_text'=>$GLOBALS['content'],'source_generation'=>str_repeat('e',64),
   'read_only'=>true,'publication_authorized'=>false,'mutation_performed'=>false
  ];
 }
}
function check($name,$yes){
 ++$GLOBALS['case_count'];
 if(!$yes){fwrite(STDERR,'FAIL: '.$name."\n");exit(1);}
 echo 'PASS: '.$name."\n";
}
require dirname(__DIR__).'/includes/class-mad4b-scp-ai-review-lifecycle.php';
$c='MAD4B_SCP_AI_Review_Lifecycle';
$result=$c::exact_media_evidence($GLOBALS['asset_id']);
check('current_original_drive_provider_evidence_read_only',is_array($result)
 &&$result['contract']==='mad4b.ai-review-evidence.v1'
 &&$result['normalized_text']===$GLOBALS['content']
 &&$result['content_hash']===$GLOBALS['asset']['content_hash']
 &&$result['source_generation']===str_repeat('e',64)
 &&$result['expected_registry_revision']===11
 &&$result['expected_authority_manifest_fingerprint']===str_repeat('d',64)
 &&$result['read_only']===true &&$result['mutation_performed']===false
 &&$result['publication_authorized']===false);
check('one_provider_read_only',MAD4B_SCP_Google_Drive_Context::$calls===1);
MAD4B_SCP_Google_Drive_Context::$forced_error='mad4b_drive_review_source_hash_drift';
$err=$c::exact_media_evidence($GLOBALS['asset_id']);
check('provider_hash_drift_error_propagated',is_wp_error($err)
 &&$err->get_error_code()==='mad4b_drive_review_source_hash_drift');
MAD4B_SCP_Google_Drive_Context::$forced_error='mad4b_drive_review_read_grant_unavailable';
$err=$c::exact_media_evidence($GLOBALS['asset_id']);
check('revoked_or_expired_read_grant_denied',is_wp_error($err)
 &&$err->get_error_code()==='mad4b_drive_review_read_grant_unavailable');
MAD4B_SCP_Google_Drive_Context::$forced_error='';
MAD4B_SCP_Google_Drive_Context::$change_hash_after_read=true;
$err=$c::exact_media_evidence($GLOBALS['asset_id']);
check('registry_hash_changed_during_remote_fetch_denied',is_wp_error($err)
 &&$err->get_error_code()==='mad4b_ai_evidence_registry_changed');
$GLOBALS['asset']['content_hash']=hash('sha256',$GLOBALS['content']);
MAD4B_SCP_Google_Drive_Context::$change_hash_after_read=false;
$GLOBALS['queue']=[];
$err=$c::exact_media_evidence($GLOBALS['asset_id']);
check('missing_context_review_queue_denied',is_wp_error($err)
 &&$err->get_error_code()==='mad4b_ai_evidence_queue_unavailable');
$GLOBALS['queue']=['registry_revision'=>11,'authority_manifest_fingerprint'=>str_repeat('d',64)];
$GLOBALS['source']['provider']='unsupported_provider';
$err=$c::exact_media_evidence($GLOBALS['asset_id']);
check('unregistered_provider_denied',is_wp_error($err)
 &&$err->get_error_code()==='mad4b_ai_evidence_provider_delegation_required');
$GLOBALS['source']['provider']='google_drive';
$GLOBALS['asset']['source_mode']='task_attachment';
$err=$c::exact_media_evidence($GLOBALS['asset_id']);
check('ungoverned_asset_denied',is_wp_error($err)
 &&$err->get_error_code()==='mad4b_ai_evidence_asset_missing');
$GLOBALS['asset']['source_mode']='governed';
$err=$c::exact_media_evidence('bad');
check('invalid_sha_id_denied',is_wp_error($err)
 &&$err->get_error_code()==='mad4b_ai_evidence_id_invalid');
check('no_auto_approval_or_publish_or_write',MAD4B_SCP_Google_Drive_Context::$calls===5);
echo 'SUMMARY: '.$GLOBALS['case_count'].' hermetic exact Drive AI Review checks passed; no write\n';
