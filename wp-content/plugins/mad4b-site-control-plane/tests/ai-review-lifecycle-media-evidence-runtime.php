<?php
/** Hermetic Local-only media evidence: no WordPress DB, external provider or file mutation in the product. */
define('ABSPATH', '/');
$GLOBALS['checks']=0;
$GLOBALS['fixture_dir']=sys_get_temp_dir().'/mad4b_lifecycle_'.getmypid();
if (!mkdir($GLOBALS['fixture_dir'],0700,true))exit(2);
$base=$GLOBALS['fixture_dir'];
$target=$base.'/reviewed.txt';
file_put_contents($target,'Approved preview draft for review only.');
$GLOBALS['attachment']=$target;
$GLOBALS['asset_id']=str_repeat('a',64);
$GLOBALS['source']=['provider'=>'wordpress_media'];
$GLOBALS['asset']=['source_mode'=>'governed','source_id'=>'source:a','file_id'=>101,
 'content_hash'=>hash('sha256',file_get_contents($target)),'title'=>'Test title','category'=>'Editorial'];
class WP_Error {private $code;public function __construct($code,$message='',$data=[]){$this->code=$code;}public function get_error_code(){return $this->code;}}
function is_wp_error($x){return $x instanceof WP_Error;}
function get_post_type($id){return $id===101?'attachment':null;}
function get_attached_file($id){return $GLOBALS['attachment'];}
function wp_get_upload_dir(){return ['basedir'=>$GLOBALS['fixture_dir']];}
function get_post_mime_type($id){return 'text/plain';}
class MAD4B_SCP_WordPress_Media_Context {
 static $complete=true;
 static function normalize_bytes($mime,$bytes){return ['normalized_text'=>$bytes,'content_complete'=>self::$complete];}
}
class MAD4B_SCP_Context_Authority {
 public static function asset($id){return $GLOBALS['asset'];}
 public static function source($id){return $GLOBALS['source'];}
 public static function review_queue(){return ['registry_revision'=>12,'authority_manifest_fingerprint'=>str_repeat('b',64)];}
}
function check($name,$condition){$GLOBALS['checks']++;if(!$condition){fwrite(STDERR,"FAIL $name\n");exit(1);}echo "PASS $name\n";}
require dirname(__DIR__).'/includes/class-mad4b-scp-ai-review-lifecycle.php';
$class='MAD4B_SCP_AI_Review_Lifecycle';
try{
 $ok=$class::exact_media_evidence($GLOBALS['asset_id']);
 check('exact_governed_source_read_only',is_array($ok)&&$ok['read_only']===true&&$ok['mutation_performed']===false&&$ok['publication_authorized']===false);
 check('exact_source_revision_bound',$ok['expected_registry_revision']===12&&$ok['content_hash']===$GLOBALS['asset']['content_hash']);
 check('invalid_asset_id_denied',is_wp_error($class::exact_media_evidence('oops')));
 $GLOBALS['asset']['source_mode']='unreviewed';
 check('ungoverned_asset_denied',is_wp_error($class::exact_media_evidence($GLOBALS['asset_id'])));
 $GLOBALS['asset']['source_mode']='governed';
 $GLOBALS['source']['provider']='google_drive';
 check('foreign_provider_denied',is_wp_error($class::exact_media_evidence($GLOBALS['asset_id'])));
 $GLOBALS['source']['provider']='wordpress_media';
 $GLOBALS['attachment']='/etc/hosts';
 check('outside_uploads_denied',is_wp_error($class::exact_media_evidence($GLOBALS['asset_id'])));
 $GLOBALS['attachment']=$target;
 $sym=$base.'/symlink.txt';
 if (!symlink($target,$sym))exit(3);
 $GLOBALS['attachment']=$sym;
 check('symlink_denied',is_wp_error($class::exact_media_evidence($GLOBALS['asset_id'])));
 $GLOBALS['attachment']=$target;
 $GLOBALS['asset']['content_hash']=str_repeat('f',64);
 check('hash_drift_denied',is_wp_error($class::exact_media_evidence($GLOBALS['asset_id'])));
 $GLOBALS['asset']['content_hash']=hash('sha256',file_get_contents($target));
 MAD4B_SCP_WordPress_Media_Context::$complete=false;
 check('incomplete_normalization_denied',is_wp_error($class::exact_media_evidence($GLOBALS['asset_id'])));
 MAD4B_SCP_WordPress_Media_Context::$complete=true;
 check('no_publication_mutation',is_file($target)&&file_get_contents($target)==='Approved preview draft for review only.');
 echo 'SUMMARY pass='.$GLOBALS['checks'].' fail=0'."\n";
}finally{
 if(isset($sym)&&is_link($sym))unlink($sym);
 if(is_file($target))unlink($target);
 rmdir($base);
}
