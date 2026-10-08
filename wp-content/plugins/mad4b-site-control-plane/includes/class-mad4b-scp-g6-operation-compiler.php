<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-mad4b-scp-g6-contracts.php';
require_once __DIR__ . '/class-mad4b-scp-g6-durable-dag-evidence.php';

/** Only reviewed PHP implementations may plan one typed primitive. No callback strings. */
interface MAD4B_SCP_G6_Operation_Strategy {
	public function strategy_id();
	public function primitives();
	public function prepare( $primitive, array $arguments, array $context );
}

/**
 * Schema-derived bounded DAG compiler. Compilation and candidates never grant
 * authority, mount a tool, publish, or choose a replacement executor silently.
 */
final class MAD4B_SCP_G6_Operation_Compiler {
	const CONTRACT = 'mad4b.compiled-content-operation-plan.v1';
	const MAX_NODES = 32;
	const PRIMITIVES = array( 'content', 'meta', 'taxonomy', 'media', 'seo', 'builder', 'translation', 'publication' );
	private static $strategies = array();

	public static function register_strategy( MAD4B_SCP_G6_Operation_Strategy $strategy ) {
		$id = $strategy->strategy_id();
		if ( ! MAD4B_SCP_G6_Contracts::id( $id ) || count( self::$strategies ) >= 32 || isset( self::$strategies[ $id ] ) ) return MAD4B_SCP_G6_Contracts::error( 'strategy_collision', 'Strategy identity is invalid or already registered.' );
		$primitives = $strategy->primitives();
		if ( ! is_array( $primitives ) || ! $primitives || array_diff( $primitives, self::PRIMITIVES ) ) return MAD4B_SCP_G6_Contracts::error( 'strategy_primitive', 'Strategy declares an unsupported primitive.' );
		self::$strategies[ $id ] = $strategy;
		return true;
	}
	/** Failed/blocked jobs cannot quietly restart work through a compiled plan. */
	public static function compilable_job_state( $state ) {
		return in_array( $state, array( 'NEW', 'QUEUED', 'RUNNING', 'WAITING_REVIEW' ), true );
	}

	public static function register_defaults() {
		if ( ! isset( self::$strategies['native-content-experience-v1'] ) ) self::register_strategy( new MAD4B_SCP_G6_Native_Content_Strategy() );
	}

	public static function candidate( $input = array() ) {
		$owner = MAD4B_SCP_G6_Contracts::owner(); if ( is_wp_error( $owner ) ) return $owner;
		if ( ! class_exists( 'MAD4B_SCP_Content_Experience_Profiles' ) ) return MAD4B_SCP_G6_Contracts::error( 'profile_service_missing', 'Content Experience service is unavailable.' );
		$post_type = isset( $input['post_type'] ) ? $input['post_type'] : '';
		if ( ! MAD4B_SCP_G6_Contracts::id( $post_type ) ) return MAD4B_SCP_G6_Contracts::error( 'candidate_type', 'Exact registered post type is required.' );
		$native = MAD4B_SCP_Content_Experience_Profiles::bootstrap_plan( array( 'post_type' => $post_type, 'taxonomy_strategy' => 'public_assignable', 'creation_status' => 'draft', 'live_update_mode' => 'draft_first' ) );
		if ( is_wp_error( $native ) ) return $native;
		$fields = array();
		foreach ( (array) get_registered_meta_keys( 'post', $post_type ) as $key => $schema ) {
			// Schema presence is an observation, never field permission or executor admission.
			if ( 0 === strpos( $key, '_' ) || preg_match( '/secret|token|password|credential|api.?key/i', $key ) ) continue;
			if ( count( $fields ) >= 128 ) break;
			$fields[ $key ] = array( 'type' => isset( $schema['type'] ) ? $schema['type'] : 'unknown', 'single' => ! empty( $schema['single'] ), 'schema_sha256' => MAD4B_SCP_G6_Contracts::digest( array( 'type' => isset( $schema['type'] ) ? $schema['type'] : 'unknown', 'single' => ! empty( $schema['single'] ) ) ), 'permission_inferred' => false );
		}
		ksort( $fields, SORT_STRING );
		$candidate = array( 'contract' => 'mad4b.schema-derived-content-candidate.v1', 'post_type' => $post_type, 'native_bootstrap_plan' => $native, 'observed_meta' => $fields, 'bindings' => array( 'content_taxonomy_media' => 'existing_content_experience_profile_review_required', 'seo' => 'admitted_strategy_required', 'language' => 'admitted_strategy_required', 'builder' => 'admitted_strategy_required' ), 'business_hardcode' => false, 'enabled' => false, 'authority_created' => false, 'mutation_performed' => false );
		$candidate['candidate_sha256'] = MAD4B_SCP_G6_Contracts::digest( $candidate );
		return $candidate;
	}

