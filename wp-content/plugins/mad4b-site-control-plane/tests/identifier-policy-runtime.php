<?php
define( 'ABSPATH', __DIR__ );
class WP_Error { private $c; public function __construct($c,$m='',$d=null){$this->c=(string)$c;} public function get_error_code(){return $this->c;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$v));}
require dirname(__DIR__).'/includes/class-mad4b-scp-identifiers.php';

$fail=static function($m,$v=null){fwrite(STDERR,'FAIL identifier-policy-runtime: '.$m.(null===$v?'':' '.json_encode($v)).PHP_EOL);exit(1);};
$check=static function($c,$m,$v=null)use($fail){if(!$c)$fail($m,$v);};

$uuid='11111111-1111-4111-8111-111111111111';
$upper=strtoupper($uuid);
$check($uuid===MAD4B_SCP_Identifiers::operation_id_for_write($uuid),'canonical operation UUIDv4 rejected');
$normalized=MAD4B_SCP_Identifiers::operation_lookup($upper);
$check(is_array($normalized)&&$uuid===$normalized['value']&&'canonical'===$normalized['identity_class'],'canonical UUID normalization failed',$normalized);

$bad_uuid='11111111-1111-1111-8111-111111111111';
$bad=MAD4B_SCP_Identifiers::operation_lookup($bad_uuid);
$check(is_wp_error($bad)&&'mad4b_operation_legacy_uuid_alias_denied'===$bad->get_error_code(),'non-v4 UUID-shaped operation id escaped through legacy lane',$bad);

$legacy='legacy.op:2024.Run-ABC_01';
$legacy_view=MAD4B_SCP_Identifiers::operation_lookup($legacy);
$check(is_array($legacy_view)&&$legacy===$legacy_view['value']&&'legacy_read_only'===$legacy_view['identity_class'],'legacy operation read identity changed',$legacy_view);
$check(!empty($legacy_view['historical_identity_preserved'])&&empty($legacy_view['rewrite_allowed'])&&empty($legacy_view['write_eligible']),'legacy operation identity became writable/rewriteable',$legacy_view);
$check(''===MAD4B_SCP_Identifiers::operation_id_for_write($legacy),'legacy operation identity admitted to new journal write');

$check($uuid===MAD4B_SCP_Identifiers::job_id($uuid),'canonical job UUIDv4 rejected');
$check(''===MAD4B_SCP_Identifiers::job_id($bad_uuid),'non-v4 job UUID admitted');
$check('core-content'===MAD4B_SCP_Identifiers::provider_id('CORE-CONTENT'),'provider_slug_v1 normalization failed');
$check(''===MAD4B_SCP_Identifiers::provider_id('core/content'),'provider slash alias admitted');

$digest=str_repeat('a',64);
$receipt=MAD4B_SCP_Identifiers::receipt_id_from_sha256($digest);
$check('receipt:v1:'.$digest===$receipt,'versioned receipt identity derivation failed',$receipt);
$check(''===MAD4B_SCP_Identifiers::receipt_id('receipt:v1:not-a-digest'),'invalid receipt identity admitted');

$approval=MAD4B_SCP_Identifiers::approval_ticket_id($upper);
$check($uuid===$approval,'approval UUIDv4 normalization regressed');

echo "mad4b.identifier-policy.runtime.v1: PASS\n";
