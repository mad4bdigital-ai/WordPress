<?php
/** Native, effect-free fixture for the real canonical dependency view. */
define( 'ABSPATH', __DIR__ );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $r ) { return $r instanceof WP_Error; }
function wp_json_encode( $value ) { return json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); }
function wp_has_ability( $ability ) { return 'fixture/missing' !== $ability; }
class MAD4B_SCP_Policy {
    public static function can_read() { return ! empty( $GLOBALS['fixture_can_read'] ); }
}
class MAD4B_SCP_Operation_Registry {
    const CONTRACT = 'mad4b.operation-registry.v1';
    public static $called = array();
    public static function discover( $input ) {
        self::$called[] = 'discover';
        if ( 'fixture-operation' !== $input['operation'] ) return new WP_Error( 'operation_not_registered' );
        return array( 'operation' => array( 'id' => 'fixture-operation', 'pipeline_profile' => 'fixture_pipeline' ),
            'planner' => 'fixture/planner', 'executor' => 'exact_executor_from_plan' );
    }
    public static function pipeline_profile( $name ) {
        self::$called[] = 'pipeline_profile';
        if ( 'fixture_pipeline' !== $name ) return new WP_Error( 'profile_unavailable' );
        return array(
            array( 'id' => 'inspect', 'type' => 'read_check', 'required' => true ),
            array( 'id' => 'plan', 'type' => 'planner', 'required' => true, 'when' => 'input.mode:manual' ),
            array( 'id' => 'approval', 'type' => 'authorization', 'required' => true ),
            array( 'id' => 'apply', 'type' => 'executor', 'required' => true ),
            array( 'id' => 'verify', 'type' => 'verification', 'required' => true ),
        );
    }
    public static function stage_binding( $id, $operation ) {
        self::$called[] = 'stage_binding';
        $bindings = array(
            'inspect' => array( 'binding_type' => 'ability', 'ability' => 'fixture/missing' ),
            'plan' => array( 'binding_type' => 'ability', 'ability' => 'fixture/planner' ),
            'approval' => array( 'binding_type' => 'policy_boundary', 'ability' => '' ),
            'apply' => array( 'binding_type' => 'plan_bound_executor', 'ability' => '' ),
            'verify' => array( 'binding_type' => 'executor_owned_verification', 'ability' => '' ),
        );
        return $bindings[$id] ?? new WP_Error( 'binding_unavailable' );
    }
}
require __DIR__ . '/../includes/class-mad4b-scp-capability-dependency-planner.php';
$assert = static function ( $value, $message ) { if ( ! $value ) throw new RuntimeException( $message ); };
$GLOBALS['fixture_can_read'] = true;
$plan = MAD4B_SCP_Capability_Dependency_Planner::inspect( array( 'operation' => 'fixture-operation' ) );
$assert( ! is_wp_error( $plan ), 'Existing canonical operation could not be read' );
$assert( $plan['state'] === 'REGISTERED_DEPENDENCIES_MISSING', 'Missing registered ability was hidden' );
$assert( $plan['missing_registered_abilities'] === array( 'fixture/missing' ), 'Missing ability not reported exactly' );
$assert( count( $plan['nodes'] ) === 5 && count( $plan['edges'] ) === 4, 'Pipeline DAG was not preserved' );
$assert( in_array( 'apply:exact_plan_executor_required', $plan['unresolved_dependencies'], true ), 'Unbound executor became available' );
$assert( in_array( 'plan:conditional_input_required', $plan['unresolved_dependencies'], true ), 'Conditional dependency was evaluated implicitly' );
$assert( $plan['nodes'][2]['separate_governance_required'] && $plan['nodes'][3]['separate_governance_required'], 'Write authority boundary was removed' );
$assert( $plan['nodes'][4]['independent_readback_required'], 'Postcondition readback was removed' );
$assert( !$plan['execution_ready'] && !$plan['approval_issued'] && !$plan['mutation_performed'], 'Planning granted authority' );
$assert( is_wp_error( MAD4B_SCP_Capability_Dependency_Planner::inspect( array( 'operation' => 'other' ) ) ), 'Unregistered operation was accepted' );
$GLOBALS['fixture_can_read'] = false;
$assert( is_wp_error( MAD4B_SCP_Capability_Dependency_Planner::inspect( array( 'operation' => 'fixture-operation' ) ) ), 'Unauthenticated dependency discovery was accepted' );
echo "PASS unified dependency plan: canonical pipeline, safe edges, missing abilities, conditional/approval/readback boundaries\\n";
