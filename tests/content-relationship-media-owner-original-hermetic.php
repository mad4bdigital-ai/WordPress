<?php
/** Original media owner adapter must return HOLD, never throw or auto-write on missing source. */
define('ABSPATH',__DIR__.'/');
class WP_Error{private $code;function __construct($code,$msg='',$data=[]){$this->code=$code;}function get_error_code(){return $this->code;}}
function is_wp_error($v){return $v instanceof WP_Error;}
interface MAD4B_Central_Enrichment_Write_Adapter{}
class MAD4B_SCP_Content_Jobs {
 static $read=0;
 static function relationship_plan($input){self::$read++;return new WP_Error('native_job_missing');}
}
function media_check($ok,$why){if(!$ok){fwrite(STDERR,'FAIL '.$why.PHP_EOL);exit(1);}$GLOBALS['n']=($GLOBALS['n']??0)+1;}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-content-relationship-writeback-media.php';
$a=new MAD4B_SCP_Relationship_Media_Write_Adapter('11111111-1111-4111-8111-111111111111');
$p=$a->plan();
media_check(($p['state']??null)==='HOLD'
 &&($p['reason']??null)==='native_contentjob_artifact_not_bound'
 &&($p['write_performed']??null)===false,
 'Unverified original media owner state cannot become a ready plan');
media_check($a->authorize([],[])===false,
 'Original media permission cannot be granted without source ContentJob');
try{$r=$a->run(str_repeat('a',64));}
catch(Throwable $e){$r=['unhandled_exception'=>$e->getMessage()];}
media_check(($r['state']??null)==='HOLD'
 &&($r['mutation_performed']??null)===false,
 'No native owner source may return controlled HOLD, never an uncaught provider exception');
media_check($a->readback([],[],[])===false,
 'No fake readback may be verified without target and attachment identity');
echo 'PASS: CONTENT_RELATIONSHIP_MEDIA_OWNER_ORIGINAL '.($GLOBALS['n']??0).
 ' native owner planning/permission/readback denial cases'.PHP_EOL;
