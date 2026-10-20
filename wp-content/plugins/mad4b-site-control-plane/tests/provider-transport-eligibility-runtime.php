<?php
define( 'ABSPATH', __DIR__ );
class WP_Error {
	private $code; private $message; private $data;
	public function __construct( $code, $message = '', $data = null ) { $this->code=(string)$code; $this->message=$message; $this->data=$data; }
	public function get_error_code(){ return $this->code; }
 public function get_error_message(){ return $this->message; }
 public function get_error_data(){ return $this->data; }
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


/* Exact Context scan read-only static diagnosis: never impersonates a write
 * authorization, opens a breaker probe, or grants release ring eligibility. */
class MAD4B_SCP_Site_Profile {
    public static $environment = 'staging';
    public static function current_environment(){ return self::$environment; }
}
class MAD4B_SCP_Provider_Circuit_Breaker {
    public static $applicable = true;
    public static function target_context($surface,$target){
        return array('applicable'=>self::$applicable,'provider_id'=>'google_drive_context');
    }
}
class MAD4B_SCP_Provider_Compatibility_Certification {
    const LEVEL_BOUNDED_WRITE = 'BOUNDED_WRITE_CERTIFIED';
    const LEVEL_REVERSIBLE_WRITE = 'REVERSIBLE_WRITE_CERTIFIED';
    const LEVEL_FULL = 'FULL_CERTIFIED';
    const LEVEL_QUARANTINED = 'QUARANTINED';
    public static $status = null;
    public static $level = 'DISCOVERED';
    public static $stage = 'shadow';
    public static $ring = '';
    public static $bound = true;
    public static function ability_status($provider,$ability){
        if (is_array(self::$status)) return self::$status;
        return array('certification_level'=>self::$level,'activation_stage'=>self::$stage,
            'release_ring'=>self::$ring,'structural_compatible'=>true,
            'artifact_authority_bound'=>self::$bound,
            'behavioral_evidence'=>array('state'=>'not_certified'));
    }
}
$inspect = static function(){ return MAD4B_SCP_Provider_Transport_Eligibility::inspect_target_certification(
    'write','context/source-scan-apply'); };
$denied = $inspect();
$check('provider_capability_not_write_certified'===$denied['reason_code']
    && $denied['transport_eligible']===null && !$denied['authorization_checked']
    && !$denied['breaker_checked'] && !$denied['authorizing'],
    'Read-only inspection silently authorized uncertified write capability.',$denied);
MAD4B_SCP_Provider_Compatibility_Certification::$level =
    MAD4B_SCP_Provider_Compatibility_Certification::LEVEL_BOUNDED_WRITE;
MAD4B_SCP_Provider_Compatibility_Certification::$stage='active';
$eligible = $inspect();
$check('static_gates_satisfied_only'===$eligible['reason_code']
    && $eligible['static_certification_ready']
    && $eligible['release_ring_eligible']
    && $eligible['transport_eligible']===null
    && !$eligible['authorizing'] && !$eligible['mutation_performed'],
    'Static inspection incorrectly certified final admission.',$eligible);
MAD4B_SCP_Provider_Compatibility_Certification::$ring =
    MAD4B_SCP_Provider_Transport_Eligibility::R1;
$canary=$inspect();
$check('provider_release_ring_ineligible'===$canary['reason_code']
    && !$canary['release_ring_eligible'] && !$canary['transport_eligible'],
    'Unpromoted canary was treated as general staging transport.',$canary);
MAD4B_SCP_Provider_Compatibility_Certification::$ring =
    MAD4B_SCP_Provider_Transport_Eligibility::R3;
MAD4B_SCP_Site_Profile::$environment = 'production';
$prod=$inspect();
$check('provider_release_ring_ineligible'===$prod['reason_code']
    && !$prod['release_ring_eligible'] && !$prod['authorizing'],
    'Read-only diagnosis widened production release eligibility.',$prod);
MAD4B_SCP_Provider_Circuit_Breaker::$applicable=false;
$not_applicable=$inspect();
$check(!$not_applicable['applicable'] && !$not_applicable['evidence_available']
    && $not_applicable['transport_eligible']===null,
    'Missing provider context was treated as certified.',$not_applicable);

MAD4B_SCP_Provider_Circuit_Breaker::$applicable=true;
MAD4B_SCP_Site_Profile::$environment='staging';
class MAD4B_SCP_Policy {
 static $kill=false; static $allowed=true;
 static function mutation_gate_status() { return array( 'environment'=>'staging', 'explicit_kill_switch'=>self::$kill ); }
 static function can_mutate() { return self::$allowed; }
}
$good_status=array( 'certification_level'=>'REVERSIBLE_WRITE_CERTIFIED', 'activation_stage'=>'active', 'structural_compatible'=>true, 'artifact_authority_bound'=>true );
foreach ( array( 'kill_switch','authority','quarantine','certification','release_ring','breaker' ) as $gate ) {
 MAD4B_SCP_Policy::$kill = 'kill_switch' === $gate;
 MAD4B_SCP_Policy::$allowed = 'authority' !== $gate;
 $status = $good_status;
 if ( 'quarantine' === $gate ) $status['certification_level']='QUARANTINED';
 if ( 'certification' === $gate ) $status['artifact_authority_bound']=false;
 if ( 'release_ring' === $gate ) $status['activation_stage']='canary';
 MAD4B_SCP_Provider_Compatibility_Certification::$status=$status;
 $result=MAD4B_SCP_Provider_Transport_Eligibility::preflight_mutation( 'write', 'fixture/scan' );
 if ( 'breaker' === $gate ) $result=MAD4B_SCP_Provider_Transport_Eligibility::finalize_breaker( $result, array( 'transport_eligible'=>false ) );
 $check( is_wp_error($result) && 'mad4b_provider_transport_precedence_denied' === $result->get_error_code(), 'gate did not remain denied: '.$gate );
 $data=$result->get_error_data();
 $check( $gate === $data['blocking_gate'] && 'not_started' === $data['mutation_state'] && false === $data['target_execution_entered'], 'exact gate or execution boundary lost: '.$gate );
 $check( false === $data['blind_retry_allowed'] && true === $data['fresh_plan_required'] && false === $data['authorizing'], 'diagnostic enabled authorization/replay: '.$gate );
 $check( false !== strpos($result->get_error_message(),$data['reason_code']) && false !== strpos($result->get_error_message(),$gate), 'MCP-visible error masked exact blocker: '.$gate );
 $check( false === strpos($result->get_error_message(),'SECRET-MUST-NOT-LEAK'), 'provider context leaked into visible error' );
}

echo "mad4b.provider-transport-eligibility.runtime.v1: PASS\n";