	public static function compile( $input = array() ) {
		$bounded = MAD4B_SCP_G6_Contracts::untrusted_arguments( $input ); if ( is_wp_error( $bounded ) ) return $bounded;
		$owner = MAD4B_SCP_G6_Contracts::owner(); if ( is_wp_error( $owner ) ) return $owner;
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'job_id', 'expected_job_revision', 'profile_slug', 'nodes', 'reason', 'workflow' ) ) ) return MAD4B_SCP_G6_Contracts::error( 'compile_schema', 'Compiler accepts only typed job, profile and DAG fields.' );
		if ( ! class_exists( 'MAD4B_SCP_Content_Jobs' ) || ! class_exists( 'MAD4B_SCP_Content_Experience_Profiles' ) ) return MAD4B_SCP_G6_Contracts::error( 'compiler_services_missing', 'ContentJob and Content Experience services are required.' );
		$job = MAD4B_SCP_Content_Jobs::get_job( array( 'job_id' => isset( $input['job_id'] ) ? $input['job_id'] : '' ) );
		if ( is_wp_error( $job ) ) return $job;
		$job = isset( $job['job'] ) && is_array( $job['job'] ) ? $job['job'] : $job;
		$revision = isset( $input['expected_job_revision'] ) ? $input['expected_job_revision'] : null;
		if ( ! is_int( $revision ) || $revision < 1 || $revision !== (int) $job['job_revision'] ) return MAD4B_SCP_G6_Contracts::error( 'job_revision', 'Exact ContentJob revision changed or is missing.' );
		if ( in_array( $job['state'], array( 'CANCELLED', 'COMPLETED' ), true ) ) return MAD4B_SCP_G6_Contracts::error( 'job_terminal', 'Terminal jobs cannot receive a mutable operation plan.' );
		if ( ! self::compilable_job_state( $job['state'] ) ) return MAD4B_SCP_G6_Contracts::error( 'job_recovery_required', 'Blocked or failed ContentJobs require an explicit lifecycle recovery before recompilation.' );
		$profile = MAD4B_SCP_Content_Experience_Profiles::profile( isset( $input['profile_slug'] ) ? $input['profile_slug'] : '' );
		if ( is_wp_error( $profile ) ) return $profile;
		if ( empty( $profile['enabled'] ) || ( isset( $job['target_post_type'] ) && '' !== $job['target_post_type'] && $job['target_post_type'] !== $profile['post_type'] ) ) return MAD4B_SCP_G6_Contracts::error( 'profile_job_mismatch', 'Enabled profile must match the job target post type.' );
		$binding = MAD4B_SCP_G6_Contracts::binding( MAD4B_SCP_G6_Contracts::digest( $profile ) ); if ( is_wp_error( $binding ) ) return $binding;
		if ( isset( $job['site_uuid'] ) && $job['site_uuid'] !== $binding['site_uuid'] ) return MAD4B_SCP_G6_Contracts::error( 'job_site_mismatch', 'ContentJob belongs to another site.' );
		$nodes = isset( $input['nodes'] ) ? $input['nodes'] : null;
		if ( ! is_array( $nodes ) || ! $nodes || count( $nodes ) > self::MAX_NODES || array_keys( $nodes ) !== range( 0, count( $nodes ) - 1 ) ) return MAD4B_SCP_G6_Contracts::error( 'dag_budget', 'DAG requires 1–32 typed nodes.' );
		$indexed = array(); $edges = 0;
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || array_diff( array_keys( $node ), array( 'node_id', 'primitive', 'strategy_id', 'arguments', 'depends_on' ) ) || empty( $node['node_id'] ) || ! MAD4B_SCP_G6_Contracts::id( $node['node_id'] ) || isset( $indexed[ $node['node_id'] ] ) ) return MAD4B_SCP_G6_Contracts::error( 'node_schema', 'Node identities and fields must be typed and unique.' );
			if ( ! isset( $node['primitive'] ) || ! in_array( $node['primitive'], self::PRIMITIVES, true ) || ! isset( $node['strategy_id'], self::$strategies[ $node['strategy_id'] ] ) || ! in_array( $node['primitive'], self::$strategies[ $node['strategy_id'] ]->primitives(), true ) ) return MAD4B_SCP_G6_Contracts::error( 'strategy_missing', 'Primitive requires a registered reviewed strategy.' );
			$deps = isset( $node['depends_on'] ) ? $node['depends_on'] : array();
			if ( ! is_array( $deps ) || count( $deps ) > self::MAX_NODES || array_unique( $deps ) !== $deps ) return MAD4B_SCP_G6_Contracts::error( 'dependency_schema', 'Dependencies must be unique node identities.' );
			foreach ( $deps as $dep ) if ( ! MAD4B_SCP_G6_Contracts::id( $dep ) || $dep === $node['node_id'] ) return MAD4B_SCP_G6_Contracts::error( 'dag_cycle', 'Self dependencies and malformed dependencies are denied.' );
			$edges += count( $deps ); if ( $edges > 128 ) return MAD4B_SCP_G6_Contracts::error( 'dag_budget', 'DAG exceeds the dependency budget.' );
			if ( ! isset( $node['arguments'] ) || ! is_array( $node['arguments'] ) ) return MAD4B_SCP_G6_Contracts::error( 'node_arguments', 'Exact typed arguments are required; symbolic execution inputs are not admitted.' );
			sort( $deps, SORT_STRING ); $node['depends_on'] = $deps; $indexed[ $node['node_id'] ] = $node;
		}
		ksort( $indexed, SORT_STRING );
		foreach ( $indexed as $node ) foreach ( $node['depends_on'] as $dep ) if ( ! isset( $indexed[ $dep ] ) ) return MAD4B_SCP_G6_Contracts::error( 'dependency_missing', 'DAG references a missing node.' );
		$order = array(); $pending = $indexed;
		while ( $pending ) {
			$advanced = false;
			foreach ( $pending as $id => $node ) if ( ! array_diff( $node['depends_on'], $order ) ) { $order[] = $id; unset( $pending[ $id ] ); $advanced = true; }
			if ( ! $advanced ) return MAD4B_SCP_G6_Contracts::error( 'dag_cycle', 'Circular operation plans are denied.' );
		}
		$context = array( 'binding' => $binding, 'profile' => $profile, 'job' => $job, 'owner_user_id' => $owner );
		$prepared = array(); $permissions = array(); $effects = array(); $objects = array();
		foreach ( $order as $id ) {
			$node = $indexed[ $id ];
			$step = self::$strategies[ $node['strategy_id'] ]->prepare( $node['primitive'], $node['arguments'], $context );
			if ( is_wp_error( $step ) ) return $step;
			$valid = self::validate_step( $step, $node ); if ( is_wp_error( $valid ) ) return $valid;
			foreach ( $step['permissions'] as $permission ) {
				$object_id = isset( $permission['object_id'] ) ? $permission['object_id'] : 0;
				if ( ! current_user_can( $permission['capability'], $object_id ) ) return MAD4B_SCP_G6_Contracts::error( 'permission_aggregation', 'Every scoped node permission is required; combining nodes cannot widen authority.' );
				$permissions[ MAD4B_SCP_G6_Contracts::digest( $permission ) ] = $permission;
			}
			foreach ( $step['effects'] as $effect ) $effects[ MAD4B_SCP_G6_Contracts::digest( $effect ) ] = $effect;
			foreach ( $step['object_pins'] as $object ) $objects[ MAD4B_SCP_G6_Contracts::digest( $object ) ] = $object;
			$step['node_id'] = $id; $step['primitive'] = $node['primitive']; $step['strategy_id'] = $node['strategy_id']; $step['depends_on'] = $node['depends_on'];
			$step['arguments_sha256'] = MAD4B_SCP_G6_Contracts::digest( $node['arguments'] );
			$step['diffs'] = MAD4B_SCP_G6_Contracts::diff( isset( $step['before'] ) ? $step['before'] : null, isset( $step['after'] ) ? $step['after'] : $step['typed_input'] );
			unset( $step['before'], $step['after'] );
			$step['step_sha256'] = MAD4B_SCP_G6_Contracts::digest( $step ); $prepared[ $id ] = $step;
		}
		ksort( $permissions, SORT_STRING ); ksort( $effects, SORT_STRING ); ksort( $objects, SORT_STRING );
		$workflow = null;
		if ( isset( $input['workflow'] ) ) {
			if ( ! is_array( $input['workflow'] ) || array_diff( array_keys( $input['workflow'] ), array( 'provider', 'workflow_ref', 'expected_workflow_sha256' ) ) || ! class_exists( 'MAD4B_SCP_Workflow_Providers' ) ) return MAD4B_SCP_G6_Contracts::error( 'workflow_schema', 'Existing workflow reference and provider must be typed.' );
			$workflow = MAD4B_SCP_Workflow_Providers::plan( array_merge( $input['workflow'], array( 'operation' => 'execute', 'reason' => 'Pinned compiled ContentJob handoff' ) ) );
			if ( is_wp_error( $workflow ) ) return $workflow;
		}
		$plan = array( 'contract' => self::CONTRACT, 'binding' => $binding, 'owner_user_id' => $owner, 'job_id' => $job['job_id'], 'job_revision' => $revision, 'job_state' => $job['state'], 'compile_input' => $input, 'input_sha256' => MAD4B_SCP_G6_Contracts::digest( $input ), 'schema_sha256' => MAD4B_SCP_G6_Contracts::digest( array( 'primitives' => self::PRIMITIVES, 'steps' => array_column( $prepared, 'schema_sha256' ) ) ), 'nodes' => $prepared, 'topological_order' => $order, 'permissions' => array_values( $permissions ), 'effects' => array_values( $effects ), 'object_pins' => array_values( $objects ), 'workflow_handoff' => $workflow, 'workflow_import_performed' => false, 'contentjob_handoff' => array( 'ability' => 'mad4b/content-artifact-append', 'artifact_type' => 'blueprint', 'producer_stage' => 'BLUEPRINT', 'job_id' => $job['job_id'], 'expected_job_revision' => $revision ), 'compensation_boundary' => 'separate_exact_reversal_plan_with_retained_native_prestate_and_current_authority', 'publication_authority_inherited' => false, 'approval_required' => true, 'autonomy_level' => 1, 'authorizing' => false, 'mutation_performed' => false );
		$plan['plan_sha256'] = MAD4B_SCP_G6_Contracts::digest( $plan );
		return $plan;
	}


	/**
	 * A review-safe projection: caller material, draft text, private object IDs,
	 * workflow payloads, diff paths and arbitrary strategy fields never leave here.
	 * The raw compiled plan remains an internal governed-coordinator object.
	 */
	public static function review_projection( array $plan ) {
		if ( ! MAD4B_SCP_G6_Contracts::assert_digest( $plan, 'plan_sha256', self::CONTRACT ) ) return MAD4B_SCP_G6_Contracts::error( 'plan_digest', 'Review requires a complete unmodified compiled plan.' );
		$owner = MAD4B_SCP_G6_Contracts::owner(); if ( is_wp_error( $owner ) ) return $owner;
		if ( ! isset( $plan['owner_user_id'] ) || (int) $plan['owner_user_id'] !== $owner ) return MAD4B_SCP_G6_Contracts::error( 'plan_owner', 'The compiled plan belongs to a different administrator.' );
		$items = array();
		foreach ( $plan['nodes'] as $id => $step ) {
			$diffs = array();
			foreach ( (array) $step['diffs'] as $diff ) {
				$diffs[] = array(
					'path_sha256' => MAD4B_SCP_G6_Contracts::digest( isset( $diff['path'] ) ? $diff['path'] : '' ),
					'change_type' => isset( $diff['change_type'] ) ? $diff['change_type'] : 'modified',
					'before_sha256' => $diff['before_sha256'],
					'after_sha256' => $diff['after_sha256'],
					'values_redacted' => true,
					'path_redacted' => true,
				);
			}
			$effects = array();
			foreach ( (array) $step['effects'] as $effect ) $effects[] = array( 'kind' => $effect['kind'], 'reversibility' => $effect['reversibility'] );
			$permissions = array();
			foreach ( (array) $step['permissions'] as $permission ) $permissions[] = array( 'capability' => $permission['capability'], 'object_ref_sha256' => MAD4B_SCP_G6_Contracts::digest( isset( $permission['object_id'] ) ? $permission['object_id'] : null ) );
			$items[] = array(
				'node_ref_sha256' => MAD4B_SCP_G6_Contracts::digest( $id ),
				'primitive' => $step['primitive'],
				'strategy_id' => $step['strategy_id'],
				'provider_id' => $step['provider_id'],
				'capability_id' => $step['capability_id'],
				'arguments_sha256' => $step['arguments_sha256'],
				'schema_sha256' => $step['schema_sha256'],
				'provider_binding_sha256' => $step['provider_binding_sha256'],
				'native_plan_sha256' => $step['native_plan_sha256'],
				'step_sha256' => $step['step_sha256'],
				'dependencies_sha256' => MAD4B_SCP_G6_Contracts::digest( $step['depends_on'] ),
				'object_state_pins_sha256' => MAD4B_SCP_G6_Contracts::digest( $step['object_pins'] ),
				'permissions' => $permissions,
				'effects' => $effects,
				'diffs' => $diffs,
				'raw_input_exposed' => false,
			);
		}
		$view = array(
			'contract' => 'mad4b.compiled-content-operation-review.v1',
			'plan_sha256' => $plan['plan_sha256'],
			'binding_sha256' => MAD4B_SCP_G6_Contracts::digest( $plan['binding'] ),
			'job_ref_sha256' => MAD4B_SCP_G6_Contracts::digest( $plan['job_id'] ),
			'job_revision' => $plan['job_revision'],
			'job_state' => $plan['job_state'],
			'input_sha256' => $plan['input_sha256'],
			'schema_sha256' => $plan['schema_sha256'],
			'steps' => $items,
			'workflow_handoff_sha256' => MAD4B_SCP_G6_Contracts::digest( $plan['workflow_handoff'] ),
			'approval_required' => true,
			'authorizing' => false,
			'mutation_performed' => false,
			'private_values_exposed' => false,
			'plan_usable_as_execution_authority' => false,
		);
		$view['review_sha256'] = MAD4B_SCP_G6_Contracts::digest( $view );
		return $view;
	}

	public static function preview( $input = array() ) {
		self::register_defaults();
		$plan = self::compile( $input );
		return is_wp_error( $plan ) ? $plan : self::review_projection( $plan );
	}

	private static function validate_step( $step, array $node ) {
		if ( ! is_array( $step ) ) return MAD4B_SCP_G6_Contracts::error( 'step_contract', 'Strategy returned no typed operation plan.' );
		$budget = MAD4B_SCP_G6_Contracts::data( $step ); if ( is_wp_error( $budget ) ) return $budget;
		foreach ( array( 'provider_id', 'capability_id', 'ability_name', 'typed_input', 'schema_sha256', 'provider_binding_sha256', 'native_plan_sha256', 'object_pins', 'permissions', 'effects', 'compensation' ) as $field ) if ( ! isset( $step[ $field ] ) ) return MAD4B_SCP_G6_Contracts::error( 'step_contract', 'Strategy omitted exact planning evidence.' );
		foreach ( array( 'schema_sha256', 'provider_binding_sha256', 'native_plan_sha256' ) as $field ) if ( ! MAD4B_SCP_G6_Contracts::sha( $step[ $field ] ) ) return MAD4B_SCP_G6_Contracts::error( 'step_pins', 'Exact schema/provider/native plan pins are required.' );
		if ( ! is_array( $step['typed_input'] ) || ! is_array( $step['object_pins'] ) || ! $step['object_pins'] || ! is_array( $step['permissions'] ) || ! $step['permissions'] || ! is_array( $step['effects'] ) || ! $step['effects'] ) return MAD4B_SCP_G6_Contracts::error( 'step_scope', 'Object pins, permissions and effects cannot be implicit.' );
		if ( ! function_exists( 'wp_get_ability' ) || ! is_object( wp_get_ability( $step['ability_name'] ) ) ) return MAD4B_SCP_G6_Contracts::error( 'provider_removed', 'Planned existing provider ability is not registered.' );
		foreach ( $step['object_pins'] as $pin ) if ( ! is_array( $pin ) || empty( $pin['resource_id'] ) || '*' === $pin['resource_id'] || ! isset( $pin['state_sha256'] ) || ! MAD4B_SCP_G6_Contracts::sha( $pin['state_sha256'] ) ) return MAD4B_SCP_G6_Contracts::error( 'object_scope', 'Every step requires an exact resource and state pin.' );
		foreach ( $step['permissions'] as $permission ) if ( ! is_array( $permission ) || empty( $permission['capability'] ) || ! MAD4B_SCP_G6_Contracts::id( $permission['capability'] ) || '*' === $permission['capability'] ) return MAD4B_SCP_G6_Contracts::error( 'permission_aggregation', 'Wildcard or unknown permissions cannot be aggregated.' );
		$publication = false;
		foreach ( $step['effects'] as $effect ) {
			if ( ! is_array( $effect ) || empty( $effect['kind'] ) || ! in_array( $effect['kind'], array( 'local_content', 'media_reference', 'publication', 'external_read', 'external_write' ), true ) || ! isset( $effect['reversibility'] ) || ! in_array( $effect['reversibility'], array( 'reversible', 'irreversible' ), true ) ) return MAD4B_SCP_G6_Contracts::error( 'unknown_effect', 'Unknown, financial, webhook and authority effects require another reviewed executor.' );
			if ( 'publication' === $effect['kind'] ) $publication = true;
		}
		if ( $publication && 'publication' !== $node['primitive'] ) return MAD4B_SCP_G6_Contracts::error( 'hidden_publication', 'Publication is a distinct explicitly reviewed primitive.' );
		if ( 'publication' === $node['primitive'] && ! $publication ) return MAD4B_SCP_G6_Contracts::error( 'publication_contract', 'Publication requires an explicit effect declaration.' );
		return true;
	}

	public static function revalidate( $input = array() ) {
		$plan = isset( $input['plan'] ) && is_array( $input['plan'] ) ? $input['plan'] : array();
		if ( ! MAD4B_SCP_G6_Contracts::assert_digest( $plan, 'plan_sha256', self::CONTRACT ) ) return MAD4B_SCP_G6_Contracts::error( 'plan_digest', 'Compiled plan was changed or belongs to another contract.' );
		if ( (int) get_current_user_id() !== (int) $plan['owner_user_id'] ) return MAD4B_SCP_G6_Contracts::error( 'plan_owner', 'Compiled plan belongs to another administrator.' );
		$current = self::compile( $plan['compile_input'] );
		if ( is_wp_error( $current ) ) return MAD4B_SCP_G6_Contracts::error( 'replan_required', 'Provider, object, permission or job is no longer eligible.', array( 'cause' => $current->get_error_code(), 'approval_invalidated' => true ) );
		if ( ! hash_equals( $plan['plan_sha256'], $current['plan_sha256'] ) ) return MAD4B_SCP_G6_Contracts::error( 'replan_required', 'Plan semantics changed; create and approve a new exact plan.', array( 'current_plan_sha256' => $current['plan_sha256'], 'diff_summary_sha256' => MAD4B_SCP_G6_Contracts::digest( MAD4B_SCP_G6_Contracts::diff( $plan, $current ) ), 'diff_paths_redacted' => true, 'approval_invalidated' => true ) );
		return array( 'contract' => 'mad4b.compiled-content-plan-revalidation.v1', 'valid' => true, 'plan_sha256' => $plan['plan_sha256'], 'authorizing' => false );
	}

	/**
	 * Internal coordinator seam, deliberately absent from read Ability registration.
	 * The caller must already be inside the existing governed execution frame;
	 * the exact child keeps permission, NHI, ticket, budget and commit fences.
	 */
	public static function dispatch_step( array $plan, $node_id, array $execution_evidence = array() ) {
		if ( ! class_exists( 'MAD4B_SCP_Execution_Fence' ) || ! class_exists( 'MAD4B_SCP_Durable_Execution' ) || ! class_exists( 'MAD4B_SCP_Execution_Commit_Guard' ) || ! MAD4B_SCP_Execution_Fence::has_active_frame() ) return MAD4B_SCP_G6_Contracts::error( 'execution_frame_required', 'Compiled execution needs an existing governed coordinator frame and durable fences.' );
		if ( 'staging' !== wp_get_environment_type() ) return MAD4B_SCP_G6_Contracts::error( 'staging_only', 'Optional compiled dispatch is limited to existing Staging authority.' );
		$valid = self::revalidate( array( 'plan' => $plan ) ); if ( is_wp_error( $valid ) ) return $valid;
		if ( ! isset( $plan['nodes'][ $node_id ] ) ) return MAD4B_SCP_G6_Contracts::error( 'step_missing', 'Exact compiled node is missing.' );
		$step = $plan['nodes'][ $node_id ];
		if ( 'RUNNING' !== $plan['job_state'] ) return MAD4B_SCP_G6_Contracts::error( 'job_not_running', 'A currently RUNNING ContentJob is required before compiled dispatch.' );
		// Dependency completion must be bound through the existing workflow journal.
		// No caller-supplied success flags or fabricated receipts advance a DAG.
		if ( $step['depends_on'] ) {
			$observed = MAD4B_SCP_G6_Durable_DAG_Evidence::inspect( $plan, $node_id );
			if ( is_wp_error( $observed ) ) return $observed;
			return MAD4B_SCP_G6_Contracts::error( 'dependency_postcondition_required', 'Durable records are not provider postcondition evidence; fresh approval and provider readback remain required.', array( 'durable_evidence_sha256' => $observed['evidence_sha256'] ) );
		}
		if ( array_diff( array_keys( $execution_evidence ), array( '_mad4b_approval_ticket_id', '_mad4b_context_receipt' ) ) ) return MAD4B_SCP_G6_Contracts::error( 'evidence_schema', 'Only existing opaque approval/context evidence can be forwarded.' );
		$name = $step['ability_name'];
		if ( ! MAD4B_SCP_Execution_Fence::final_execution_wrapper_verified( $name ) ) return MAD4B_SCP_G6_Contracts::error( 'execution_boundary_missing', 'Exact existing child ability lacks its verified execution boundary.' );
		$input = array_merge( $step['typed_input'], $execution_evidence );
		$input_sha = MAD4B_SCP_G6_Contracts::digest( $step['typed_input'] );
		$scope = MAD4B_SCP_Durable_Execution::scope_key( $plan['binding']['site_uuid'], $step['capability_id'], 'compiled_step', $plan['job_id'] . ':' . $node_id );
		$claim = MAD4B_SCP_Durable_Execution::begin_idempotency( $scope, $plan['plan_sha256'] . ':' . $node_id, $input_sha ); if ( is_wp_error( $claim ) ) return $claim;
		if ( empty( $claim['claimed'] ) ) return isset( $claim['result'] ) ? $claim['result'] : MAD4B_SCP_G6_Contracts::error( 'idempotency_result_missing', 'Completed step result cannot be resolved.' );
		try {
			$result = MAD4B_SCP_Execution_Fence::with_governed_child( $name, $input, static function () use ( $name, $input ) { return wp_get_ability( $name )->execute( $input ); }, 'compiled_content_step' );
		} catch ( Throwable $error ) { return MAD4B_SCP_G6_Contracts::error( 'execution_uncertain', 'Child execution failed; durable reconciliation is required.' ); }
		if ( is_wp_error( $result ) ) return MAD4B_SCP_G6_Contracts::error( 'execution_uncertain', 'Child failed or rejected execution; reconcile before another dispatch.', array( 'cause' => $result->get_error_code(), 'reconciliation_required' => true ) );
		$completed = MAD4B_SCP_Durable_Execution::complete_idempotency( $claim, $result );
		return is_wp_error( $completed ) ? $completed : $result;
	}
}

