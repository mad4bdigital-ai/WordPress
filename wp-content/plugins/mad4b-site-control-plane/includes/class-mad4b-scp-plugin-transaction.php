<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Unified read-only planner for plugin lifecycle/package/update transactions.
 *
 * Execution remains delegated to the exact existing governed ability. This class
 * intentionally does not create a generic mutation dispatcher.
 */
final class MAD4B_SCP_Plugin_Transaction {
	const CONTRACT = 'mad4b.plugin-transaction-plan.v1';

	public static function boot() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 34 );
	}

	public static function register_ability() {
		if ( ! function_exists( 'wp_register_ability' ) || ( function_exists( 'wp_has_ability' ) && wp_has_ability( 'mad4b/plugin-transaction-plan' ) ) ) return;
		wp_register_ability( 'mad4b/plugin-transaction-plan', array(
			'label' => 'Plan Governed Plugin Transaction',
			'description' => 'Resolve install, replace, update, activate, or deactivate to its exact governed planner/executor with dependency impact.',
			'category' => 'mad4b-read',
			'execute_callback' => array( __CLASS__, 'plan' ),
			'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
			'input_schema' => array(
				'type' => 'object',
				'properties' => array(
					'action' => array( 'type' => 'string', 'enum' => array( 'install', 'replace', 'update', 'activate', 'deactivate' ) ),
					'plugin' => array( 'type' => 'string', 'maxLength' => 191 ),
					'provider_id' => array( 'type' => 'string', 'maxLength' => 64 ),
					'component' => array( 'type' => 'string', 'maxLength' => 64 ),
					'activation_scope' => array( 'type' => 'string', 'enum' => array( 'site', 'network' ), 'default' => 'site' ),
					'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
				),
				'required' => array( 'action', 'reason' ),
				'additionalProperties' => false,
			),
			'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
			'meta' => array(
				'public' => false,
				'show_in_rest' => false,
				'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
				'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
			),
		) );
	}

	private static function execute_planner( $ability_name, array $input ) {
		if ( ! function_exists( 'wp_has_ability' ) || ! function_exists( 'wp_get_ability' ) || ! wp_has_ability( $ability_name ) ) {
			return new WP_Error( 'mad4b_plugin_transaction_planner_unavailable', 'Required governed plugin planner is not registered.', array( 'planner' => $ability_name ) );
		}
		$ability = wp_get_ability( $ability_name );
		if ( ! is_object( $ability ) || ! method_exists( $ability, 'execute' ) ) return new WP_Error( 'mad4b_plugin_transaction_planner_invalid', 'Required governed plugin planner cannot execute.' );
		return $ability->execute( $input );
	}

	public static function plan( $input ) {
		$input = is_array( $input ) ? $input : array();
		$action = sanitize_key( isset( $input['action'] ) ? (string) $input['action'] : '' );
		$plugin = isset( $input['plugin'] ) ? ltrim( wp_normalize_path( sanitize_text_field( (string) $input['plugin'] ) ), '/' ) : '';
		$provider = sanitize_key( isset( $input['provider_id'] ) ? (string) $input['provider_id'] : '' );
		$component = sanitize_key( isset( $input['component'] ) ? (string) $input['component'] : '' );
		$scope = isset( $input['activation_scope'] ) ? sanitize_key( (string) $input['activation_scope'] ) : 'site';
		$reason = sanitize_text_field( isset( $input['reason'] ) ? (string) $input['reason'] : '' );

		$planner = '';
		$executor = '';
		$planner_input = array( 'reason' => $reason );
		if ( in_array( $action, array( 'activate', 'deactivate' ), true ) ) {
			if ( '' === $plugin ) return new WP_Error( 'mad4b_plugin_transaction_plugin_required', 'Plugin file is required for lifecycle transactions.' );
			$planner = 'mad4b/plugin-lifecycle-plan';
			$executor = 'activate' === $action ? 'mad4b/plugin-activate' : 'mad4b/plugin-deactivate';
			$planner_input += array( 'plugin' => $plugin, 'desired_active' => 'activate' === $action, 'activation_scope' => $scope );
		} elseif ( 'update' === $action ) {
			if ( '' === $plugin ) return new WP_Error( 'mad4b_plugin_transaction_plugin_required', 'Plugin file is required for remote update.' );
			$planner = 'mad4b/plugin-remote-update-plan';
			$executor = 'mad4b/plugin-remote-update-apply';
			$planner_input += array( 'plugin_file' => $plugin );
		} elseif ( in_array( $action, array( 'install', 'replace' ), true ) ) {
			if ( '' === $provider ) return new WP_Error( 'mad4b_plugin_transaction_provider_required', 'Certified provider_id is required for install/replace.' );
			$planner = 'mad4b/plugin-package-plan';
			$executor = 'mad4b/plugin-package-apply';
			$planner_input += array( 'provider_id' => $provider, 'component' => $component, 'source' => 'auto_certified' );
		} else {
			return new WP_Error( 'mad4b_plugin_transaction_action_invalid', 'Unsupported plugin transaction action.' );
		}

		$native_plan = self::execute_planner( $planner, $planner_input );
		if ( is_wp_error( $native_plan ) ) return $native_plan;
		$compatibility_blockers = array();
		$native_operation = isset( $native_plan['operation'] ) ? sanitize_key( (string) $native_plan['operation'] ) : '';
		if ( 'install' === $action && 'install' !== $native_operation ) $compatibility_blockers[] = 'requested_install_requires_absent_plugin';
		if ( 'replace' === $action && 'replace' !== $native_operation ) $compatibility_blockers[] = 'requested_replace_requires_installed_plugin';
		if ( in_array( $action, array( 'activate', 'deactivate' ), true ) && $action !== $native_operation ) $compatibility_blockers[] = 'native_lifecycle_operation_mismatch';
		if ( 'update' === $action && ! in_array( $native_operation, array( 'remote_update', 'update' ), true ) ) $compatibility_blockers[] = 'native_update_operation_mismatch';
		$native_blockers = isset( $native_plan['blockers'] ) && is_array( $native_plan['blockers'] ) ? array_values( $native_plan['blockers'] ) : array();
		$blockers = array_values( array_unique( array_merge( $native_blockers, $compatibility_blockers ) ) );
		sort( $blockers, SORT_STRING );
		$impact_plugin = '' !== $plugin ? $plugin : ( isset( $native_plan['plugin_file'] ) ? (string) $native_plan['plugin_file'] : '' );
		$impact = class_exists( 'MAD4B_SCP_Dependency_Impact_Graph' ) ? MAD4B_SCP_Dependency_Impact_Graph::inspect( array( 'plugin' => $impact_plugin, 'provider_id' => $provider ) ) : array();
		$envelope = array(
			'contract' => self::CONTRACT,
			'action' => $action,
			'target' => array( 'plugin' => $plugin, 'provider_id' => $provider, 'component' => $component, 'activation_scope' => $scope ),
			'planner' => $planner,
			'executor' => $executor,
			'native_plan_contract' => isset( $native_plan['contract'] ) ? (string) $native_plan['contract'] : '',
			'native_plan_sha256' => isset( $native_plan['plan_sha256'] ) ? (string) $native_plan['plan_sha256'] : '',
			'native_operation' => $native_operation,
			'eligible' => ! empty( $native_plan['eligible'] ) && empty( $compatibility_blockers ),
			'blockers' => $blockers,
			'dependency_impact' => $impact,
			'native_plan' => $native_plan,
			'execution_contract' => 'exact_executor_only',
			'generic_mutation_dispatch' => false,
			'resume_policy' => 'idempotency_and_reconciliation_before_retry',
			'mutation_performed' => false,
			'authority_created' => false,
		);
		$encoded = wp_json_encode( $envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$envelope['transaction_sha256'] = is_string( $encoded ) ? hash( 'sha256', $encoded ) : '';
		return $envelope;
	}
}
