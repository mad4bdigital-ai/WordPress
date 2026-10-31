<?php
// Isolated deterministic behavior test; never loads WordPress or mutates data.
define('ABSPATH', '/tmp/');
class WP_Error {public function __construct(public $code, public $message) {}}
function is_wp_error($x){return $x instanceof WP_Error;}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_-]/','',(string)$v));}
require __DIR__.'/../wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-content-workflow-modes.php';
$c='MAD4B_SCP_Content_Workflow_Modes';
function ok($condition,$message){if(!$condition)throw new Exception($message);}
ok($c::requested('review_gated')==='strict','legacy mode not mapped');
ok(is_wp_error($c::requested('unknown')),'unknown mode not rejected');
foreach(['autonomous','assisted','strict'] as $mode){
 $pending=$c::review_result($mode,[],false);
 ok($pending['quality_evidence_status']==='QA_PENDING'&&!$pending['can_publish'],'pending must not publish');
 $blocked=$c::review_result($mode,['hard'],true);
 ok($blocked['quality_evidence_status']==='QA_BLOCKED'&&!$blocked['can_store_in_hub'],'blocked storage');
 $pass=$c::review_result($mode,[],true);
 ok(!$pass['publication_authorized']&&!$pass['can_publish'],'publication is separate');
 ok($pass['can_store_in_hub']===($mode==='autonomous'),'unapproved Hub storing');
 ok($pass['independent_review_required']===($mode==='strict'),'strict independent review');
}
$catalog=$c::catalog([]);
ok($catalog['mutation_performed']===false&&$catalog['publication_authorized']===false,'catalog must be read-only');
echo "CONTENT_WORKFLOW_MODES_RUNTIME: PASS 3 modes, blocked/pending/pass/legacy scenarios\n";