/** Uses the existing native Content Experience planner and generated governed routes. */
final class MAD4B_SCP_G6_Native_Content_Strategy implements MAD4B_SCP_G6_Operation_Strategy {
	public function strategy_id() { return 'native-content-experience-v1'; }
	public function primitives() { return array( 'content', 'meta', 'taxonomy', 'media', 'publication' ); }
	public function prepare( $primitive, array $arguments, array $context ) {
		if ( ! class_exists( 'MAD4B_SCP_Content_Experience_Runtime' ) ) return MAD4B_SCP_G6_Contracts::error( 'native_service_missing', 'Native content planner is unavailable.' );
		$profile = $context['profile']; $operation = 'publication' === $primitive ? 'publish' : 'update';
		$allowed = array( 'post_id', 'expected_modified_gmt' );
		if ( 'content' === $primitive ) $allowed = array_merge( $allowed, array( 'post_title', 'post_content', 'post_excerpt' ) );
		if ( 'meta' === $primitive ) $allowed[] = 'meta';
		if ( 'taxonomy' === $primitive ) $allowed[] = 'taxonomies';
		if ( 'media' === $primitive ) $allowed = array_merge( $allowed, array( 'featured_media_id', 'meta', 'expected_remote_media_state_sha256', 'expected_media_manifest_sha256', 'expected_media_manifest_item_count', 'expected_media_recovery_receipt_sha256', 'expected_media_binding_state_sha256' ) );
		if ( 'publication' === $primitive ) $allowed[] = 'post_status';
		if ( array_diff( array_keys( $arguments ), $allowed ) || empty( $arguments['post_id'] ) || ! is_int( $arguments['post_id'] ) ) return MAD4B_SCP_G6_Contracts::error( 'native_arguments', 'Native primitive requires exact bounded fields and an existing post ID.' );
		if ( 'media' === $primitive && isset( $arguments['meta'] ) && array_diff( array_keys( $arguments['meta'] ), array_keys( isset( $profile['media_meta_fields'] ) ? $profile['media_meta_fields'] : array() ) ) ) return MAD4B_SCP_G6_Contracts::error( 'media_fields', 'Media primitive only binds configured media fields.' );
		$object = get_post( $arguments['post_id'] );
		if ( ! is_object( $object ) || $object->post_type !== $profile['post_type'] || ! current_user_can( 'edit_post', $object->ID ) ) return MAD4B_SCP_G6_Contracts::error( 'native_object', 'Exact post is not editable under this profile.' );
		if ( 'publication' !== $primitive && in_array( $object->post_status, array( 'publish', 'future', 'private' ), true ) ) return MAD4B_SCP_G6_Contracts::error( 'live_target_requires_publication', 'Compiled content changes require a draft target; live publication needs a separate exact publication plan.' );
		$plan = MAD4B_SCP_Content_Experience_Runtime::operation_plan( $profile['slug'], $operation, $arguments ); if ( is_wp_error( $plan ) ) return $plan;
		$routes = isset( $profile['routes'] ) ? $profile['routes'] : MAD4B_SCP_Content_Experience_Profiles::profile_routes( $profile['slug'], $profile['revision'] );
		$ability = isset( $routes[ $operation . '_apply' ] ) ? $routes[ $operation . '_apply' ] : '';
		$ability_object = function_exists( 'wp_get_ability' ) ? wp_get_ability( $ability ) : null;
		if ( ! is_object( $ability_object ) || ! method_exists( $ability_object, 'get_input_schema' ) ) return MAD4B_SCP_G6_Contracts::error( 'provider_removed', 'Existing native governed ability is unavailable.' );
		$permissions = array( array( 'capability' => 'edit_post', 'object_id' => (int) $object->ID ) );
		if ( 'publication' === $primitive ) $permissions[] = array( 'capability' => MAD4B_SCP_Content_Experience_Profiles::post_type_publish_cap( get_post_type_object( $profile['post_type'] ) ), 'object_id' => 0 );
		$typed = $arguments; $typed['plan_sha256'] = $plan['plan_sha256'];
		return array( 'provider_id' => 'native_content_experience', 'capability_id' => 'content_experience.' . $operation, 'ability_name' => $ability, 'typed_input' => $typed, 'schema_sha256' => MAD4B_SCP_G6_Contracts::digest( $ability_object->get_input_schema() ), 'provider_binding_sha256' => MAD4B_SCP_G6_Contracts::digest( array( 'profile' => $profile, 'runtime_artifact' => hash_file( 'sha256', __DIR__ . '/class-mad4b-scp-content-experience-runtime.php' ), 'strategy_artifact' => hash_file( 'sha256', __FILE__ ) ) ), 'native_plan_sha256' => $plan['plan_sha256'], 'object_pins' => array( array( 'resource_id' => 'post:' . $object->ID, 'state_sha256' => $plan['current_state_sha256'] ) ), 'permissions' => $permissions, 'effects' => array( array( 'kind' => 'publication' === $primitive ? 'publication' : ( 'media' === $primitive ? 'media_reference' : 'local_content' ), 'reversibility' => 'publication' === $primitive ? 'irreversible' : 'reversible', 'external_effects' => 'existing_native_provider_contract_applies' ) ), 'compensation' => array( 'automatic' => false, 'existing_reversal_service_required' => true, 'prestate_retention_required' => true ), 'before' => array( 'object_state_sha256' => $plan['current_state_sha256'] ), 'after' => $plan['normalized_input'] );
	}
}
