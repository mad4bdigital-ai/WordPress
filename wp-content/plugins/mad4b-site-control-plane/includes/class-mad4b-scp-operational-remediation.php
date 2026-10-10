<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Site-neutral, read-only remediation control plane.
 *
 * A discovered capability is not an execution grant. This surface can only
 * observe canonical WordPress evidence, select the owning remediation lane
 * and produce a fresh, build-bound *review* handoff. Every effectful operation
 * remains owned by its separate registered governed adapter.
 */
final class MAD4B_SCP_Operational_Remediation {
	const CONTRACT = 'mad4b.operational-remediation-control.v1';
	const STATUS_ABILITY = 'mad4b/operational-remediation-status';
	const PREPARE_ABILITY = 'mad4b/operational-remediation-prepare';
	const MAX_GATES = 128;
	const MAX_ACTIONS = 256;

	public static function boot() {
		// Reuse the canonical read-only remediation/operation catalog as the
		// parent for an extensible, separately governed MCP workflow bridge.
		if ( ! class_exists( 'MAD4B_SCP_Manual_Workflow_Bridge', false ) )
			require_once __DIR__ . '/class-mad4b-scp-manual-workflow-bridge.php';
		MAD4B_SCP_Manual_Workflow_Bridge::boot();
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 39 );
		if ( class_exists( 'MAD4B_SCP_Admin_Route_Registry', false ) )
			MAD4B_SCP_Admin_Route_Registry::schedule_submenu( array( __CLASS__, 'register_menu' ), 39 );
	}

	public static function register_menu() {
		if ( ! class_exists( 'MAD4B_SCP_Admin_UI', false ) ) return;
		add_submenu_page(
			MAD4B_SCP_Admin_UI::PAGE_SLUG,
			__( 'Operational Remediation', 'mad4b-site-control-plane' ),
			__( 'Operational Remediation', 'mad4b-site-control-plane' ),
			'manage_options',
			'mad4b-operational-remediation',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * A read-only admin view. This is an operator work queue, not an installer,
	 * privileged job runner or one-click approval surface.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) )
			wp_die( esc_html__( 'Administrator capability is required.', 'mad4b-site-control-plane' ), '', array( 'response' => 403 ) );
		$view = self::status( array( 'include_live_acceptance' => false ) );
		echo '<div class="wrap"><h1>' . esc_html__( 'Operational Remediation', 'mad4b-site-control-plane' ) . '</h1>';
		echo '<p>' . esc_html__( 'Observed diagnostic only. Provider installation, host commands, Browser execution, Brand approval, WordPress writes and release acceptance require separate governed steps.', 'mad4b-site-control-plane' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Integrity', 'mad4b-site-control-plane' ) . ':</strong> '
			. esc_html( ! empty( $view['diagnostic_integrity_ready'] ) ? 'Verified observation' : 'Blocked: recheck source and providers' ) . '</p>';
		echo '<p><strong>Source:</strong> <code>' . esc_html( (string) ( $view['source_commit_sha'] ?? '' ) ) . '</code> <strong>Plan:</strong> <code>'
			. esc_html( (string) ( $view['plan_sha256'] ?? '' ) ) . '</code></p>';
		$issues = is_array( $view['plan_integrity_blockers'] ?? null ) ? $view['plan_integrity_blockers'] : array();
		if ( $issues ) echo '<div class="notice notice-error inline"><p>'
			. esc_html( implode( ', ', array_slice( $issues, 0, 15 ) ) ) . '</p></div>';
		echo '<h2>' . esc_html__( 'Unresolved evidence and remediation owners', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>Gate</th><th>Owner</th><th>Remediation</th><th>Executor evidence</th></tr></thead><tbody>';
		$items = is_array( $view['work_items'] ?? null ) ? $view['work_items'] : array();
		foreach ( array_slice( $items, 0, self::MAX_GATES ) as $item ) {
			if ( ! is_array( $item ) ) continue;
			$paths = is_array( $item['paths'] ?? null ) ? $item['paths'] : array();
			echo '<tr><td><code>' . esc_html( (string) ( $item['gate_id'] ?? '' ) ) . '</code></td><td>'
				. esc_html( (string) ( $item['owner'] ?? '' ) ) . '</td><td>';
			foreach ( array_slice( $paths, 0, 10 ) as $action ) {
				if ( ! is_array( $action ) ) continue;
				echo '<p><code>' . esc_html( (string) ( $action['action_id'] ?? '' ) ) . '</code> — '
					. esc_html( (string) ( $action['kind'] ?? '' ) ) . '</p>';
			}
			echo '</td><td>';
			foreach ( array_slice( $paths, 0, 10 ) as $action ) {
				if ( ! is_array( $action ) ) continue;
				echo '<p>' . esc_html( (string) ( $action['apply_ability'] ?? '' ) ) . ': '
					. esc_html( ! empty( $action['apply_registered'] ) ? 'Registered; separate approval required' : 'Missing or external executor' )
					. '</p>';
			}
			echo '</td></tr>';
		}
		if ( ! $items ) echo '<tr><td colspan="4">No blocked gates observed. This is not a release certificate.</td></tr>';
		echo '</tbody></table></div>';
	}
	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_has_ability' ) ) return;
		$definitions = array(
			self::STATUS_ABILITY => array(
				'label' => 'Operational Remediation Status',
				'description' => 'Inspect canonical Staging and optional independent Live Acceptance, provider registration, next steps and missing evidence. No side effects.',
				'callback' => 'status',
				'properties' => array(
					'include_live_acceptance' => array( 'type' => 'boolean', 'default' => false ),
				),
				'required' => array(),
			),
			self::PREPARE_ABILITY => array(
				'label' => 'Prepare Operational Remediation',
				'description' => 'Reject stale plans and select a site-bound governed adapter and independent postcondition. Never executes.',
				'callback' => 'prepare',
				'properties' => array(
					'action_id' => array( 'type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,95}$' ),
					'expected_plan_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
					'expected_source_commit_sha' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{40}$' ),
					'include_live_acceptance' => array( 'type' => 'boolean', 'default' => false ),
				),
				'required' => array( 'action_id', 'expected_plan_sha256', 'expected_source_commit_sha' ),
			),
		);
		foreach ( $definitions as $ability => $definition ) {
			if ( wp_has_ability( $ability ) ) continue;
			wp_register_ability( $ability, array(
				'label' => $definition['label'],
				'description' => $definition['description'],
				'category' => 'mad4b-read',
				'execute_callback' => array( __CLASS__, $definition['callback'] ),
				'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
				'input_schema' => array(
					'type' => 'object',
					'properties' => $definition['properties'],
					'required' => $definition['required'],
					'additionalProperties' => false,
				),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array( 'public' => false, 'type' => 'tool',
						'surface' => 'read', 'non_authorizing' => true ),
					'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
				),
			) );
		}
	}

	/** Two independent observations. Disagreement is explicitly blocked. */
	public static function status( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		if ( ! class_exists( 'MAD4B_SCP_Staging_Certification', false ) )
			return self::denied( 'staging_certification_unavailable' );
		$live = ! empty( $input['include_live_acceptance'] );
		$plan = MAD4B_SCP_Staging_Certification::convergence_plan( array(
			'include_live_acceptance' => $live,
			'include_authoritative_content' => false,
			'include_rendered_frontend' => false,
		) );
		$native = MAD4B_SCP_Staging_Certification::status( array( 'compact' => true ) );
		return self::reduce( $native, is_array( $plan ) ? $plan : array(), $live );
	}

	public static function prepare( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$id = isset( $input['action_id'] ) ? (string) $input['action_id'] : '';
		$sha = isset( $input['expected_plan_sha256'] ) ? (string) $input['expected_plan_sha256'] : '';
		$source = isset( $input['expected_source_commit_sha'] ) ? (string) $input['expected_source_commit_sha'] : '';
		if ( ! preg_match( '/^[a-z][a-z0-9_]{0,95}$/D', $id )
			|| ! preg_match( '/^[a-f0-9]{64}$/D', $sha )
			|| ! preg_match( '/^[a-f0-9]{40}$/D', $source ) )
			return self::denied( 'invalid_exact_action_identity' );
		if ( ! class_exists( 'MAD4B_SCP_Staging_Certification', false ) )
			return self::denied( 'staging_certification_unavailable' );
		$plan = MAD4B_SCP_Staging_Certification::convergence_plan( array(
			'include_live_acceptance' => ! empty( $input['include_live_acceptance'] ),
			'include_authoritative_content' => false,
			'include_rendered_frontend' => false,
		) );
		return self::prepare_from_plan( is_array( $plan ) ? $plan : array(), $id, $sha, $source );
	}

	/**
	 * Pure plan reducer for an independent second canonical observation.
	 * It does not accept caller-provided "ready" flags as release evidence.
	 */
	public static function reduce( array $native, array $plan, $live_requested = false ) {
		$binding = isset( $plan['plan_binding'] ) && is_array( $plan['plan_binding'] )
			? $plan['plan_binding'] : array();
		$plan_sha = (string) ( $plan['plan_sha256'] ?? '' );
		$source_sha = (string) ( $binding['source_commit_sha'] ?? '' );
		$identity = ! empty( $binding['nonproduction_site_ready'] )
			&& 'staging' === (string) ( $binding['environment'] ?? '' )
			&& (bool) preg_match( '/^[a-f0-9]{40}$/D', $source_sha )
			&& (bool) preg_match( '/^[a-f0-9]{64}$/D', $plan_sha )
			&& is_string( $binding['site_origin'] ?? null ) && '' !== $binding['site_origin']
			&& (bool) preg_match( '/^[a-f0-9]{64}$/D', (string) ( $binding['site_profile_digest'] ?? '' ) )
			&& (bool) preg_match( '/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D', (string) ( $binding['site_uuid'] ?? '' ) );
		$native_gates = is_array( $native['gates'] ?? null ) ? $native['gates'] : array();
		$blocking = is_array( $plan['blocking_gates'] ?? null ) ? $plan['blocking_gates'] : array();
		$coverage = is_array( $plan['gate_action_coverage'] ?? null ) ? $plan['gate_action_coverage'] : array();
		$actions = is_array( $plan['actions'] ?? null ) ? $plan['actions'] : array();
		$issues = is_array( $plan['plan_integrity_blockers'] ?? null )
			? array_values( $plan['plan_integrity_blockers'] ) : array( 'plan_integrity_unavailable' );
		if ( count( $native_gates ) > self::MAX_GATES || count( $blocking ) > self::MAX_GATES
			|| count( $actions ) > self::MAX_ACTIONS ) $issues[] = 'remediation_inventory_limit_exceeded';
		if ( ! $identity ) $issues[] = 'exact_staging_site_binding_unavailable';
		if ( ! isset( $native['gates'], $native['ready'] ) || ! is_array( $native['gates'] )
			|| ! isset( $plan['blocking_gates'], $plan['actions'], $plan['gate_action_coverage'] )
			|| ! is_array( $plan['blocking_gates'] ) || ! is_array( $plan['actions'] )
			|| ! is_array( $plan['gate_action_coverage'] ) )
			$issues[] = 'canonical_gate_registry_unavailable';
		if ( ! empty( $plan['dispatch_allowed'] ) || ! empty( $plan['autonomous_mutation_authorized'] ) )
			$issues[] = 'remediation_plan_attempts_self_authorization';
		$blocked = array();
		foreach ( array_slice( $blocking, 0, self::MAX_GATES ) as $gate ) {
			if ( ! is_string( $gate ) || ! preg_match( '/^[a-z][a-z0-9_]{0,95}$/D', $gate ) ) {
				$issues[] = 'invalid_remediation_gate_name';
				continue;
			}
			if ( isset( $blocked[ $gate ] ) ) $issues[] = 'duplicate_blocking_gate:' . $gate;
			$blocked[ $gate ] = true;
		}
		// Convergence augments Staging with Host/Developer and independent Live.
		// Compare only shared native gate IDs; a new gate is not a disagreement.
		foreach ( array_slice( $native_gates, 0, self::MAX_GATES, true ) as $id => $gate ) {
			if ( ! is_array( $gate ) || ! is_string( $id ) ) {
				$issues[] = 'invalid_native_gate';
				continue;
			}
			$native_ready = ! empty( $gate['ready'] );
			if ( $native_ready === isset( $blocked[ $id ] ) )
				$issues[] = 'native_convergence_readiness_conflict:' . $id;
		}
		$by_id = array();
		foreach ( array_slice( $actions, 0, self::MAX_ACTIONS ) as $action ) {
			if ( ! is_array( $action ) || ! is_string( $action['action_id'] ?? null ) ) {
				$issues[] = 'invalid_remediation_action';
				continue;
			}
			$key = $action['action_id'];
			if ( isset( $by_id[ $key ] ) ) $issues[] = 'duplicate_remediation_action:' . $key;
			if ( ! empty( $action['automatic_execution_allowed'] )
				|| ! empty( $action['authorizing'] ) || ! empty( $action['mutation_performed'] ) )
				$issues[] = 'unauthorized_plan_action:' . $key;
			$by_id[ $key ] = $action;
		}
		$items = array();
		foreach ( array_keys( $blocked ) as $gate_id ) {
			$action_ids = is_array( $coverage[ $gate_id ] ?? null ) ? $coverage[ $gate_id ] : array();
			$owner = 'operator';
			if ( isset( $native_gates[ $gate_id ] ) && is_array( $native_gates[ $gate_id ] ) )
				$owner = (string) ( $native_gates[ $gate_id ]['remediation_owner'] ?? $owner );
			$paths = array();
			foreach ( array_slice( $action_ids, 0, 32 ) as $id ) {
				if ( ! is_string( $id ) || ! isset( $by_id[ $id ] ) ) {
					$issues[] = 'missing_remediation_action_for_gate:' . $gate_id;
					continue;
				}
				$action = $by_id[ $id ];
				$paths[] = array(
					'action_id' => $id,
					'kind' => (string) ( $action['kind'] ?? 'unknown' ),
					'executor' => (string) ( $action['executor'] ?? 'operator' ),
					'apply_ability' => (string) ( $action['apply_ability'] ?? '' ),
					'apply_registered' => ! empty( $action['apply_ability_registered'] ),
					'readback_ability' => (string) ( $action['readback_ability'] ?? '' ),
					'readback_registered' => ! empty( $action['readback_ability_registered'] ),
					'owner_approval_required' => ! empty( $action['human_decision_required'] ),
					'no_automatic_execution' => true,
				);
			}
			if ( ! $paths ) $issues[] = 'uncovered_remediation_gate:' . $gate_id;
			$items[] = array(
				'gate_id' => $gate_id,
				'owner' => $owner,
				'status' => $paths ? 'NEEDS_EVIDENCE_OR_SEPARATE_AUTHORITY' : 'NO_REGISTERED_REMEDIATION_PATH',
				'paths' => $paths,
			);
		}
		$issues = array_values( array_unique( $issues ) );
		$domains = is_array( $plan['readiness_domains'] ?? null ) ? $plan['readiness_domains'] : array();
		$overlay = is_array( $plan['live_acceptance_overlay'] ?? null ) ? $plan['live_acceptance_overlay'] : array();
		$live_ready = ! $live_requested || ( ! empty( $overlay['included'] ) && ! empty( $overlay['ready'] ) );
		$verified = $identity && ! $issues && ! empty( $plan['gate_coverage_complete'] );
		return array(
			'contract' => self::CONTRACT,
			'state' => ! $verified ? 'DIAGNOSTIC_INTEGRITY_BLOCKED'
				: ( $items ? 'REMEDIATION_REQUIRED'
					: ( ! empty( $native['ready'] ) && ! empty( $plan['current_ready'] )
						? 'OBSERVED_STAGING_GATES_READY' : 'NATIVE_STAGING_EVIDENCE_PENDING' ) ),
			'diagnostic_integrity_ready' => $verified,
			'native_staging_ready' => ! empty( $native['ready'] ),
			'staging_release_gates_ready' => $verified && ! $items && ! empty( $native['ready'] ) && ! empty( $plan['current_ready'] ),
			'live_acceptance_included' => ! empty( $overlay['included'] ),
			'live_acceptance_ready' => $live_requested && $live_ready,
			'full_release_certified' => false,
			'ready_for_automatic_repair' => false,
			'plan_sha256' => $plan_sha,
			'source_commit_sha' => $source_sha,
			'site_uuid' => (string) ( $binding['site_uuid'] ?? '' ),
			'site_profile_digest' => (string) ( $binding['site_profile_digest'] ?? '' ),
			'readiness_domains' => $domains,
			'blocking_gates' => array_keys( $blocked ),
			'work_items' => $items,
			'plan_integrity_blockers' => $issues,
			'reverify_ability' => 'mad4b/staging-convergence-verify',
			'prepare_ability' => self::PREPARE_ABILITY,
			'read_only' => true, 'authorizing' => false,
			'mutation_performed' => false, 'production_mutation_performed' => false,
		);
	}

	/**
	 * Pure exact-source action selection. Outputs *descriptive handoff*, not
	 * a grant, command, token, request for authority or remote executable.
	 */
	public static function prepare_from_plan( array $plan, $id, $sha, $source ) {
		if ( ! is_string( $id ) || ! preg_match( '/^[a-z][a-z0-9_]{0,95}$/D', $id )
			|| ! is_string( $sha ) || ! preg_match( '/^[a-f0-9]{64}$/D', $sha )
			|| ! is_string( $source ) || ! preg_match( '/^[a-f0-9]{40}$/D', $source ) )
			return self::denied( 'invalid_exact_action_identity' );
		$binding = is_array( $plan['plan_binding'] ?? null ) ? $plan['plan_binding'] : array();
		$current_sha = (string) ( $plan['plan_sha256'] ?? '' );
		$current_source = (string) ( $binding['source_commit_sha'] ?? '' );
		if ( strlen( $current_sha ) !== 64 || ! hash_equals( $current_sha, $sha )
			|| strlen( $current_source ) !== 40 || ! hash_equals( $current_source, $source ) )
			return self::denied( 'REPLAN_REQUIRED' );
		if ( empty( $binding['nonproduction_site_ready'] ) || 'staging' !== (string) ( $binding['environment'] ?? '' )
			|| ! is_string( $binding['site_origin'] ?? null ) || '' === $binding['site_origin']
			|| ! preg_match( '/^[a-f0-9]{64}$/D', (string) ( $binding['site_profile_digest'] ?? '' ) )
			|| ! preg_match( '/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D', (string) ( $binding['site_uuid'] ?? '' ) )
			|| empty( $plan['gate_coverage_complete'] ) || ! empty( $plan['plan_integrity_blockers'] )
			|| ! empty( $plan['dispatch_allowed'] ) || ! empty( $plan['autonomous_mutation_authorized'] ) )
			return self::denied( 'SITE_OR_PLAN_INTEGRITY_DENIED' );
		$matching = array();
		foreach ( array_slice( is_array( $plan['actions'] ?? null ) ? $plan['actions'] : array(), 0, self::MAX_ACTIONS ) as $row ) {
			if ( is_array( $row ) && ( $row['action_id'] ?? '' ) === $id ) $matching[] = $row;
		}
		if ( count( $matching ) !== 1 ) return self::denied( 'ACTION_NOT_UNIQUELY_AVAILABLE' );
		$row = $matching[0];
		$open_gates = is_array( $plan['blocking_gates'] ?? null ) ? $plan['blocking_gates'] : array();
		$target_gates = is_array( $row['target_gates'] ?? null ) ? $row['target_gates'] : array();
		if ( ! $target_gates || count( $target_gates ) > 32
			|| array_diff( $target_gates, $open_gates ) )
			return self::denied( 'ACTION_NOT_BOUND_TO_OPEN_GATE' );
		if ( ! empty( $row['authorizing'] ) || ! empty( $row['mutation_performed'] )
			|| ! empty( $row['automatic_execution_allowed'] ) )
			return self::denied( 'ACTION_ATTEMPTS_SELF_AUTHORIZATION' );
		$apply = is_string( $row['apply_ability'] ?? null ) ? $row['apply_ability'] : '';
		$registered = ! empty( $row['apply_ability_registered'] );
		$readback = is_string( $row['readback_ability'] ?? null ) ? $row['readback_ability'] : '';
		$mode = '' !== $apply && ! $registered ? 'PROVIDER_DISCOVERY_REQUIRED'
			: ( '' !== $apply ? 'SEPARATE_GOVERNED_APPROVAL_REQUIRED'
				: 'EXTERNAL_OR_OWNER_EVIDENCE_REQUIRED' );
		return array(
			'contract' => self::CONTRACT . '.preparation.v1',
			'state' => $mode,
			'action_id' => $id,
			'kind' => (string) ( $row['kind'] ?? 'unknown' ),
			'executor' => (string) ( $row['executor'] ?? 'operator' ),
			'target_gates' => array_values( array_slice( is_array( $row['target_gates'] ?? null ) ? $row['target_gates'] : array(), 0, 32 ) ),
			'apply_ability' => $apply,
			'apply_ability_registered' => $registered,
			'readback_ability' => $readback,
			'readback_registered' => ! empty( $row['readback_ability_registered'] ),
			'expected_plan_sha256' => $sha,
			'expected_source_commit_sha' => $source,
			'site_uuid' => (string) $binding['site_uuid'],
			'site_profile_digest' => (string) $binding['site_profile_digest'],
			'next_step' => 'Authorize a separate adapter-specific plan and invoke that adapter only after its own exact runtime and approval checks. Then reread Staging certification and replan.',
			'ready_for_dispatch' => false, 'approval_issued' => false,
			'full_release_certified' => false, 'authorizing' => false,
			'read_only' => true, 'mutation_performed' => false,
			'production_mutation_performed' => false,
		);
	}

	private static function denied( $reason ) {
		return array(
			'contract' => self::CONTRACT,
			'state' => 'DENIED',
			'blockers' => array( (string) $reason ),
			'ready_for_dispatch' => false, 'full_release_certified' => false,
			'authorizing' => false, 'read_only' => true,
			'mutation_performed' => false, 'production_mutation_performed' => false,
		);
	}
}

// Definition-only admin navigation; no lifecycle work on frontend requests.
if ( class_exists( 'MAD4B_SCP_Admin_Route_Registry', false ) )
	MAD4B_SCP_Admin_Route_Registry::register( 'mad4b-operational-remediation', 'manage_options' );
