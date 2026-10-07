<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Repository G5 closure projection. External/live acceptance remains separate. */
final class MAD4B_SCP_G5_Acceptance {
	const CONTRACT = 'mad4b.feature007-g5-acceptance.v1';
	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 34 );
	}

	public static function register_ability() {
		if ( ! function_exists( 'wp_register_ability' ) || ( function_exists( 'wp_has_ability' ) && wp_has_ability( 'mad4b/g5-acceptance-status' ) ) ) return;
		wp_register_ability( 'mad4b/g5-acceptance-status', array(
			'label' => 'Inspect G5 Acceptance Status',
			'description' => 'Inspect G5 repository coverage and remaining external acceptance without granting provider or content authority.',
			'category' => 'mad4b-admin',
			'execute_callback' => array( __CLASS__, 'status' ),
			'permission_callback' => array( 'MAD4B_SCP_G5_External_Providers', 'can_manage' ),
			'input_schema' => array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
			'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
			'meta' => array( 'public' => false, 'show_in_rest' => false, 'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'admin' ), 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
		) );
	}

	public static function task_ids() {
		return array( 'T3926', 'T3927', 'T3928', 'T3929', 'T3930', 'T3946', 'T3947', 'T3948', 'T3949', 'T3950', 'T4056', 'T4057', 'T4058', 'T4059', 'T4060' );
	}

	public static function adversarial_matrix() {
		return array(
			'missing_external_consent' => 'blocked',
			'mixed_currency_or_window' => 'blocked',
			'partial_or_sampled_evidence' => 'blocked',
			'budget_exhausted_or_uncertain_charge' => 'blocked',
			'contradictory_observations' => 'blocked',
			'unknown_protocol_or_client_defined_provider' => 'blocked',
			'seo_provider_coexistence_conflict' => 'preserve_and_review',
			'virtual_archive_without_native_readback' => 'blocked',
			'language_or_effective_source_ambiguous' => 'blocked',
			'stale_cache_or_emitted_head_unknown' => 'blocked',
			'signal_driven_content_mutation' => 'denied',
		);
	}

	public static function status( $input = array() ) {
		if ( ! MAD4B_SCP_G5_External_Providers::can_manage() ) return new WP_Error( 'mad4b_g5_acceptance_access_denied', 'G5 acceptance status requires manage_options.' );
		if ( ! is_array( $input ) || $input ) return new WP_Error( 'mad4b_g5_acceptance_input_invalid', 'G5 acceptance status accepts no input.' );
		return array(
			'contract' => self::CONTRACT,
			'task_ids' => self::task_ids(),
			'provider_reference_contract' => MAD4B_SCP_G5_Provider_Profiles::CONTRACT,
			'seo_family_contract' => MAD4B_SCP_G5_SEO_Provider_Families::CONTRACT,
			'adversarial_matrix' => self::adversarial_matrix(),
			'repository_foundation' => 'implemented',
			'live_provider_acceptance' => false,
			'live_browser_acceptance' => false,
			'paid_provider_capture_performed' => false,
			'production_authorized' => false,
			'generic_outbound_http_allowed' => false,
			'new_grants_or_tool_mounts_created' => false,
			'direct_content_mutation_from_growth_evidence' => false,
			'authorizing' => false,
		);
	}
}
