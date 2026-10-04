<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

foreach ( array( 'search-measurement', 'search-runtime-context', 'search-eligibility', 'search-evidence-policy', 'provider-account-budget-authority', 'search-decision-policy', 'adaptive-search-fault-guard' ) as $foundation ) require_once __DIR__ . '/class-mad4b-scp-' . $foundation . '.php';

foreach ( array( 'contracts', 'store', 'context', 'surfaces', 'targets', 'decisions', 'budgets', 'providers', 'evidence', 'insights', 'worker', 'runtime', 'experience', 'work-operations' ) as $component ) require_once __DIR__ . '/search/class-mad4b-scp-search-' . $component . '.php';
require_once __DIR__ . '/search/adapters/class-mad4b-scp-search-wordpress-discovery.php';
require_once __DIR__ . '/search/adapters/class-mad4b-scp-search-serp-adapters.php';

/** Ability registration uses the existing WordPress authorization wrapper. */
final class MAD4B_SCP_Adaptive_Search_Intelligence {
	private static $registered = array();
	public static function ability_names( $surface ) { return isset( self::$registered[ $surface ] ) ? array_values( array_unique( self::$registered[ $surface ] ) ) : array(); }
	public static function boot() {
		MAD4B_SCP_Search_Budgets::boot();
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ), 46 );
		add_filter( 'mad4b_scp_search_live_surface_evidence', array( 'MAD4B_SCP_Search_Multilingual_Discovery', 'localize' ) );
		MAD4B_SCP_Search_Experience::boot();
	}
	public static function register() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		$methods = array( 'status' => 'read', 'discover' => 'read', 'profile_plan' => 'read', 'profile_apply' => 'config', 'profile_verify' => 'read', 'compile_plan' => 'read', 'compile_apply' => 'config', 'budget_plan' => 'read', 'budget_apply' => 'write', 'capture_plan' => 'read', 'capture_apply' => 'write', 'reconcile' => 'write', 'cohort' => 'read', 'proposal' => 'read', 'control' => 'config', 'provider_probe' => 'write', 'post_change_plan' => 'read', 'post_change_apply' => 'config', 'experiment' => 'read', 'recompute' => 'config', 'import_evidence' => 'config', 'retention' => 'config', 'execution_profiles' => 'read' );
		$id = array( 'type' => 'string', 'maxLength' => 96 ); $sha = array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' );
		foreach ( $methods as $method => $lane ) {
			$name = 'mad4b/search-' . str_replace( '_', '-', $method );
			$surface = 'read' === $lane ? 'read' : 'write'; self::$registered[ $surface ][] = $name;
			if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) continue;
			$read = 'read' === $lane;
			$properties = array( 'profile_id' => $id, 'target_id' => $sha, 'provider_id' => $id, 'job_id' => $sha, 'signal_id' => $sha, 'snapshot_id' => $sha, 'prior_snapshot_id' => $sha, 'experiment_id' => $sha, 'snapshot_ids' => array( 'type' => 'array', 'maxItems' => 100, 'items' => $sha ), 'post_id' => array( 'type' => 'integer', 'minimum' => 1 ), 'control' => array( 'type' => 'string', 'enum' => array( 'pause', 'resume', 'freeze_spend', 'unfreeze_spend', 'disable_provider', 'enable_provider', 'pin', 'unpin', 'mute', 'unmute', 'refresh' ) ), 'source_class' => array( 'type' => 'string', 'enum' => array( 'imported', 'manual', 'historical', 'external_intelligence', 'first_party_search_performance' ) ), 'evidence' => array( 'type' => 'object' ), 'experience_slug' => $id, 'profile' => array( 'type' => 'object' ), 'candidates' => array( 'type' => 'array', 'maxItems' => 5000, 'items' => array( 'type' => 'object' ) ), 'expected_revision' => array( 'type' => 'integer', 'minimum' => 0 ), 'observation_epoch' => array( 'type' => 'integer', 'minimum' => 0 ), 'plan_sha256' => $sha, 'cursor' => array( 'type' => 'string', 'maxLength' => 200 ), 'cursors' => array( 'type' => 'object' ), 'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200 ) );
			$fields = array(
				'status' => array( 'profile_id' ), 'discover' => array( 'profile_id', 'cursors' ),
				'profile_plan' => array( 'profile', 'expected_revision' ), 'profile_apply' => array( 'profile', 'expected_revision', 'plan_sha256' ), 'profile_verify' => array( 'profile_id' ),
				'compile_plan' => array( 'profile_id', 'candidates', 'cursors' ), 'compile_apply' => array( 'profile_id', 'candidates', 'cursors', 'plan_sha256' ),
				'budget_plan' => array( 'profile_id', 'provider_id' ), 'budget_apply' => array( 'profile_id', 'provider_id', 'plan_sha256' ),
				'capture_plan' => array( 'profile_id', 'target_id', 'observation_epoch' ), 'capture_apply' => array( 'profile_id', 'target_id', 'observation_epoch', 'plan_sha256' ),
				'reconcile' => array( 'job_id' ), 'cohort' => array( 'profile_id', 'cursor', 'limit' ), 'proposal' => array( 'signal_id', 'experience_slug' ),
				'control' => array( 'profile_id', 'control', 'provider_id', 'target_id' ), 'provider_probe' => array( 'provider_id' ),
				'post_change_plan' => array( 'profile_id', 'target_id', 'experience_slug', 'post_id' ), 'post_change_apply' => array( 'profile_id', 'target_id', 'experience_slug', 'post_id', 'plan_sha256' ),
				'experiment' => array( 'experiment_id', 'snapshot_ids' ), 'recompute' => array( 'snapshot_id', 'prior_snapshot_id' ), 'import_evidence' => array( 'source_class', 'evidence' ), 'retention' => array( 'cursor' ), 'execution_profiles' => array()
			);
			if ( 'retention' === $method ) { $properties['kind'] = array( 'type' => 'string', 'enum' => array( 'snapshot', 'signal', 'graph', 'job' ) ); $fields[ $method ][] = 'kind'; }
			$properties = array_intersect_key( $properties, array_flip( $fields[ $method ] ) );
			wp_register_ability( $name, array( 'label' => 'Search ' . str_replace( '_', ' ', $method ), 'description' => 'Provider-neutral Search Intelligence; configuration never grants provider spend, content mutation or Production authority.', 'category' => $read ? 'mad4b-read' : 'mad4b-write', 'execute_callback' => array( 'MAD4B_SCP_Search_Runtime', $method ), 'permission_callback' => $read ? array( 'MAD4B_SCP_Policy', 'can_read' ) : array( 'MAD4B_SCP_Search_Runtime', 'config' === $lane ? 'can_configure' : 'can_write' ), 'input_schema' => array( 'type' => 'object', 'properties' => $properties, 'additionalProperties' => false ), 'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ), 'meta' => array( 'public' => false, 'show_in_rest' => false, 'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => $read ? 'read' : 'write' ), 'annotations' => array( 'readonly' => $read, 'destructive' => false, 'idempotent' => $read ) ) ) );
		}
	}
}
MAD4B_SCP_Adaptive_Search_Intelligence::boot();
