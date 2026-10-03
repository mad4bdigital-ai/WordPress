<?php
define( 'ABSPATH', __DIR__ );
class WP_Error {
	private $code;
	public function __construct( $code, $message = '', $data = null ) { $this->code=(string)$code; }
	public function get_error_code(){ return $this->code; }
}
function is_wp_error($v){ return $v instanceof WP_Error; }
function sanitize_key($v){ return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$v)); }
require dirname(__DIR__).'/includes/class-mad4b-scp-provider-transport-eligibility.php';

$fail=static function($m,$v=null){fwrite(STDERR,'FAIL provider-transport-eligibility-runtime: '.$m.(null===$v?'':' '.json_encode($v)).PHP_EOL);exit(1);};
$check=static function($c,$m,$v=null)use($fail){if(!$c)$fail($m,$v);};
$base=array(
 'kill_switch_active'=>false,'authority_allowed'=>true,'quarantined'=>false,
 'certification_allowed'=>true,'release_ring_allowed'=>true,'breaker_allowed'=>true,
 'provider_id'=>'ci-provider','ability_name'=>'ci/provider-write','environment'=>'staging',
 'release_ring'=>MAD4B_SCP_Provider_Transport_Eligibility::R3,
 'certification_level'=>'REVERSIBLE_WRITE_CERTIFIED','activation_stage'=>'active'
);
$allow=MAD4B_SCP_Provider_Transport_Eligibility::resolve_facts($base);
$check(is_array($allow)&&'ALLOW'===$allow['decision']&&!empty($allow['transport_eligible']),'Fully eligible provider transport was denied.',$allow);

$all=$base;
$all['kill_switch_active']=true;$all['authority_allowed']=false;$all['quarantined']=true;
$all['certification_allowed']=false;$all['release_ring_allowed']=false;$all['breaker_allowed']=false;
$r=MAD4B_SCP_Provider_Transport_Eligibility::resolve_facts($all);
$check('mutation_kill_switch_active'===$r['reason_code'],'Kill switch did not win strongest-deny precedence.',$r);

$cases=array(
 array('field'=>'authority_allowed','value'=>false,'reason'=>'mutation_authority_denied'),
 array('field'=>'quarantined','value'=>true,'reason'=>'provider_capability_quarantined'),
 array('field'=>'certification_allowed','value'=>false,'reason'=>'provider_capability_not_write_certified'),
 array('field'=>'release_ring_allowed','value'=>false,'reason'=>'provider_release_ring_ineligible'),
 array('field'=>'breaker_allowed','value'=>false,'reason'=>'provider_circuit_breaker_blocked'),
);
foreach($cases as $index=>$case){
 $facts=$base;
 foreach(array_slice($cases,0,$index) as $higher){
   $facts[$higher['field']] = 'quarantined'===$higher['field'] ? false : true;
 }
 $facts[$case['field']]=$case['value'];
 $r=MAD4B_SCP_Provider_Transport_Eligibility::resolve_facts($facts);
 $check('DENY'===$r['decision']&&$case['reason']===$r['reason_code'],'Provider deny precedence drifted.',$r);
}

$active=array('activation_stage'=>'active');
$check(MAD4B_SCP_Provider_Transport_Eligibility::R3===MAD4B_SCP_Provider_Transport_Eligibility::release_ring_for_status($active,'production'),'ACTIVE compatibility bridge improperly became Production R4.');
$check(!MAD4B_SCP_Provider_Transport_Eligibility::release_ring_allows_normal_mutation(MAD4B_SCP_Provider_Transport_Eligibility::R3,'production'),'R3 was treated as Production eligible.');
$check(MAD4B_SCP_Provider_Transport_Eligibility::release_ring_allows_normal_mutation(MAD4B_SCP_Provider_Transport_Eligibility::R3,'staging'),'R3 did not allow general Staging transport.');

echo "mad4b.provider-transport-eligibility.runtime.v1: PASS\n";
