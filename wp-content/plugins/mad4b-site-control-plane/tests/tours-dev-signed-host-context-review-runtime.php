<?php
/** Negative-only Context Authority host lane: never writes a WordPress option. */
error_reporting(E_ALL);define('ABSPATH',__DIR__.'/');
class WP_Error {private $code;public function __construct($code,$msg=''){$this->code=$code;}public function get_error_code(){return $this->code;}}
function check_it($ok,$why){if(!$ok){fwrite(STDERR,"FAIL: $why\n");exit(1);}}
class MAD4B_SCP_Host_Agent_Bridge {static $on=false;public static function active(){return self::$on;}}
require dirname(__DIR__).'/includes/class-mad4b-scp-context-authority.php';
$review='MAD4B_SCP_Context_Authority';
$agent=array('id'=>71,'public_id'=>'non-authorizing-local-test-agent');
$denied=$review::review_asset_by_host_agent(array('asset_id'=>'unknown'),$agent,'none');
check_it($denied instanceof WP_Error && $denied->get_error_code()==='mad4b_host_review_proof_required','Host signature/active CLI proof must precede all asset review');
MAD4B_SCP_Host_Agent_Bridge::$on=true;
$forbidden=array('category','authority_class','required','required_scope_confirmed','quality_mode','quality_score','classification_confirmed','approve_stale_generation_evidence');
foreach($forbidden as $field){
 $no=$review::review_asset_by_host_agent(array($field=>1),$agent,'test-only');
 check_it($no instanceof WP_Error && $no->get_error_code()==='mad4b_host_ai_governance_mutation_denied','Host cannot override governance field '.$field);
}
MAD4B_SCP_Host_Agent_Bridge::$on=false;
check_it($review::AI_REVIEW_ABILITY==='mad4b/context-ai-review','Canonical exact review ability unchanged');
echo "PASS: Context Authority host review requires active signed host and refuses 8 governance mutation fields\n";
