<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Site-bound brand/task context authority.
 *
 * MVP storage is deliberately versioned, non-autoloaded WordPress options.
 * This keeps Context Authority independent from the governance schema while the
 * product contract and UX stabilize. A future lifecycle migration may move the
 * same records to dedicated tables without changing the public contracts.
 */
final class MAD4B_SCP_Context_Authority {
	const CONTRACT = 'mad4b.context-authority.v1';
	const PROFILE_CONTRACT = 'mad4b.brand-context-profile.v1';
	const SOURCE_CONTRACT = 'mad4b.context-source.v1';
	const ASSET_CONTRACT = 'mad4b.context-asset.v1';
	const QUALITY_CONTRACT = 'mad4b.context-quality-score.v2';
	const HUMAN_REVIEW_CONTRACT = 'mad4b.context-human-review.v2';
	const AI_REVIEW_CONTRACT = 'mad4b.context-ai-agent-review.v1';
	const REVIEW_POLICY_CONTRACT = 'mad4b.context-review-policy.v1';
	const AI_REVIEW_ABILITY = 'mad4b/context-ai-review';

	const PROFILE_OPTION = 'mad4b_scp_brand_context_profile_v1';
	const SOURCES_OPTION = 'mad4b_scp_context_sources_v1';
	const ASSETS_OPTION = 'mad4b_scp_context_assets_v1';
	const REGISTRY_REVISION_OPTION = 'mad4b_scp_context_registry_revision_v1';
	const REGISTRY_LOCK_OPTION = 'mad4b_scp_context_registry_lock_v1';
	const REGISTRY_LOCK_TTL = 45;

	const ABILITY = 'mad4b/context-authority-status';
	const MAX_SOURCES = 50;
	const MAX_ASSETS = 1000;

	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 9 );
	}

	public static function register_ability() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( self::ABILITY ) ) {
			wp_register_ability(
				self::ABILITY,
				array(
					'label' => 'Context Authority Status',
					'description' => 'Read-only site-bound Brand Context, source, asset classification and quality readiness.',
					'category' => 'mad4b-read',
					'execute_callback' => array( __CLASS__, 'status' ),
					'permission_callback' => class_exists( 'MAD4B_SCP_Policy' ) ? array( 'MAD4B_SCP_Policy', 'can_read' ) : '__return_false',
					'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
					'meta' => array(
						'public' => false,
						'show_in_rest' => false,
						'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
						'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
					),
				)
			);
		}
		if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( self::AI_REVIEW_ABILITY ) ) {
			wp_register_ability(
				self::AI_REVIEW_ABILITY,
				array(
					'label' => 'AI Agent Context Review',
					'description' => 'Submit one exact-bound Context review decision through the explicitly delegated AI Agent approval mode. Governance metadata and quality policy cannot be changed by this ability.',
					'category' => 'mad4b-admin',
					'execute_callback' => array( __CLASS__, 'review_asset_by_agent' ),
					'permission_callback' => class_exists( 'MAD4B_SCP_Policy' ) ? array( 'MAD4B_SCP_Policy', 'can_admin' ) : '__return_false',
					'input_schema' => array(
						'type' => 'object',
						'properties' => array(
							'asset_id' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
							'decision' => array( 'type' => 'string', 'enum' => array( 'approve', 'needs_changes', 'reject' ) ),
							'review_note' => array( 'type' => 'string', 'maxLength' => 1000, 'default' => '' ),
							'expected_content_hash' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
							'expected_registry_revision' => array( 'type' => 'integer', 'minimum' => 0 ),
							'expected_authority_manifest_fingerprint' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
						),
						'required' => array( 'asset_id', 'decision', 'expected_content_hash', 'expected_registry_revision', 'expected_authority_manifest_fingerprint' ),
						'additionalProperties' => false,
					),
					'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
					'meta' => array(
						'public' => false,
						'show_in_rest' => false,
						'mcp' => array(
							'public' => false,
							'type' => 'tool',
							'surface' => 'write',
							'mad4b_governed_write_authority' => 'mad4b.governed-write-authority.v2',
							'mad4b_ai_review_standing_delegation' => self::AI_REVIEW_CONTRACT,
						),
						'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
					),
				)
			);
		}
	}

	public static function profile() {
		$site = self::site_binding();
		if ( is_wp_error( $site ) ) return array();
		$record = get_option( self::PROFILE_OPTION, array() );
		if ( ! self::valid_profile( $record ) ) return array();
		$record_site_uuid = strtolower( trim( (string) $record['site_uuid'] ) );
		return hash_equals( (string) $site['site_uuid'], $record_site_uuid ) ? $record : array();
	}


	private static function default_review_policy() {
		return array(
			'contract' => self::REVIEW_POLICY_CONTRACT,
			'mode' => 'human_only',
			'ai_agent_public_id' => '',
			'updated_at' => '',
			'updated_by_wp_user_id' => 0,
		);
	}

	public static function review_policy() {
		$profile = self::profile();
		$policy = isset( $profile['review_policy'] ) && is_array( $profile['review_policy'] ) ? $profile['review_policy'] : self::default_review_policy();
		if ( self::REVIEW_POLICY_CONTRACT !== ( isset( $policy['contract'] ) ? (string) $policy['contract'] : '' ) ) $policy = self::default_review_policy();
		$mode = isset( $policy['mode'] ) ? sanitize_key( (string) $policy['mode'] ) : 'human_only';
		if ( ! in_array( $mode, array( 'human_only', 'human_and_ai' ), true ) ) $mode = 'human_only';
		$policy['mode'] = $mode;
		$agent_public_id = isset( $policy['ai_agent_public_id'] ) ? strtolower( trim( (string) $policy['ai_agent_public_id'] ) ) : '';
		$policy['ai_agent_public_id'] = 1 === preg_match( '/^[a-f0-9-]{36}$/', $agent_public_id ) ? $agent_public_id : '';
		return $policy;
	}


	private static function site_profile_environment() {
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! method_exists( 'MAD4B_SCP_Site_Profile', 'current_environment' ) ) return '';
		return sanitize_key( (string) MAD4B_SCP_Site_Profile::current_environment() );
	}

	private static function site_profile_agent_slug() {
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! method_exists( 'MAD4B_SCP_Site_Profile', 'agent_slug' ) ) return '';
		return sanitize_key( (string) MAD4B_SCP_Site_Profile::agent_slug() );
	}

	public static function ai_review_catalog_eligible() {
		$policy = self::review_policy();
		if ( 'human_and_ai' !== (string) $policy['mode'] || empty( $policy['ai_agent_public_id'] ) ) return false;
		if ( 'staging' !== self::site_profile_environment() ) return false;
		if ( ! class_exists( 'MAD4B_SCP_Agent_Registry' ) || ! class_exists( 'MAD4B_SCP_Schema' ) || ! MAD4B_SCP_Schema::is_ready() ) return false;
		$agent = MAD4B_SCP_Agent_Registry::get_agent_by_public_id( (string) $policy['ai_agent_public_id'] );
		$profile_agent_slug = self::site_profile_agent_slug();
		return is_array( $agent )
			&& 'enabled' === ( isset( $agent['status'] ) ? (string) $agent['status'] : '' )
			&& 'staging' === ( isset( $agent['environment'] ) ? (string) $agent['environment'] : '' )
			&& '' !== $profile_agent_slug
			&& $profile_agent_slug === sanitize_key( isset( $agent['slug'] ) ? (string) $agent['slug'] : '' );
	}

	public static function ai_review_policy_status() {
		$policy = self::review_policy();
		$blockers = array();
		$agent = array();
		$grant = null;
		if ( 'human_and_ai' !== (string) $policy['mode'] ) $blockers[] = 'ai_review_mode_disabled';
		if ( empty( $policy['ai_agent_public_id'] ) ) $blockers[] = 'ai_review_agent_unconfigured';
		if ( 'staging' !== self::site_profile_environment() ) $blockers[] = 'ai_review_staging_only';
		if ( ! class_exists( 'MAD4B_SCP_Agent_Registry' ) || ! class_exists( 'MAD4B_SCP_Schema' ) || ! MAD4B_SCP_Schema::is_ready() ) {
			$blockers[] = 'ai_review_agent_registry_unavailable';
		} elseif ( ! empty( $policy['ai_agent_public_id'] ) ) {
			$agent = MAD4B_SCP_Agent_Registry::get_agent_by_public_id( (string) $policy['ai_agent_public_id'] );
			if ( ! is_array( $agent ) || empty( $agent ) ) $blockers[] = 'ai_review_agent_missing';
			else {
				if ( 'enabled' !== ( isset( $agent['status'] ) ? (string) $agent['status'] : '' ) ) $blockers[] = 'ai_review_agent_disabled';
				if ( 'staging' !== ( isset( $agent['environment'] ) ? (string) $agent['environment'] : '' ) ) $blockers[] = 'ai_review_agent_environment_mismatch';
				$profile_agent_slug = self::site_profile_agent_slug();
				if ( '' === $profile_agent_slug || $profile_agent_slug !== sanitize_key( isset( $agent['slug'] ) ? (string) $agent['slug'] : '' ) ) $blockers[] = 'ai_review_agent_not_profile_owned';
				$grant = MAD4B_SCP_Agent_Registry::exact_grant( (int) $agent['id'], 'mad4b-write', self::AI_REVIEW_ABILITY, 'core' );
				if ( ! is_array( $grant ) || 'allow' !== ( isset( $grant['effect'] ) ? (string) $grant['effect'] : '' ) || 'staging' !== ( isset( $grant['environment'] ) ? (string) $grant['environment'] : '' ) ) $blockers[] = 'ai_review_exact_grant_missing';
			}
		}
		$blockers = array_values( array_unique( $blockers ) );
		return array(
			'contract' => self::REVIEW_POLICY_CONTRACT,
			'mode' => (string) $policy['mode'],
			'human_review_available' => true,
			'ai_review_enabled' => 'human_and_ai' === (string) $policy['mode'],
			'ai_agent_public_id' => (string) $policy['ai_agent_public_id'],
			'catalog_eligible' => self::ai_review_catalog_eligible(),
			'exact_grant_ready' => is_array( $grant ) && 'allow' === ( isset( $grant['effect'] ) ? (string) $grant['effect'] : '' ),
			'ready' => empty( $blockers ),
			'blockers' => $blockers,
			'grant_reconciliation_automatic' => false,
			'production_authorized' => false,
		);
	}

	public static function set_review_policy( $mode, $agent_public_id = '', $confirmed = false ) {
		$mode = sanitize_key( (string) $mode );
		$agent_public_id = strtolower( trim( sanitize_text_field( (string) $agent_public_id ) ) );
		if ( ! in_array( $mode, array( 'human_only', 'human_and_ai' ), true ) ) return new WP_Error( 'mad4b_context_review_mode_invalid', 'Context review mode must be human_only or human_and_ai.' );
		if ( 'human_only' === $mode ) $agent_public_id = '';

		// Persisted state is authoritative. A reload must not force the operator to
		// re-confirm an already committed delegation merely because the one-time
		// confirmation checkbox is intentionally not persisted.
		$current = self::review_policy();
		if ( $mode === (string) $current['mode'] && $agent_public_id === (string) $current['ai_agent_public_id'] ) {
			$current['mutation_performed'] = false;
			$current['idempotent'] = true;
			$current['persistence_verified'] = true;
			return $current;
		}

		if ( 'human_and_ai' === $mode ) {
			if ( ! $confirmed ) return new WP_Error( 'mad4b_context_ai_review_confirmation_required', 'Changing delegated AI Agent review requires explicit administrator confirmation.' );
			if ( 1 !== preg_match( '/^[a-f0-9-]{36}$/', $agent_public_id ) ) return new WP_Error( 'mad4b_context_ai_review_agent_required', 'Select one exact enabled MAD4B Agent for AI review delegation.' );
			if ( 'staging' !== self::site_profile_environment() ) return new WP_Error( 'mad4b_context_ai_review_staging_only', 'AI Agent approval mode is Staging-only in rc.54.' );
			if ( ! class_exists( 'MAD4B_SCP_Agent_Registry' ) || ! class_exists( 'MAD4B_SCP_Schema' ) || ! MAD4B_SCP_Schema::is_ready() ) return new WP_Error( 'mad4b_context_ai_review_agent_registry_unavailable', 'MAD4B Agent registry is unavailable.' );
			$agent = MAD4B_SCP_Agent_Registry::get_agent_by_public_id( $agent_public_id );
			$profile_agent_slug = self::site_profile_agent_slug();
			if ( ! is_array( $agent ) || empty( $agent ) || 'enabled' !== ( isset( $agent['status'] ) ? (string) $agent['status'] : '' ) || 'staging' !== ( isset( $agent['environment'] ) ? (string) $agent['environment'] : '' ) ) return new WP_Error( 'mad4b_context_ai_review_agent_ineligible', 'AI review requires the enabled Staging Site Profile agent.' );
			if ( '' === $profile_agent_slug || $profile_agent_slug !== sanitize_key( isset( $agent['slug'] ) ? (string) $agent['slug'] : '' ) ) return new WP_Error( 'mad4b_context_ai_review_agent_not_profile_owned', 'AI review delegation must use the canonical Site Profile governed-write agent.' );
		}
		return self::with_registry_lock(
			'set_review_policy',
			static function () use ( $mode, $agent_public_id ) {
				$audit_ready = self::audit_preflight();
				if ( is_wp_error( $audit_ready ) ) return $audit_ready;
				$profile = self::profile();
				if ( empty( $profile ) ) return new WP_Error( 'mad4b_brand_context_profile_required', 'Configure the Brand Context Profile before changing review policy.' );
				$previous = self::review_policy();
				$policy = array(
					'contract' => self::REVIEW_POLICY_CONTRACT,
					'mode' => $mode,
					'ai_agent_public_id' => $agent_public_id,
					'updated_at' => gmdate( 'c' ),
					'updated_by_wp_user_id' => get_current_user_id(),
				);
				$profile['review_policy'] = $policy;
				$profile['revision'] = max( 1, isset( $profile['revision'] ) ? absint( $profile['revision'] ) + 1 : 1 );
				$profile['updated_at'] = gmdate( 'c' );
				if ( ! self::write_option( self::PROFILE_OPTION, $profile ) ) return new WP_Error( 'mad4b_context_review_policy_write_failed', 'Context review policy could not be persisted.' );
				return self::audited_registry_result(
					$policy,
					'mad4b/context-review-policy-save',
					array(
						'contract' => self::REVIEW_POLICY_CONTRACT,
						'previous_mode' => isset( $previous['mode'] ) ? (string) $previous['mode'] : 'human_only',
						'mode' => $mode,
						'previous_ai_agent_public_id' => isset( $previous['ai_agent_public_id'] ) ? (string) $previous['ai_agent_public_id'] : '',
						'ai_agent_public_id' => $agent_public_id,
						'human_review_available' => true,
						'grant_reconciliation_called' => false,
						'production_mutation' => false,
						'wp_user_id' => get_current_user_id(),
					),
					'ok'
				);
			}
		);
	}

	public static function sources() {
		$site = self::site_binding();
		if ( is_wp_error( $site ) ) return array();
		return self::authorized_sources_from_records( self::raw_sources(), $site );
	}

	public static function assets() {
		$site = self::site_binding();
		if ( is_wp_error( $site ) ) return array();
		$sources = self::authorized_sources_from_records( self::raw_sources(), $site );
		return self::authorized_assets_from_records( self::raw_assets(), $sources, $site );
	}

    /** Aggregate only; does not expose or adopt quarantined legacy data. */
    public static function legacy_reconciliation_census() {
        if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ||
            ! class_exists( 'MAD4B_SCP_Policy', false ) || ! MAD4B_SCP_Policy::can_read() )
            return new WP_Error( 'mad4b_legacy_census_permission_denied', 'Administrator read permission required.' );
        $checkpoint = MAD4B_SCP_Operational_Integrity::capture();
        if ( is_wp_error( $checkpoint ) ) return $checkpoint;
        $sources = MAD4B_SCP_Operational_Scope_Guard::legacy_reconciliation_census( self::raw_sources() );
        if ( is_wp_error( $sources ) ) return $sources;
        $assets = MAD4B_SCP_Operational_Scope_Guard::legacy_reconciliation_census( self::raw_assets() );
        if ( is_wp_error( $assets ) ) return $assets;
        $fresh = MAD4B_SCP_Operational_Integrity::assert_unchanged( $checkpoint, false );
        if ( is_wp_error( $fresh ) ) return $fresh;
        return array(
            'contract' => 'mad4b.context-legacy-reconciliation-census.v1',
            'sources' => $sources['counts'],
            'assets' => $assets['counts'],
            'source_quarantine_count' => $sources['quarantined'],
            'asset_quarantine_count' => $assets['quarantined'],
            'scope_fingerprint' => $checkpoint['fingerprint'],
            'read_only' => true,
            'mutation_performed' => false,
            'migration_authorized' => false,
        );
    }


	/**
	 * Privileged read-only candidate discovery. Unbound is not owned:
	 * visibility of an old folder does not grant migration rights.
	 */
	public static function legacy_owner_transfer_discover( $input = array() ) {
		if ( ! is_array( $input ) || $input )
			return new WP_Error( 'mad4b_legacy_transfer_discover_input_invalid', 'Only empty read-only inventory requests are accepted.' );
		if ( ! current_user_can( 'manage_options' ) )
			return new WP_Error( 'mad4b_legacy_transfer_discover_admin_required', 'Exact administrator review required.' );
		$site = self::site_binding();
		if ( is_wp_error( $site ) ) return $site;
		$rows = array();
		$foreign_or_conflicted = 0;
		foreach ( self::raw_sources() as $key => $source ) {
			if ( ! is_array( $source ) ) { ++$foreign_or_conflicted; continue; }
			$id = (string) ( $source['source_id'] ?? '' );
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $id ) ) { ++$foreign_or_conflicted; continue; }
			$review = self::legacy_owner_transfer_plan( array( 'source_id' => $id ) );
			if ( is_wp_error( $review ) ) { ++$foreign_or_conflicted; continue; }
			$rows[] = array(
				'source_id' => $id,
				'external_root_id' => (string) $review['external_root_id'],
				'asset_count' => (int) $review['asset_count'],
				'plan_sha256' => (string) $review['plan_sha256'],
				'status' => 'owner_and_external_folder_review_required',
			);
		}
		return array( 'contract' => 'mad4b.context-legacy-owner-transfer-discovery.v1',
			'eligible_unbound_candidates' => $rows,
			'candidate_count' => count( $rows ),
			'foreign_or_conflicted_records' => $foreign_or_conflicted,
			'brand_ownership_asserted' => false, 'migration_authorized' => false,
			'read_only' => true, 'mutation_performed' => false );
	}

	/** Test optional tenancy metadata WITHOUT treating a missing brand as owned. */
	private static function legacy_metadata_compatible( array $record, array $scope ) {
		$fields = array(
			'tenant_ref' => 'tenant_ref', 'tenant_id' => 'tenant_ref',
			'blog_id' => 'blog_id', 'network_id' => 'network_id',
			'environment' => 'environment', 'deployment_mode' => 'deployment_mode',
		);
		foreach ( $fields as $key => $trusted ) {
			if ( ! array_key_exists( $key, $record ) ) continue;
			if ( ! isset( $scope[ $trusted ] ) || ! is_scalar( $record[ $key ] )
				|| '' === trim( (string) $record[ $key ] )
				|| ! hash_equals( (string) $scope[ $trusted ], trim( (string) $record[ $key ] ) ) return false;
		}
		return true;
	}

	/**
	 * Plan one exact unbound legacy folder transfer. Foreign Brand identities
	 * and competing scoped records are NEVER silently reassigned. Owner must
	 * independently inspect the original Google Drive folder.
	 */
	public static function legacy_owner_transfer_plan( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$source_id = strtolower( trim( (string) ( $input['source_id'] ?? '' ) ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $source_id ) )
			return new WP_Error( 'mad4b_legacy_transfer_source_required', 'Select one exact already-stored source ID.' );
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_legacy_transfer_admin_required', 'An administrator must inspect the exact prior source.' );
		$site = self::site_binding();
		if ( is_wp_error( $site ) ) return $site;
		if ( 'staging' !== (string) $site['environment'] )
			return new WP_Error( 'mad4b_legacy_transfer_staging_only', 'Legacy identity migration is Staging only.' );
		$scope = MAD4B_SCP_Operational_Scope_Guard::require_current();
		if ( is_wp_error( $scope ) ) return $scope;
		$profile = self::profile();
		$brand_id = strtolower( trim( (string) ( $profile['brand_id'] ?? '' ) ) );
		if ( ! preg_match( '/^[a-f0-9]{32}$/D', $brand_id )
			|| ! hash_equals( (string) $scope['brand_ref'], $brand_id ) )
			return new WP_Error( 'mad4b_legacy_transfer_brand_invalid', 'Current enrolled Brand identity is not exact.' );
		$sources = self::raw_sources();
		$assets = self::raw_assets();
		$source = $sources[ $source_id ] ?? array();
		if ( ! self::valid_source( $source ) || 'google_drive' !== (string) ( $source['provider'] ?? '' )
			|| 'governed' !== (string) ( $source['mode'] ?? '' )
			|| ! hash_equals( $source_id, strtolower( (string) ( $source['source_id'] ?? '' ) ) )
			|| ! hash_equals( (string) $scope['site_uuid'], strtolower( (string) ( $source['site_uuid'] ?? '' ) ) ) )
			return new WP_Error( 'mad4b_legacy_transfer_source_not_exact', 'Source is not an exact governed Drive source on this Staging site.' );
		$old_brand = strtolower( trim( (string) ( $source['brand_id'] ?? '' ) ) );
		if ( '' !== $old_brand ) return new WP_Error( 'mad4b_legacy_transfer_ownership_not_unbound',
			'Only truly unbound legacy records can be considered; another Brand must use a separate verified migration.' );
		if ( ! self::legacy_metadata_compatible( $source, $scope ) )
			return new WP_Error( 'mad4b_legacy_transfer_scope_conflict', 'Source contains conflicting tenant, deployment or network metadata.' );
		// Require all assets belonging to the selected source to be transferable
		// together. Any foreign, malformed or mixed row aborts the whole plan.
		$selected = array();
		foreach ( $assets as $key => $asset ) {
			if ( ! is_array( $asset ) || $source_id !== (string) ( $asset['source_id'] ?? '' ) ) continue;
			if ( ! self::valid_asset( $asset ) || '' !== strtolower( trim( (string) ( $asset['brand_id'] ?? '' ) ) )
				|| ! hash_equals( (string) $scope['site_uuid'], strtolower( (string) ( $asset['site_uuid'] ?? '' ) ) )
				|| 'governed' !== (string) ( $asset['source_mode'] ?? '' )
				|| ! self::legacy_metadata_compatible( $asset, $scope ) )
				return new WP_Error( 'mad4b_legacy_transfer_asset_scope_conflict',
					'Foreign, malformed or mixed-ownership asset prevents bulk transfer.' );
			$selected[ (string) $key ] = $asset;
		}
		if ( ! $selected ) return new WP_Error( 'mad4b_legacy_transfer_assets_required',
			'No unbound assets were proven for this exact governed source.' );
		ksort( $selected, SORT_STRING );
		$external_root = (string) ( $source['external_root_id'] ?? '' );
		$source_digest = hash( 'sha256', wp_json_encode( $source ) );
		$assets_digest = hash( 'sha256', wp_json_encode( $selected ) );
		$basis = array(
			'contract' => 'mad4b.context-legacy-owner-transfer-plan.v1',
			'state' => 'owner_and_external_folder_review_required',
			'site_uuid' => (string) $scope['site_uuid'],
			'brand_id' => $brand_id,
			'brand_revision' => (int) ( $profile['revision'] ?? 0 ),
			'registry_revision' => self::registry_revision(),
			'source_id' => $source_id,
			'external_root_id' => $external_root,
			'source_record_sha256' => $source_digest,
			'asset_records_sha256' => $assets_digest,
			'asset_count' => count( $selected ),
			'owner_review_required' => true,
			'current_provider_folder_identity_review_required' => true,
			'one_time_exact_mutation_approval_required' => true,
			'prior_approvals_invalidated_on_transfer' => true,
			'new_source_created' => false,
			'source_write_policy_after_transfer' => 'read_only',
			'post_transfer_scan_required' => true,
			'production_mutation_authorized' => false,
			'read_only' => true,
			'mutation_performed' => false,
		);
		$basis['plan_sha256'] = hash( 'sha256', wp_json_encode( $basis ) );
		return $basis;
	}

	/**
	 * Exact, one-source, Staging-only owner-attested transition. Invoked
	 * ONLY as an independently approved governed MCP write; the source/asset
	 * options and current scope are CAS protected by the Context registry lock.
	 * All inherited approval statuses are invalidated until new review.
	 */
	public static function legacy_owner_transfer_apply( $input ) {
		if ( ! is_array( $input ) ) return new WP_Error( 'mad4b_legacy_transfer_input_invalid', 'Exact transfer input required.' );
		if ( 'APPROVE EXACT UNBOUND BRAND TRANSFER' !== (string) ( $input['confirmation'] ?? '' ) )
			return new WP_Error( 'mad4b_legacy_transfer_owner_confirmation_required', 'The exact owner transfer confirmation was not given.' );
		$source_id = strtolower( trim( (string) ( $input['source_id'] ?? '' ) ) );
		$expected = strtolower( trim( (string) ( $input['expected_plan_sha256'] ?? '' ) ) );
		$reviewed_root = trim( (string) ( $input['reviewed_external_root_id'] ?? '' ) );
		$evidence = trim( (string) ( $input['owner_evidence_reference'] ?? '' ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $expected ) || strlen( $evidence ) < 12 ||
			strlen( $evidence ) > 500 || '' === $reviewed_root )
			return new WP_Error( 'mad4b_legacy_transfer_evidence_invalid', 'An exact source plan and independently reviewed evidence reference are required.' );
		// Independent real provider read: an operator-supplied folder string is
		// never enough to convert unbound records into Brand-owned records.
		if ( ! class_exists( 'MAD4B_SCP_Google_Drive_Context' ) )
			return new WP_Error( 'mad4b_legacy_transfer_provider_unavailable', 'Existing enrolled Google Drive provider is not available.' );
		$folder = MAD4B_SCP_Google_Drive_Context::get_folder( $reviewed_root );
		if ( is_wp_error( $folder ) ) return $folder;
		if ( ! is_array( $folder ) || ! hash_equals( $reviewed_root, (string) ( $folder['id'] ?? '' ) )
			|| 'application/vnd.google-apps.folder' !== (string) ( $folder['mimeType'] ?? '' ) )
			return new WP_Error( 'mad4b_legacy_transfer_folder_mismatch', 'Independent Google Drive folder identity could not be verified.' );
		return self::with_registry_lock( 'legacy_owner_transfer_apply',
			static function () use ( $input, $source_id, $expected, $reviewed_root, $evidence ) {
				$plan = self::legacy_owner_transfer_plan( array( 'source_id' => $source_id ) );
				if ( is_wp_error( $plan ) ) return $plan;
				if ( ! hash_equals( (string) $plan['plan_sha256'], $expected )
					|| ! hash_equals( (string) $plan['brand_id'], strtolower( (string) ( $input['expected_brand_id'] ?? '' ) ) )
					|| ! hash_equals( (string) $plan['external_root_id'], $reviewed_root ) )
					return new WP_Error( 'mad4b_legacy_transfer_exact_plan_stale',
						'Exact source, owner, external folder or registry changed after approval.' );
				$audit = self::audit_preflight();
				if ( is_wp_error( $audit ) ) return $audit;
				$scope = MAD4B_SCP_Operational_Scope_Guard::require_current();
				if ( is_wp_error( $scope ) ) return $scope;
				$sources = self::raw_sources();
				$assets = self::raw_assets();
				$source = $sources[ $source_id ];
				$stamp = array( 'brand_id' => (string) $plan['brand_id'] );
				foreach ( array( 'tenant_ref', 'blog_id', 'network_id', 'environment', 'deployment_mode' ) as $key )
					if ( ! array_key_exists( $key, $source ) ) $stamp[ $key ] = (string) $scope[ $key ];
				$source = array_merge( $source, $stamp );
				$source['write_policy'] = 'read_only';
				$source['status'] = 'selected';
				$source['last_scan_complete'] = false;
				$source['last_complete_scan_at'] = '';
				$source['updated_at'] = gmdate( 'c' );
				$sources[ $source_id ] = $source;
				$count = 0;
				foreach ( $assets as $id => $asset ) {
					if ( ! is_array( $asset ) || $source_id !== (string) ( $asset['source_id'] ?? '' ) ) continue;
					$asset['brand_id'] = (string) $plan['brand_id'];
					foreach ( array( 'tenant_ref', 'blog_id', 'network_id', 'environment', 'deployment_mode' ) as $key )
						if ( ! array_key_exists( $key, $asset ) ) $asset[ $key ] = (string) $scope[ $key ];
					$asset['review_status'] = 'unreviewed';
					$asset['review_decision'] = '';
					$asset['reviewed_content_hash'] = '';
					$asset['reviewed_at'] = '';
					// No old Agent/owner review is inherited across an
					// identity change, including stale-evidence overrides.
					foreach ( array( 'review_actor_type', 'review_agent_public_id',
						'review_note', 'reviewed_by', 'review_evidence_sha256',
						'generation_evidence_stale_override',
						'generation_evidence_reviewed_current_digest',
					) as $approval_field ) unset( $asset[ $approval_field ] );
					$asset['status'] = 'stale';
					$asset['updated_at'] = gmdate( 'c' );
					$assets[ $id ] = $asset;
					++$count;
				}
				if ( $count !== (int) $plan['asset_count'] ) return new WP_Error(
					'mad4b_legacy_transfer_count_drift', 'Asset set changed during the locked transfer.' );
				$committed = self::commit_option_changes(
					array( self::SOURCES_OPTION => $sources, self::ASSETS_OPTION => $assets ),
					'mad4b_legacy_transfer_commit_failed', 'Could not atomically persist the reviewed legacy transfer.'
				);
				if ( is_wp_error( $committed ) ) return $committed;
				$read_source = self::raw_sources();
				$read_assets = self::raw_assets();
				$selected_postcount = 0;
				if ( ! isset( $read_source[ $source_id ] )
					|| ! hash_equals( (string) $plan['brand_id'], (string) ( $read_source[ $source_id ]['brand_id'] ?? '' ) )
					|| 'read_only' !== (string) ( $read_source[ $source_id ]['write_policy'] ?? '' ) )
					return new WP_Error( 'mad4b_legacy_transfer_source_readback_failed',
						'New registry binding could not be independently read back; compensate before accepting.' );
				foreach ( $read_assets as $post_asset ) {
					if ( ! is_array( $post_asset ) ||
						$source_id !== (string) ( $post_asset['source_id'] ?? '' ) ) continue;
					++$selected_postcount;
					if ( ! hash_equals( (string) $plan['brand_id'], (string) ( $post_asset['brand_id'] ?? '' ) )
						|| 'unreviewed' !== (string) ( $post_asset['review_status'] ?? '' )
						|| '' !== (string) ( $post_asset['reviewed_content_hash'] ?? '' )
						|| 'stale' !== (string) ( $post_asset['status'] ?? '' ) )
						return new WP_Error( 'mad4b_legacy_transfer_assets_readback_failed',
							'Exact newly transferred assets have not been independently verified as unapproved.' );
				}
				if ( $selected_postcount !== $count )
					return new WP_Error( 'mad4b_legacy_transfer_asset_count_readback_failed',
						'Source asset count changed before the transfer was accepted.' );
				return self::audited_registry_result( array(
					'contract' => 'mad4b.context-legacy-owner-transfer.v1',
					'state' => 'transferred_unapproved_requires_fresh_scan',
					'source_id' => $source_id, 'asset_count' => $count,
					'brand_id' => (string) $plan['brand_id'],
					'prior_review_authority_preserved' => false,
					'new_source_created' => false,
					'production_mutation' => false,
				), 'mad4b/context-legacy-owner-transfer', array(
					'source_id_sha256' => hash( 'sha256', $source_id ),
					'prior_source_sha256' => (string) $plan['source_record_sha256'],
					'prior_assets_sha256' => (string) $plan['asset_records_sha256'],
					'asset_count' => $count,
					'plan_sha256' => $expected,
					'evidence_reference_sha256' => hash( 'sha256', $evidence ),
					'owner_approved_transfer' => true,
					'prior_reviews_invalidated' => true,
					'supplier_rights_auto_granted' => false,
					'production_mutation' => false,
				), 'ok' );
			}
		);
	}

	/** Independent postcondition read; never accepts a caller-supplied owner. */
	public static function legacy_owner_transfer_readback( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$id = strtolower( trim( (string) ( $input['source_id'] ?? '' ) ) );
		if ( ! current_user_can( 'manage_options' ) ||
			1 !== preg_match( '/^[a-f0-9]{64}$/D', $id ) )
			return new WP_Error( 'mad4b_legacy_transfer_readback_denied',
				'Administrator and exact SHA-bound legacy source ID required.' );
		$scope = MAD4B_SCP_Operational_Scope_Guard::require_current();
		if ( is_wp_error( $scope ) ) return $scope;
		$source = self::raw_sources()[ $id ] ?? array();
		$brand = strtolower( (string) ( $scope['brand_ref'] ?? '' ) );
		$source_valid = is_array( $source ) && self::valid_source( $source )
			&& 'read_only' === (string) ( $source['write_policy'] ?? '' )
			&& hash_equals( $brand, strtolower( (string) ( $source['brand_id'] ?? '' ) ) )
			&& MAD4B_SCP_Operational_Scope_Guard::record_metadata_matches( $source, $scope );
		$items = 0; $unreviewed = 0; $foreign = 0;
		foreach ( self::raw_assets() as $asset ) {
			if ( ! is_array( $asset ) || $id !== (string) ( $asset['source_id'] ?? '' ) ) continue;
			++$items;
			if ( 'stale' === (string) ( $asset['status'] ?? '' ) &&
				'unreviewed' === (string) ( $asset['review_status'] ?? '' ) &&
				'' === (string) ( $asset['reviewed_content_hash'] ?? '' ) &&
				MAD4B_SCP_Operational_Scope_Guard::record_metadata_matches( $asset, $scope ) )
				++$unreviewed;
			else ++$foreign;
		}
		return array(
			'contract' => 'mad4b.context-legacy-owner-transfer-readback.v1',
			'state' => $source_valid && $items > 0 && $items === $unreviewed && 0 === $foreign
				? 'transferred_unapproved_requires_fresh_scan' : 'blocked_or_changed',
			'ready_for_fresh_source_scan' => $source_valid && $items > 0 && $items === $unreviewed && 0 === $foreign,
			'existing_source_verified' => $source_valid,
			'asset_count' => $items, 'unreviewed_asset_count' => $unreviewed,
			'foreign_or_approved_asset_count' => $foreign,
			'old_approvals_reused' => false,
			'content_ready_for_publication' => false,
			'supplier_rights_authorized' => false,
			'read_only' => true, 'mutation_performed' => false,
		);
	}

	private static function raw_sources() {
		$records = get_option( self::SOURCES_OPTION, array() );
		return is_array( $records ) ? $records : array();
	}

	private static function raw_assets() {
		$records = get_option( self::ASSETS_OPTION, array() );
		return is_array( $records ) ? $records : array();
	}

	private static function authorized_sources_from_records( array $records, array $site ) {
		$out = array();
		$site_uuid = isset( $site['site_uuid'] ) ? strtolower( trim( (string) $site['site_uuid'] ) ) : '';
		$profile = self::profile();
		$brand_id = isset( $profile['brand_id'] ) ? strtolower( trim( (string) $profile['brand_id'] ) ) : '';
		$verified_scope = class_exists( 'MAD4B_SCP_Operational_Scope_Guard', false )
			? MAD4B_SCP_Operational_Scope_Guard::require_current()
			: new WP_Error( 'mad4b_scope_guard_missing', 'Verified identity unavailable.' );
		if ( is_wp_error( $verified_scope ) || '' === $site_uuid ||
			! hash_equals( $site_uuid, (string) $verified_scope['site_uuid'] ) ||
			! hash_equals( $brand_id, (string) $verified_scope['brand_ref'] ) ||
			! preg_match( '/^[a-f0-9]{32}$/', $brand_id ) ) return array();
		foreach ( $records as $key => $record ) {
			if ( ! self::valid_source( $record ) ) continue;
			$record_site_uuid = strtolower( trim( (string) $record['site_uuid'] ) );
			if ( ! hash_equals( $site_uuid, $record_site_uuid ) ) continue;
			$record_brand_id = isset( $record['brand_id'] ) ? strtolower( trim( (string) $record['brand_id'] ) ) : '';
			if ( '' === $record_brand_id || ! hash_equals( $brand_id, $record_brand_id ) ) continue;
            if ( ! MAD4B_SCP_Operational_Scope_Guard::record_metadata_matches( $record, $verified_scope ) ) continue;
			$policy = isset( $record['write_policy'] ) ? sanitize_key( (string) $record['write_policy'] ) : 'read_only';
			if ( ! in_array( $policy, array( 'read_only', 'repair_only', 'managed' ), true ) ) $policy = 'read_only';
			if ( 'task_attachment' === ( isset( $record['mode'] ) ? (string) $record['mode'] : '' ) ) $policy = 'read_only';
			$record['write_policy'] = $policy;
			$out[ (string) $key ] = $record;
		}
		return $out;
	}

	private static function authorized_assets_from_records( array $records, array $sources, array $site ) {
		$out = array();
		$site_uuid = isset( $site['site_uuid'] ) ? strtolower( trim( (string) $site['site_uuid'] ) ) : '';
		$verified_scope = class_exists( 'MAD4B_SCP_Operational_Scope_Guard', false )
			? MAD4B_SCP_Operational_Scope_Guard::require_current()
			: new WP_Error( 'mad4b_scope_guard_missing', 'Verified identity unavailable.' );
		if ( is_wp_error( $verified_scope ) || '' === $site_uuid ||
			! hash_equals( $site_uuid, (string) $verified_scope['site_uuid'] ) ) return array();
		foreach ( $records as $key => $record ) {
			if ( ! self::valid_asset( $record ) ) continue;
			$record_site_uuid = isset( $record['site_uuid'] ) ? strtolower( trim( (string) $record['site_uuid'] ) ) : '';
			if ( '' === $record_site_uuid || ! hash_equals( $site_uuid, $record_site_uuid ) ) continue;
			$source_id = isset( $record['source_id'] ) ? (string) $record['source_id'] : '';
			if ( '' === $source_id || ! isset( $sources[ $source_id ] ) ) continue;
			// Unbound legacy assets need reviewed ownership; never adopt by source ID alone.
			$asset_brand_id = isset( $record['brand_id'] ) ? strtolower( trim( (string) $record['brand_id'] ) ) : '';
			if ( '' === $asset_brand_id ||
				! hash_equals( strtolower( (string) $sources[ $source_id ]['brand_id'] ), $asset_brand_id ) ||
				! hash_equals( strtolower( (string) $verified_scope['brand_ref'] ), $asset_brand_id ) ) continue;
            if ( ! MAD4B_SCP_Operational_Scope_Guard::record_metadata_matches( $record, $verified_scope ) ) continue;
			$source_mode = isset( $sources[ $source_id ]['mode'] ) ? (string) $sources[ $source_id ]['mode'] : '';
			$asset_mode = isset( $record['source_mode'] ) ? (string) $record['source_mode'] : '';
			if ( '' === $source_mode || '' === $asset_mode || ! hash_equals( $source_mode, $asset_mode ) ) continue;
			$out[ (string) $key ] = $record;
		}
		return $out;
	}

	public static function categories() {
		return array(
			'brand_strategy' => 'Brand Strategy',
			'brand_positioning' => 'Brand Positioning',
			'audience_persona' => 'Audience / Persona',
			'tone_of_voice' => 'Tone of Voice',
			'messaging' => 'Messaging',
			'editorial_guidelines' => 'Editorial Guidelines',
			'terminology' => 'Terminology',
			'claim_policy' => 'Claim Policy',
			'seo_strategy' => 'SEO Strategy',
			'content_strategy' => 'Content Strategy',
			'campaign_strategy' => 'Campaign Strategy',
			'product_knowledge' => 'Product Knowledge',
			'service_knowledge' => 'Service Knowledge',
			'destination_knowledge' => 'Destination Knowledge',
			'market_research' => 'Market Research',
			'writer_reference' => 'Writer Reference',
			'content_example' => 'Content Example',
			'historical_content' => 'Historical Content',
			'legal_policy' => 'Legal Policy',
			'operational_policy' => 'Operational Policy',
			'uncategorized' => 'Uncategorized',
		);
	}

	public static function authority_classes() {
		return array(
			'brand_authority' => 'Brand Authority',
			'policy_authority' => 'Policy Authority',
			'task_knowledge' => 'Task Knowledge',
			'reference' => 'Reference',
		);
	}

	public static function write_policies() {
		return array(
			'read_only' => array(
				'label' => 'Read-only',
				'operations' => array(),
				'description' => 'Browse, scan, classify and score only.',
			),
			'repair_only' => array(
				'label' => 'Repair existing assets',
				'operations' => array( 'update', 'recreate' ),
				'description' => 'Update existing text assets and recreate assets confirmed unavailable; cannot create unrelated new assets.',
			),
			'managed' => array(
				'label' => 'Managed library',
				'operations' => array( 'create', 'update', 'recreate' ),
				'description' => 'Create, update and recreate governed assets inside this selected source folder.',
			),
		);
	}

	private static function write_policy_rank( $policy ) {
		$ranks = array( 'read_only' => 0, 'repair_only' => 1, 'managed' => 2 );
		$policy = sanitize_key( (string) $policy );
		return isset( $ranks[ $policy ] ) ? (int) $ranks[ $policy ] : 0;
	}

	private static function write_policy_escalation_requires_confirmation( $from, $to ) {
		return self::write_policy_rank( $to ) > self::write_policy_rank( $from );
	}

	private static function assert_write_policy_transition( $from, $to, $confirmed ) {
		if ( ! self::write_policy_escalation_requires_confirmation( $from, $to ) ) return true;
		if ( $confirmed ) return true;
		return new WP_Error(
			'mad4b_context_source_write_policy_confirmation_required',
			'Increasing Context source Drive write authority requires explicit confirmation.',
			array(
				'previous_write_policy' => sanitize_key( (string) $from ),
				'requested_write_policy' => sanitize_key( (string) $to ),
			)
		);
	}

	private static function recursive_scope_expansion_requires_confirmation( array $current, $recursive ) {
		return ! empty( $current ) && empty( $current['recursive'] ) && (bool) $recursive;
	}

	private static function assert_recursive_scope_transition( array $current, $recursive, $confirmed ) {
		if ( ! self::recursive_scope_expansion_requires_confirmation( $current, $recursive ) ) return true;
		if ( $confirmed ) return true;
		return new WP_Error(
			'mad4b_context_source_recursive_confirmation_required',
			'Expanding an existing Context source to include subfolders requires explicit confirmation.',
			array(
				'previous_recursive' => false,
				'requested_recursive' => true,
			)
		);
	}

	public static function source_write_policy( $source_id ) {
		$source = self::source( $source_id );
		return empty( $source ) ? 'read_only' : ( isset( $source['write_policy'] ) ? (string) $source['write_policy'] : 'read_only' );
	}

	public static function source_allows_write( $source_id, $operation ) {
		$source = self::source( $source_id );
		if ( empty( $source ) ) return false;
		if ( 'task_attachment' === ( isset( $source['mode'] ) ? (string) $source['mode'] : '' ) ) return false;
		$operation = sanitize_key( (string) $operation );
		$policy = isset( $source['write_policy'] ) ? sanitize_key( (string) $source['write_policy'] ) : 'read_only';
		$policies = self::write_policies();
		return isset( $policies[ $policy ] ) && in_array( $operation, $policies[ $policy ]['operations'], true );
	}

	public static function writable_source_count( $operation ) {
		$count = 0;
		foreach ( self::sources() as $source_id => $source ) if ( self::source_allows_write( $source_id, $operation ) ) ++$count;
		return $count;
	}

	public static function save_profile( $brand_name, array $options = array() ){
		return self::with_registry_lock(
			'save_profile',
			static function () use ( $brand_name, $options ) {
			$site = self::site_binding();
			if ( is_wp_error( $site ) ) return $site;
			$audit_ready = self::audit_preflight();
			if ( is_wp_error( $audit_ready ) ) return $audit_ready;
			$brand_name = trim( sanitize_text_field( (string) $brand_name ) );
			if ( '' === $brand_name ) return new WP_Error( 'mad4b_brand_context_name_required', 'Brand name is required.' );
			$current = self::profile();
			$old_brand_id = isset( $current['brand_id'] ) ? strtolower( (string) $current['brand_id'] ) : '';
			if ( '' !== $old_brand_id && ! preg_match( '/^[a-f0-9]{32}$/', $old_brand_id ) ) {
				return new WP_Error( 'mad4b_brand_identity_invalid', 'Stored brand identity is invalid. Explicit recovery is required.' );
			}
			$renaming = ! empty( $current ) && ! hash_equals( (string) $current['brand_name'], $brand_name );
			if ( $renaming ) {
				$expected_id = isset( $options['expected_brand_id'] ) ? strtolower( trim( (string) $options['expected_brand_id'] ) ) : '';
				$expected_revision = isset( $options['expected_revision'] ) ? absint( $options['expected_revision'] ) : 0;
				if ( empty( $options['confirm_identity_preserving_rename'] ) || '' === $expected_id || ! hash_equals( $old_brand_id, $expected_id ) ) {
					return new WP_Error( 'mad4b_brand_rename_confirmation_required', 'Confirm this is the same business under a new display name, with the current exact brand identity. Brand replacement requires separate reviewed migration.' );
				}
				if ( $expected_revision !== (int) $current['revision'] ) {
					return new WP_Error( 'mad4b_brand_rename_revision_conflict', 'Brand Profile changed since the rename form was opened. Refresh and review before retrying.' );
			}
			}
			// First enrollment uses an opaque ID, not a name-based alias able to
			// re-adopt abandoned sources after profile removal / re-enrollment.
			$brand_id = '' !== $old_brand_id ? $old_brand_id : strtolower( str_replace( '-', '', wp_generate_uuid4() ) );
			if ( ! preg_match( '/^[a-f0-9]{32}$/', $brand_id ) ) return new WP_Error( 'mad4b_brand_identity_generation_failed', 'Unable to create a canonical brand identity.' );
			$revision = isset( $current['revision'] ) ? max( 1, absint( $current['revision'] ) + 1 ) : 1;
			$record = array(
				'contract' => self::PROFILE_CONTRACT,
				'site_uuid' => $site['site_uuid'],
				// Renaming is not a transfer of authority. Preserve the persisted identity.
				'brand_id' => $brand_id,
				'brand_name' => $brand_name,
				'revision' => $revision,
				'status' => 'configured',
				'context_policy' => 'site_bound_governed_plus_task_sources',
				'context_fingerprint' => self::context_fingerprint(),
				'review_policy' => isset( $current['review_policy'] ) && is_array( $current['review_policy'] ) ? $current['review_policy'] : self::default_review_policy(),
				'last_verified_at' => '',
				'created_at' => isset( $current['created_at'] ) ? (string) $current['created_at'] : gmdate( 'c' ),
				'updated_at' => gmdate( 'c' ),
			);
			if ( ! self::write_option( self::PROFILE_OPTION, $record ) ) return new WP_Error( 'mad4b_context_profile_write_failed', 'Brand Context Profile could not be persisted.' );
			return self::audited_registry_result(
				$record,
				'mad4b/context-profile-save',
				array(
					'site_uuid' => (string) $site['site_uuid'],
					'brand_id' => (string) $record['brand_id'],
					'revision' => (int) $record['revision'],
					'created' => empty( $current ),
					'identity_preserved' => ! empty( $current ),
					'identity_preserving_rename' => $renaming,
					'brand_transfer_performed' => false,
				),
				'ok'
			);

			}
		);
	}

	public static function upsert_source( array $input ) {
		return self::with_registry_lock(
			'upsert_source',
			static function () use ( $input ) {
				$site = self::site_binding();
				if ( is_wp_error( $site ) ) return $site;
				$profile = self::profile();
				if ( empty( $profile ) ) return new WP_Error( 'mad4b_brand_context_profile_required', 'Configure the Brand Context Profile before adding sources.' );
				$audit_ready = self::audit_preflight();
				if ( is_wp_error( $audit_ready ) ) return $audit_ready;
				// MCP-managed source writes carry two exact identity assertions.
				// Validate while the same registry lock used for persistence is held.
				if ( array_key_exists( 'expected_registry_revision', $input ) &&
					(int) $input['expected_registry_revision'] !== self::registry_revision() )
					return new WP_Error( 'mad4b_context_source_registry_revision_stale',
						'Source registry changed after review; replan against current authority.' );
				if ( array_key_exists( 'expected_authority_manifest_fingerprint', $input ) ) {
					$claimed = (string) $input['expected_authority_manifest_fingerprint'];
					$current_manifest = self::authority_manifest_fingerprint();
					if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $claimed ) ||
						! hash_equals( $current_manifest, $claimed ) )
						return new WP_Error( 'mad4b_context_source_authority_manifest_stale',
							'Source authority manifest changed; reviewed plan cannot be reused.' );
				}

				$provider = sanitize_key( isset( $input['provider'] ) ? $input['provider'] : '' );
				$mode = sanitize_key( isset( $input['mode'] ) ? $input['mode'] : 'governed' );
				if ( 'google_drive' !== $provider ) return new WP_Error( 'mad4b_context_source_provider_invalid', 'Only the governed Google Drive context provider is supported in this foundation.' );
				if ( ! in_array( $mode, array( 'governed', 'task_attachment' ), true ) ) return new WP_Error( 'mad4b_context_source_mode_invalid', 'Context source mode must be governed or task_attachment.' );

				$external_root_id = self::bounded_external_id( isset( $input['external_root_id'] ) ? $input['external_root_id'] : '' );
				if ( '' === $external_root_id ) return new WP_Error( 'mad4b_context_source_root_required', 'A canonical Google Drive folder ID is required.' );
				if ( 'root' === strtolower( $external_root_id ) ) return new WP_Error( 'mad4b_context_source_root_forbidden', 'My Drive root is browse-only. Select a specific Google Drive folder as the Context source boundary.' );
				$label = trim( sanitize_text_field( isset( $input['label'] ) ? $input['label'] : '' ) );
				if ( '' === $label ) $label = 'Google Drive Folder';
				$task_scope = trim( sanitize_text_field( isset( $input['task_scope'] ) ? $input['task_scope'] : '' ) );
				if ( 'task_attachment' === $mode && '' === $task_scope ) return new WP_Error( 'mad4b_context_task_scope_required', 'Task-only sources require a task scope label.' );

				$authorized_sources = self::sources();
				$sources = self::raw_sources();
				$source_id = hash( 'sha256', $site['site_uuid'] . '|' . $provider . '|' . $mode . '|' . $external_root_id . '|' . $task_scope );
				// Legacy IDs are site-scoped. Never overwrite an invisible source owned by
				// another brand, even if the external folder and mode match.
				if ( isset( $sources[ $source_id ] ) ) {
					$stored_brand = isset( $sources[ $source_id ]['brand_id'] ) ? strtolower( trim( (string) $sources[ $source_id ]['brand_id'] ) ) : '';
					if ( '' === $stored_brand || ! hash_equals( strtolower( (string) $profile['brand_id'] ), $stored_brand ) ) {
						return new WP_Error( 'mad4b_context_source_brand_collision', 'This source belongs to another or unverified brand. Review its ownership before migration.' );
					}
				}
				$current = isset( $authorized_sources[ $source_id ] ) ? $authorized_sources[ $source_id ] : array();
				if ( ! isset( $sources[ $source_id ] ) && count( $sources ) >= self::MAX_SOURCES ) {
					return new WP_Error(
						'mad4b_context_source_registry_capacity_limit',
						'Context source registry is at its certified storage limit. Remove or remediate an existing source before adding another.',
						array( 'limit' => self::MAX_SOURCES, 'stored_source_count' => count( $sources ) )
					);
				}
				$previous_write_policy = isset( $current['write_policy'] ) ? sanitize_key( (string) $current['write_policy'] ) : 'read_only';
				$write_policy = isset( $input['write_policy'] )
					? sanitize_key( (string) $input['write_policy'] )
					: $previous_write_policy;
				if ( ! isset( self::write_policies()[ $write_policy ] ) ) return new WP_Error( 'mad4b_context_source_write_policy_invalid', 'Context source write policy is invalid.' );
				if ( 'task_attachment' === $mode && 'read_only' !== $write_policy ) return new WP_Error( 'mad4b_context_task_source_write_forbidden', 'Task-only Context sources are read-only in this release.' );
				$transition = self::assert_write_policy_transition( $previous_write_policy, $write_policy, ! empty( $input['write_policy_confirmed'] ) );
				if ( is_wp_error( $transition ) ) return $transition;

				$previous_recursive = ! empty( $current ) ? ! empty( $current['recursive'] ) : null;
				$recursive = array_key_exists( 'recursive', $input )
					? ! empty( $input['recursive'] )
					: ( null === $previous_recursive ? true : $previous_recursive );
				$recursive_transition = self::assert_recursive_scope_transition( $current, $recursive, ! empty( $input['recursive_scope_confirmed'] ) );
				if ( is_wp_error( $recursive_transition ) ) return $recursive_transition;

				$record = array(
					'contract' => self::SOURCE_CONTRACT,
					'source_id' => $source_id,
					'site_uuid' => $site['site_uuid'],
					'brand_id' => (string) $profile['brand_id'],
					'provider' => $provider,
					'mode' => $mode,
					'external_root_id' => $external_root_id,
					'label' => $label,
					'task_scope' => 'task_attachment' === $mode ? $task_scope : '',
					'write_policy' => $write_policy,
					'recursive' => (bool) $recursive,
					'status' => isset( $current['status'] ) ? (string) $current['status'] : 'selected',
					'last_synced_at' => isset( $current['last_synced_at'] ) ? (string) $current['last_synced_at'] : '',
					'last_scan_complete' => ! empty( $current['last_scan_complete'] ),
					'last_scan_generation' => isset( $current['last_scan_generation'] ) ? (string) $current['last_scan_generation'] : '',
					'last_complete_scan_generation' => isset( $current['last_complete_scan_generation'] ) ? (string) $current['last_complete_scan_generation'] : '',
					'last_complete_scan_at' => isset( $current['last_complete_scan_at'] ) ? (string) $current['last_complete_scan_at'] : '',
					'last_scan_truncation_reasons' => isset( $current['last_scan_truncation_reasons'] ) && is_array( $current['last_scan_truncation_reasons'] ) ? $current['last_scan_truncation_reasons'] : array(),
					'asset_count' => isset( $current['asset_count'] ) ? absint( $current['asset_count'] ) : 0,
					'created_at' => isset( $current['created_at'] ) ? (string) $current['created_at'] : gmdate( 'c' ),
					'updated_at' => gmdate( 'c' ),
				);
				$sources[ $source_id ] = $record;
				if ( ! self::write_option( self::SOURCES_OPTION, $sources ) ) return new WP_Error( 'mad4b_context_source_registry_write_failed', 'Context source registry could not be persisted.' );
				return self::audited_registry_result(
					$record,
					'mad4b/context-source-upsert',
					array(
						'source_id' => $source_id,
						'provider' => $provider,
						'mode' => $mode,
						'previous_write_policy' => $previous_write_policy,
						'write_policy' => $write_policy,
						'write_policy_escalated' => self::write_policy_escalation_requires_confirmation( $previous_write_policy, $write_policy ),
						'write_policy_confirmed' => ! empty( $input['write_policy_confirmed'] ),
						'previous_recursive' => null === $previous_recursive ? null : (bool) $previous_recursive,
						'recursive' => ! empty( $record['recursive'] ),
						'recursive_scope_escalated' => self::recursive_scope_expansion_requires_confirmation( $current, $recursive ),
						'recursive_scope_confirmed' => ! empty( $input['recursive_scope_confirmed'] ),
						'created' => empty( $current ),
					),
					'ok'
				);
			}
		);
	}

	public static function replace_source_assets( $source_id, array $assets, array $scan = array() ) {
		return self::with_registry_lock(
			'replace_source_assets',
			static function () use ( $source_id, $assets, $scan ) {
				$source_id = strtolower( trim( (string) $source_id ) );
				$authorized_sources = self::sources();
				if ( ! isset( $authorized_sources[ $source_id ] ) ) return new WP_Error( 'mad4b_context_source_not_found', 'Context source was not found.' );
				$audit_ready = self::audit_preflight();
				if ( is_wp_error( $audit_ready ) ) return $audit_ready;
				$source = $authorized_sources[ $source_id ];
				$sources = self::raw_sources();
				$records = self::raw_assets();
				$previous = array();
				foreach ( $records as $asset_id => $record ) {
					if ( ! isset( $record['source_id'] ) || ! hash_equals( $source_id, (string) $record['source_id'] ) ) continue;
					// A scan can reuse prior approval evidence and mint absence decisions.
					// A foreign or unbound asset must not participate in either operation.
					$record_brand = isset( $record['brand_id'] ) ? strtolower( trim( (string) $record['brand_id'] ) ) : '';
					$record_site = isset( $record['site_uuid'] ) ? strtolower( trim( (string) $record['site_uuid'] ) ) : '';
					if ( '' === $record_brand || ! hash_equals( strtolower( (string) $source['brand_id'] ), $record_brand ) ||
						'' === $record_site || ! hash_equals( strtolower( (string) $source['site_uuid'] ), $record_site ) ) {
						return new WP_Error( 'mad4b_context_scan_foreign_asset_quarantined', 'Scan halted: prior assets include unverified or different-brand ownership. Review registry lineage before an authorized rescan.' );
					}
					$previous[ (string) $asset_id ] = $record;
				}

				$scan_started_at = isset( $scan['started_at'] ) ? sanitize_text_field( (string) $scan['started_at'] ) : gmdate( 'c' );
				$scan_completed_at = isset( $scan['completed_at'] ) ? sanitize_text_field( (string) $scan['completed_at'] ) : gmdate( 'c' );
				$scan_complete = ! array_key_exists( 'complete', $scan ) || ! empty( $scan['complete'] );
				$scan_generation = isset( $scan['scan_generation'] ) && preg_match( '/^[a-f0-9]{64}$/', (string) $scan['scan_generation'] )
					? strtolower( (string) $scan['scan_generation'] )
					: hash( 'sha256', $source_id . '|' . $scan_started_at . '|' . count( $assets ) );
				$truncation_reasons = isset( $scan['truncation_reasons'] ) && is_array( $scan['truncation_reasons'] )
					? array_values( array_unique( array_filter( array_map( 'sanitize_key', $scan['truncation_reasons'] ) ) ) )
					: array();

				if ( count( $records ) > self::MAX_ASSETS ) {
					$scan_complete = false;
					$truncation_reasons[] = 'registry_asset_capacity_exceeded';
				}
				if ( count( $assets ) > self::MAX_ASSETS ) {
					$scan_complete = false;
					$truncation_reasons[] = 'scan_asset_input_limit';
				}

				// Phase 1: normalize every observed record we intend to represent.
				// No absence state is minted until this phase proves representability.
				$normalized_assets = array();
				$reused_asset_count = 0;
				foreach ( array_slice( $assets, 0, self::MAX_ASSETS ) as $asset ) {
					if ( ! is_array( $asset ) ) {
						$scan_complete = false;
						$truncation_reasons[] = 'asset_record_invalid';
						continue;
					}

					$file_id = isset( $asset['file_id'] ) ? self::bounded_external_id( $asset['file_id'] ) : '';
					$candidate_asset_id = '' !== $file_id ? hash( 'sha256', $source_id . '|' . $file_id ) : '';
					$reuse_prior = ! empty( $asset['reuse_existing'] ) && '' !== $candidate_asset_id && isset( $previous[ $candidate_asset_id ] )
						? $previous[ $candidate_asset_id ]
						: array();
					$reuse_exact = ! empty( $reuse_prior )
						&& ! empty( $reuse_prior['content_complete'] )
						&& ! empty( $reuse_prior['content_hash'] )
						&& isset( $asset['content_hash'] )
						&& preg_match( '/^[a-f0-9]{64}$/', (string) $asset['content_hash'] )
						&& hash_equals( (string) $reuse_prior['content_hash'], strtolower( (string) $asset['content_hash'] ) )
						&& isset( $asset['modifiedTime'], $reuse_prior['version'] )
						&& '' !== (string) $asset['modifiedTime']
						&& hash_equals( (string) $reuse_prior['version'], (string) $asset['modifiedTime'] );

					if ( $reuse_exact ) {
						$normalized = $reuse_prior;
						$normalized['parent_folder_id'] = isset( $asset['parent_folder_id'] ) ? self::bounded_external_id( $asset['parent_folder_id'] ) : ( isset( $normalized['parent_folder_id'] ) ? (string) $normalized['parent_folder_id'] : '' );
						$normalized['title'] = isset( $asset['title'] ) && '' !== trim( (string) $asset['title'] ) ? trim( sanitize_text_field( (string) $asset['title'] ) ) : ( isset( $normalized['title'] ) ? (string) $normalized['title'] : 'Untitled' );
						$normalized['path'] = isset( $asset['path'] ) ? substr( sanitize_text_field( (string) $asset['path'] ), 0, 500 ) : ( isset( $normalized['path'] ) ? (string) $normalized['path'] : '' );
						$normalized['mime_type'] = isset( $asset['mimeType'] ) ? substr( sanitize_text_field( (string) $asset['mimeType'] ), 0, 191 ) : ( isset( $normalized['mime_type'] ) ? (string) $normalized['mime_type'] : '' );
						$normalized['version'] = (string) $asset['modifiedTime'];
						$normalized['content_hash'] = (string) $reuse_prior['content_hash'];
						$normalized['content_complete'] = true;
						$normalized['content_bytes'] = isset( $reuse_prior['content_bytes'] ) ? (int) $reuse_prior['content_bytes'] : 0;
						$normalized['normalization_status'] = 'reused';
						$normalized['normalization_reason'] = 'unchanged_provider_version';
						$normalized['status'] = 'ready';
						$normalized['last_synced_at'] = $scan_completed_at;
						++$reused_asset_count;
					} else {
						$normalized = self::normalize_asset( $source, $asset );
						if ( is_wp_error( $normalized ) ) {
							$scan_complete = false;
							$truncation_reasons[] = 'asset_normalization_failed';
							continue;
						}
					}
					$prior = isset( $previous[ $normalized['asset_id'] ] ) ? $previous[ $normalized['asset_id'] ] : array();
					$prior_classification_source = isset( $prior['classification_source'] ) ? (string) $prior['classification_source'] : '';
					$human_classification = 'human' === $prior_classification_source;
					$generated_classification = 'brand_context_builder' === $prior_classification_source;
					if ( $human_classification || $generated_classification ) {
						// Explicit human or governed generated classification/authority survives
						// provider rescans. Approval evidence remains exact-content-hash bound.
						$normalized['category'] = isset( $prior['category'] ) ? (string) $prior['category'] : $normalized['category'];
						$normalized['classification_confidence'] = $human_classification ? 1.0 : ( isset( $prior['classification_confidence'] ) ? (float) $prior['classification_confidence'] : 1.0 );
						$normalized['classification_source'] = $human_classification ? 'human' : 'brand_context_builder';
						$normalized['authority_class'] = isset( $prior['authority_class'] ) ? (string) $prior['authority_class'] : $normalized['authority_class'];
						$normalized['required'] = ! empty( $prior['required'] );
						$normalized['priority'] = isset( $prior['priority'] ) ? (int) $prior['priority'] : $normalized['priority'];

						if ( $generated_classification ) {
							foreach ( array( 'generated_artifact_id', 'generation_evidence_digest', 'materialization_receipt_sha256' ) as $generated_field ) {
								if ( array_key_exists( $generated_field, $prior ) ) $normalized[ $generated_field ] = $prior[ $generated_field ];
							}
						}

						$same_content = ! empty( $prior['content_hash'] ) && hash_equals( (string) $prior['content_hash'], (string) $normalized['content_hash'] );
						$prior_review_status = isset( $prior['review_status'] ) ? (string) $prior['review_status'] : '';
						$prior_reviewed_hash = isset( $prior['reviewed_content_hash'] ) ? strtolower( trim( (string) $prior['reviewed_content_hash'] ) ) : '';
						$prior_review_bound = $same_content
							&& in_array( $prior_review_status, array( 'approved', 'needs_changes', 'rejected' ), true )
							&& ! empty( $prior['reviewed_at'] )
							&& preg_match( '/^[a-f0-9]{64}$/', $prior_reviewed_hash )
							&& hash_equals( $prior_reviewed_hash, (string) $normalized['content_hash'] );

						if ( $prior_review_bound ) {
							$normalized['reviewed_by'] = isset( $prior['reviewed_by'] ) ? absint( $prior['reviewed_by'] ) : 0;
							$normalized['reviewed_at'] = (string) $prior['reviewed_at'];
							$normalized['review_status'] = $prior_review_status;
							$normalized['reviewed_content_hash'] = $prior_reviewed_hash;
							$normalized['review_decision'] = isset( $prior['review_decision'] ) ? sanitize_key( (string) $prior['review_decision'] ) : ( 'approved' === $prior_review_status ? 'approve' : ( 'rejected' === $prior_review_status ? 'reject' : 'needs_changes' ) );
							$normalized['review_note'] = isset( $prior['review_note'] ) ? substr( sanitize_text_field( (string) $prior['review_note'] ), 0, 1000 ) : '';
							if ( ! empty( $prior['quality']['human_override'] ) ) {
								$normalized['quality_score'] = isset( $prior['quality_score'] ) ? (int) $prior['quality_score'] : $normalized['quality_score'];
								$normalized['quality'] = $prior['quality'];
							}
						} elseif ( $generated_classification && $same_content && in_array( $prior_review_status, array( '', 'unreviewed' ), true ) ) {
							$normalized['reviewed_by'] = 0;
							$normalized['reviewed_at'] = '';
							$normalized['review_status'] = 'unreviewed';
							$normalized['reviewed_content_hash'] = '';
							$normalized['review_decision'] = '';
							$normalized['review_note'] = '';
							$normalized['review_actor_type'] = '';
							$normalized['review_agent_public_id'] = '';
							if ( isset( $normalized['quality'] ) && is_array( $normalized['quality'] ) ) $normalized['quality']['provisional'] = true;
						} else {
							$normalized['reviewed_by'] = 0;
							$normalized['reviewed_at'] = '';
							$normalized['review_status'] = 'needs_review_content_changed';
							$normalized['reviewed_content_hash'] = '';
							$normalized['review_decision'] = '';
							$normalized['review_note'] = '';
							$normalized['review_actor_type'] = '';
							$normalized['review_agent_public_id'] = '';
						}
					}
					$normalized['availability_reason'] = '';
					$normalized['last_seen_at'] = $scan_completed_at;
					$normalized['last_missing_at'] = isset( $prior['last_missing_at'] ) ? (string) $prior['last_missing_at'] : '';
					unset( $normalized['absence_scan_generation'] );
					$normalized_assets[ (string) $normalized['asset_id'] ] = $normalized;
				}

				// Phase 2: prove the raw registry can represent every observed unique
				// asset without deleting unrelated/hidden storage records.
				$selected_assets = array();
				$new_slots = max( 0, self::MAX_ASSETS - count( $records ) );
				foreach ( $normalized_assets as $asset_id => $normalized ) {
					if ( isset( $records[ $asset_id ] ) ) {
						$selected_assets[ $asset_id ] = $normalized;
						continue;
					}
					if ( $new_slots > 0 ) {
						$selected_assets[ $asset_id ] = $normalized;
						--$new_slots;
						continue;
					}
					$scan_complete = false;
					$truncation_reasons[] = 'registry_asset_capacity_limit';
				}

				// Only a fully observed + normalized + representable scan may create
				// absence evidence for an asset that was not observed this generation.
				if ( $scan_complete ) {
					foreach ( $previous as $asset_id => $record ) {
						if ( isset( $selected_assets[ $asset_id ] ) ) continue;
						$records[ $asset_id ]['status'] = 'unavailable';
						$records[ $asset_id ]['availability_reason'] = 'not_seen_in_complete_scan';
						$records[ $asset_id ]['last_missing_at'] = $scan_completed_at;
						$records[ $asset_id ]['absence_scan_generation'] = $scan_generation;
					}
				}

				foreach ( $selected_assets as $asset_id => $normalized ) $records[ $asset_id ] = $normalized;

				$truncation_reasons = array_values( array_unique( array_filter( array_map( 'sanitize_key', $truncation_reasons ) ) ) );
				$sources[ $source_id ]['status'] = $scan_complete ? 'ready' : 'partial_scan';
				$sources[ $source_id ]['last_synced_at'] = $scan_completed_at;
				$sources[ $source_id ]['last_scan_complete'] = (bool) $scan_complete;
				$sources[ $source_id ]['last_scan_generation'] = $scan_generation;
				$sources[ $source_id ]['last_scan_truncation_reasons'] = $truncation_reasons;
				if ( $scan_complete ) {
					$sources[ $source_id ]['last_complete_scan_generation'] = $scan_generation;
					$sources[ $source_id ]['last_complete_scan_at'] = $scan_completed_at;
				}

				$site = self::site_binding();
				$proposed_sources = is_wp_error( $site ) ? array() : self::authorized_sources_from_records( $sources, $site );
				$proposed_assets = is_wp_error( $site ) ? array() : self::authorized_assets_from_records( $records, $proposed_sources, $site );
				$source_asset_count = 0;
				foreach ( $proposed_assets as $proposed_asset ) {
					if ( isset( $proposed_asset['source_id'] ) && hash_equals( $source_id, (string) $proposed_asset['source_id'] ) ) ++$source_asset_count;
				}
				$sources[ $source_id ]['asset_count'] = $source_asset_count;
				$sources[ $source_id ]['updated_at'] = gmdate( 'c' );

				$profile = self::profile();
				if ( ! empty( $profile ) ) {
					$profile['context_fingerprint'] = self::context_fingerprint( $records, $sources );
					$profile['authority_manifest_fingerprint'] = self::authority_manifest_fingerprint( $records, $sources );
					if ( $scan_complete ) $profile['last_verified_at'] = $scan_completed_at;
					$profile['status'] = $scan_complete ? 'indexed' : 'partial_index';
					$profile['updated_at'] = gmdate( 'c' );
				}
				$changes = array(
					self::ASSETS_OPTION => $records,
					self::SOURCES_OPTION => $sources,
				);
				if ( ! empty( $profile ) ) $changes[ self::PROFILE_OPTION ] = $profile;
				$commit = self::commit_option_changes(
					$changes,
					'mad4b_context_scan_registry_commit_failed',
					'Context scan registry state could not be committed atomically.'
				);
				if ( is_wp_error( $commit ) ) return $commit;

				$result = array(
					'source' => $sources[ $source_id ],
					'asset_count' => $source_asset_count,
					'observed_asset_count' => count( $normalized_assets ),
					'represented_asset_count' => count( $selected_assets ),
					'reused_asset_count' => $reused_asset_count,
					'scan_complete' => (bool) $scan_complete,
					'scan_generation' => $scan_generation,
					'truncation_reasons' => $truncation_reasons,
					'context_fingerprint' => self::context_fingerprint( $records, $sources ),
					'authority_manifest_fingerprint' => self::authority_manifest_fingerprint( $records, $sources ),
				);
				return self::audited_registry_result(
					$result,
					'mad4b/context-source-scan',
					array(
						'source_id' => $source_id,
						'provider' => isset( $source['provider'] ) ? (string) $source['provider'] : '',
						'mode' => isset( $source['mode'] ) ? (string) $source['mode'] : '',
						'scan_generation' => $scan_generation,
						'scan_complete' => (bool) $scan_complete,
						'observed_asset_count' => count( $normalized_assets ),
						'represented_asset_count' => count( $selected_assets ),
						'reused_asset_count' => $reused_asset_count,
						'truncation_reasons' => $truncation_reasons,
					),
					$scan_complete ? 'ok' : 'partial'
				);
			}
		);
	}

	public static function source( $source_id ) {
		$source_id = strtolower( trim( sanitize_text_field( (string) $source_id ) ) );
		$sources = self::sources();
		return isset( $sources[ $source_id ] ) ? $sources[ $source_id ] : array();
	}

	public static function asset( $asset_id ) {
		$asset_id = strtolower( trim( sanitize_text_field( (string) $asset_id ) ) );
		$assets = self::assets();
		return isset( $assets[ $asset_id ] ) ? $assets[ $asset_id ] : array();
	}

	public static function upsert_asset_from_provider( $source_id, array $provider_asset, array $preserve = array() ) {
		return self::with_registry_lock(
			'upsert_asset_from_provider',
			static function () use ( $source_id, $provider_asset, $preserve ) {
				$source = self::source( $source_id );
				if ( empty( $source ) ) return new WP_Error( 'mad4b_context_source_not_found', 'Context source was not found.' );
				$normalized = self::normalize_asset( $source, $provider_asset );
				if ( is_wp_error( $normalized ) ) return $normalized;
				$records = self::raw_assets();
				if ( ! isset( $records[ $normalized['asset_id'] ] ) && count( $records ) >= self::MAX_ASSETS ) {
					return new WP_Error(
						'mad4b_context_asset_registry_capacity_limit',
						'Context asset registry is at its certified storage limit. New provider assets cannot be registered without explicit remediation.',
						array( 'limit' => self::MAX_ASSETS, 'stored_asset_count' => count( $records ) )
					);
				}
				if ( isset( $records[ $normalized['asset_id'] ] ) && empty( self::asset( $normalized['asset_id'] ) ) ) {
					return new WP_Error( 'mad4b_context_asset_brand_collision', 'Asset identity is already reserved outside the current trusted brand scope.' );
				}
				$existing = isset( $records[ $normalized['asset_id'] ] ) && is_array( $records[ $normalized['asset_id'] ] ) ? $records[ $normalized['asset_id'] ] : array();
				$review_source = ! empty( $preserve ) ? $preserve : $existing;

				// Classification/authority is governance metadata and may survive a
				// provider content change. Content approval and manual quality never
				// do: they are evidence about an exact content hash.
				foreach ( array( 'category', 'classification_confidence', 'classification_source', 'authority_class', 'required', 'priority' ) as $field ) {
					if ( array_key_exists( $field, $preserve ) ) $normalized[ $field ] = $preserve[ $field ];
					elseif ( ! empty( $existing['reviewed_at'] ) && 'human' === ( isset( $existing['classification_source'] ) ? $existing['classification_source'] : '' ) && array_key_exists( $field, $existing ) ) $normalized[ $field ] = $existing[ $field ];
				}

				$previous_hash = isset( $review_source['content_hash'] ) ? strtolower( trim( (string) $review_source['content_hash'] ) ) : '';
				$current_hash = isset( $normalized['content_hash'] ) ? strtolower( trim( (string) $normalized['content_hash'] ) ) : '';
				$same_content = preg_match( '/^[a-f0-9]{64}$/', $previous_hash )
					&& preg_match( '/^[a-f0-9]{64}$/', $current_hash )
					&& hash_equals( $previous_hash, $current_hash );
				$human_review = ! empty( $review_source['reviewed_at'] )
					&& 'human' === ( isset( $review_source['classification_source'] ) ? (string) $review_source['classification_source'] : '' );

				if ( $human_review && $same_content ) {
					foreach ( array( 'reviewed_by', 'reviewed_at', 'review_status', 'reviewed_content_hash', 'review_decision', 'review_note', 'review_actor_type', 'review_agent_public_id' ) as $field ) {
						if ( array_key_exists( $field, $review_source ) ) $normalized[ $field ] = $review_source[ $field ];
					}
				} elseif ( $human_review && ! $same_content ) {
					$normalized['reviewed_by'] = 0;
					$normalized['reviewed_at'] = '';
					$normalized['review_status'] = 'needs_review_content_changed';
					$normalized['reviewed_content_hash'] = '';
					$normalized['review_decision'] = '';
					$normalized['review_note'] = '';
				}

				if ( $same_content && ! empty( $review_source['quality']['human_override'] ) ) {
					$normalized['quality_score'] = isset( $review_source['quality_score'] ) ? (int) $review_source['quality_score'] : $normalized['quality_score'];
					$normalized['quality'] = $review_source['quality'];
				}
				$normalized['availability_reason'] = '';
				$normalized['last_seen_at'] = gmdate( 'c' );
				$records[ $normalized['asset_id'] ] = $normalized;
				$sources = self::raw_sources();
				$profile = self::refreshed_profile_record( $records, $sources, true );
				$changes = array( self::ASSETS_OPTION => $records );
				if ( ! empty( $profile ) ) $changes[ self::PROFILE_OPTION ] = $profile;
				$commit = self::commit_option_changes(
					$changes,
					'mad4b_context_asset_registry_write_failed',
					'Context asset registry and fingerprint could not be committed atomically.'
				);
				if ( is_wp_error( $commit ) ) return $commit;
				return $normalized;
			}
		);
	}

	public static function register_recreated_asset( $old_asset_id, $source_id, array $provider_asset, array $preserve = array() ) {
		return self::with_registry_lock(
			'register_recreated_asset',
			static function () use ( $old_asset_id, $source_id, $provider_asset, $preserve ) {
				$old_asset_id = strtolower( trim( sanitize_text_field( (string) $old_asset_id ) ) );
				$source_id = strtolower( trim( sanitize_text_field( (string) $source_id ) ) );
				$source = self::source( $source_id );
				if ( empty( $source ) ) return new WP_Error( 'mad4b_context_source_not_found', 'Context source was not found for recreation.' );
				$records = self::raw_assets();
				if ( ! isset( $records[ $old_asset_id ] ) ) return new WP_Error( 'mad4b_context_recreate_original_missing', 'Original Context asset is missing from the registry.' );
				$original = $records[ $old_asset_id ];
				if ( empty( self::asset( $old_asset_id ) ) ) return new WP_Error( 'mad4b_context_recreate_brand_mismatch', 'Original Context asset is outside the current brand.' );
				if ( ! hash_equals( (string) $original['source_id'], $source_id ) ) return new WP_Error( 'mad4b_context_recreate_source_mismatch', 'Original Context asset is not bound to the requested source.' );
				if ( 'unavailable' !== ( isset( $original['status'] ) ? (string) $original['status'] : '' ) ) return new WP_Error( 'mad4b_context_recreate_original_not_unavailable', 'Only an unavailable Context asset can be atomically replaced.' );

				$normalized = self::normalize_asset( $source, $provider_asset );
				if ( is_wp_error( $normalized ) ) return $normalized;
				if ( $old_asset_id === (string) $normalized['asset_id'] ) return new WP_Error( 'mad4b_context_recreate_identity_collision', 'Recreated provider asset unexpectedly reused the unavailable asset identity.' );
				if ( isset( $records[ $normalized['asset_id'] ] ) && empty( self::asset( $normalized['asset_id'] ) ) ) return new WP_Error( 'mad4b_context_recreate_brand_collision', 'Replacement identity is reserved by another brand.' );
				if ( ! isset( $records[ $normalized['asset_id'] ] ) && count( $records ) >= self::MAX_ASSETS ) {
					return new WP_Error(
						'mad4b_context_asset_registry_capacity_limit',
						'Context asset registry is full; recreated provider asset cannot be committed safely.',
						array( 'limit' => self::MAX_ASSETS, 'stored_asset_count' => count( $records ) )
					);
				}
				foreach ( array( 'category', 'classification_confidence', 'classification_source', 'authority_class', 'required', 'priority' ) as $field ) {
					if ( array_key_exists( $field, $preserve ) ) $normalized[ $field ] = $preserve[ $field ];
					elseif ( array_key_exists( $field, $original ) ) $normalized[ $field ] = $original[ $field ];
				}
				// A recreated file has a new provider identity and newly supplied
				// content. Preserve governance classification, never the old content
				// approval or a manual quality override.
				$normalized['reviewed_by'] = 0;
				$normalized['reviewed_at'] = '';
				$normalized['review_status'] = 'needs_review_content_changed';
				$normalized['reviewed_content_hash'] = '';
				$normalized['review_decision'] = '';
				$normalized['review_note'] = '';
				$normalized['availability_reason'] = '';
				$normalized['last_seen_at'] = gmdate( 'c' );

				$records[ $old_asset_id ]['status'] = 'recreated';
				$records[ $old_asset_id ]['availability_reason'] = 'replacement_created';
				$records[ $old_asset_id ]['replacement_asset_id'] = (string) $normalized['asset_id'];
				$records[ $old_asset_id ]['recreated_at'] = gmdate( 'c' );
				$records[ $normalized['asset_id'] ] = $normalized;
				$sources = self::raw_sources();
				$profile = self::refreshed_profile_record( $records, $sources, true );
				$changes = array( self::ASSETS_OPTION => $records );
				if ( ! empty( $profile ) ) $changes[ self::PROFILE_OPTION ] = $profile;
				$commit = self::commit_option_changes(
					$changes,
					'mad4b_context_recreate_registry_commit_failed',
					'Context recreation registry state could not be committed atomically.'
				);
				if ( is_wp_error( $commit ) ) return $commit;
				return self::audited_registry_result(
					array( 'original' => $records[ $old_asset_id ], 'replacement' => $normalized ),
					'mad4b/context-recreated-asset-registered',
					array(
						'asset_id' => $old_asset_id,
						'replacement_asset_id' => (string) $normalized['asset_id'],
						'source_id' => $source_id,
						'file_id' => (string) $normalized['file_id'],
					),
					'ok'
				);
			}
		);
	}

	public static function mark_generated_brand_draft( $asset_id, $category, $artifact_id, $evidence_digest, $receipt_sha256, array $generation = array() ) {
		return self::with_registry_lock(
			'mark_generated_brand_draft',
			static function () use ( $asset_id, $category, $artifact_id, $evidence_digest, $receipt_sha256, $generation ) {
				$asset_id = strtolower( trim( sanitize_text_field( (string) $asset_id ) ) );
				$category = sanitize_key( (string) $category );
				if ( ! in_array( $category, array( 'tone_of_voice', 'editorial_guidelines' ), true ) ) return new WP_Error( 'mad4b_brand_generated_category_invalid', 'Generated Brand Context category is invalid.' );
				$records = self::raw_assets();
				if ( ! isset( $records[ $asset_id ] ) ) return new WP_Error( 'mad4b_brand_generated_asset_missing', 'Generated Brand Context asset is missing from the registry.' );
				if ( empty( self::asset( $asset_id ) ) ) return new WP_Error( 'mad4b_context_asset_brand_mismatch', 'Asset is outside the current trusted brand scope.' );
				$records[ $asset_id ]['category'] = $category;
				$records[ $asset_id ]['authority_class'] = 'brand_authority';
				$records[ $asset_id ]['required'] = true;
				$records[ $asset_id ]['classification_source'] = 'brand_context_builder';
				$records[ $asset_id ]['review_status'] = 'unreviewed';
				$records[ $asset_id ]['reviewed_content_hash'] = '';
				$records[ $asset_id ]['review_decision'] = '';
				$records[ $asset_id ]['generated_artifact_id'] = strtolower( trim( (string) $artifact_id ) );
				$records[ $asset_id ]['generation_evidence_digest'] = strtolower( trim( (string) $evidence_digest ) );
				$records[ $asset_id ]['generation_plan_sha256'] = strtolower( trim( (string) ( isset( $generation['plan_sha256'] ) ? $generation['plan_sha256'] : '' ) ) );
				$records[ $asset_id ]['generation_job_id'] = strtolower( trim( (string) ( isset( $generation['job_id'] ) ? $generation['job_id'] : '' ) ) );
				$records[ $asset_id ]['generation_draft_preflight_sha256'] = strtolower( trim( (string) ( isset( $generation['draft_preflight_sha256'] ) ? $generation['draft_preflight_sha256'] : '' ) ) );
				$records[ $asset_id ]['generation_include_rendered_frontend'] = ! empty( $generation['include_rendered_frontend'] );
				$records[ $asset_id ]['generation_builder_spec_version'] = isset( $generation['builder_spec_version'] ) ? (string) $generation['builder_spec_version'] : '';
				$records[ $asset_id ]['generation_evidence_stale_override'] = false;
				$records[ $asset_id ]['materialization_receipt_sha256'] = strtolower( trim( (string) $receipt_sha256 ) );
				if ( isset( $records[ $asset_id ]['quality'] ) && is_array( $records[ $asset_id ]['quality'] ) ) $records[ $asset_id ]['quality']['provisional'] = true;
				$sources = self::raw_sources();
				$profile = self::refreshed_profile_record( $records, $sources, true );
				$changes = array( self::ASSETS_OPTION => $records );
				if ( ! empty( $profile ) ) $changes[ self::PROFILE_OPTION ] = $profile;
				$commit = self::commit_option_changes( $changes, 'mad4b_brand_generated_registry_write_failed', 'Generated Brand Context registry state could not be committed.' );
				return is_wp_error( $commit ) ? $commit : $records[ $asset_id ];
			}
		);
	}

	public static function begin_generated_brand_rollback( $asset_id, $artifact_id, $receipt_sha256 ) {
		return self::with_registry_lock(
			'begin_generated_brand_rollback',
			static function () use ( $asset_id, $artifact_id, $receipt_sha256 ) {
				$asset_id = strtolower( trim( sanitize_text_field( (string) $asset_id ) ) );
				$artifact_id = strtolower( trim( sanitize_text_field( (string) $artifact_id ) ) );
				$receipt_sha256 = strtolower( trim( (string) $receipt_sha256 ) );
				$records = self::raw_assets();
				if ( ! isset( $records[ $asset_id ] ) ) return new WP_Error( 'mad4b_brand_rollback_asset_missing', 'Generated Brand Context asset is missing from the registry.' );
				if ( empty( self::asset( $asset_id ) ) ) return new WP_Error( 'mad4b_context_asset_brand_mismatch', 'Asset is outside the current trusted brand scope.' );
				$current = $records[ $asset_id ];
				if ( empty( $current['generated_artifact_id'] ) || ! hash_equals( strtolower( (string) $current['generated_artifact_id'] ), $artifact_id ) ) return new WP_Error( 'mad4b_brand_rollback_artifact_binding_drift', 'Generated Brand Context Artifact binding changed; rollback denied.' );
				if ( empty( $current['materialization_receipt_sha256'] ) || ! hash_equals( strtolower( (string) $current['materialization_receipt_sha256'] ), $receipt_sha256 ) ) return new WP_Error( 'mad4b_brand_rollback_receipt_binding_drift', 'Generated Brand Context receipt binding changed; rollback denied.' );
				$status = isset( $current['status'] ) ? (string) $current['status'] : '';
				if ( 'rollback_pending' === $status ) {
					return array(
						'contract' => 'mad4b.brand-context-rollback-intent.v1',
						'asset_id' => $asset_id,
						'artifact_id' => $artifact_id,
						'receipt_sha256' => $receipt_sha256,
						'started_at' => isset( $current['rollback_started_at'] ) ? (string) $current['rollback_started_at'] : '',
						'idempotent' => true,
					);
				}
				if ( 'ready' !== $status ) return new WP_Error( 'mad4b_brand_rollback_status_drift', 'Generated Brand Context asset is not in a rollback-eligible ready state.', array( 'status' => $status ) );
				$started_at = gmdate( 'c' );
				$records[ $asset_id ]['status'] = 'rollback_pending';
				$records[ $asset_id ]['rollback_started_at'] = $started_at;
				$records[ $asset_id ]['rollback_artifact_id'] = $artifact_id;
				$records[ $asset_id ]['rollback_receipt_sha256'] = $receipt_sha256;
				$sources = self::raw_sources();
				$profile = self::refreshed_profile_record( $records, $sources, true );
				$changes = array( self::ASSETS_OPTION => $records );
				if ( ! empty( $profile ) ) $changes[ self::PROFILE_OPTION ] = $profile;
				$commit = self::commit_option_changes( $changes, 'mad4b_brand_rollback_intent_write_failed', 'Brand Context rollback intent could not be committed atomically.' );
				if ( is_wp_error( $commit ) ) return $commit;
				return array(
					'contract' => 'mad4b.brand-context-rollback-intent.v1',
					'asset_id' => $asset_id,
					'artifact_id' => $artifact_id,
					'receipt_sha256' => $receipt_sha256,
					'started_at' => $started_at,
					'idempotent' => false,
				);
			}
		);
	}

	public static function cancel_generated_brand_rollback( $asset_id, $artifact_id, $receipt_sha256, $reason = '' ) {
		return self::with_registry_lock(
			'cancel_generated_brand_rollback',
			static function () use ( $asset_id, $artifact_id, $receipt_sha256, $reason ) {
				$asset_id = strtolower( trim( sanitize_text_field( (string) $asset_id ) ) );
				$artifact_id = strtolower( trim( sanitize_text_field( (string) $artifact_id ) ) );
				$receipt_sha256 = strtolower( trim( (string) $receipt_sha256 ) );
				$records = self::raw_assets();
				if ( ! isset( $records[ $asset_id ] ) ) return new WP_Error( 'mad4b_brand_rollback_asset_missing', 'Generated Brand Context asset is missing from the registry.' );
				if ( empty( self::asset( $asset_id ) ) ) return new WP_Error( 'mad4b_context_asset_brand_mismatch', 'Asset is outside the current trusted brand scope.' );
				$current = $records[ $asset_id ];
				if ( 'rollback_pending' !== ( isset( $current['status'] ) ? (string) $current['status'] : '' ) ) return new WP_Error( 'mad4b_brand_rollback_cancel_state_drift', 'Generated Brand Context asset is not in rollback_pending state.' );
				if ( empty( $current['rollback_artifact_id'] ) || ! hash_equals( strtolower( (string) $current['rollback_artifact_id'] ), $artifact_id ) ) return new WP_Error( 'mad4b_brand_rollback_cancel_artifact_drift', 'Rollback intent Artifact binding changed.' );
				if ( empty( $current['rollback_receipt_sha256'] ) || ! hash_equals( strtolower( (string) $current['rollback_receipt_sha256'] ), $receipt_sha256 ) ) return new WP_Error( 'mad4b_brand_rollback_cancel_receipt_drift', 'Rollback intent receipt binding changed.' );
				$records[ $asset_id ]['status'] = 'ready';
				$records[ $asset_id ]['availability_reason'] = '';
				$records[ $asset_id ]['rollback_cancel_reason'] = sanitize_key( (string) $reason );
				$records[ $asset_id ]['rollback_cancelled_at'] = gmdate( 'c' );
				unset( $records[ $asset_id ]['rollback_started_at'], $records[ $asset_id ]['rollback_artifact_id'], $records[ $asset_id ]['rollback_receipt_sha256'] );
				$sources = self::raw_sources();
				$profile = self::refreshed_profile_record( $records, $sources, true );
				$changes = array( self::ASSETS_OPTION => $records );
				if ( ! empty( $profile ) ) $changes[ self::PROFILE_OPTION ] = $profile;
				$commit = self::commit_option_changes( $changes, 'mad4b_brand_rollback_cancel_write_failed', 'Brand Context rollback cancellation could not be committed atomically.' );
				return is_wp_error( $commit ) ? $commit : $records[ $asset_id ];
			}
		);
	}

	public static function mark_generated_brand_draft_rolled_back( $asset_id, $file_id, $content_sha256, $artifact_id, $receipt_sha256, $allow_unmarked = false ) {
		return self::with_registry_lock(
			'mark_generated_brand_draft_rolled_back',
			static function () use ( $asset_id, $file_id, $content_sha256, $artifact_id, $receipt_sha256, $allow_unmarked ) {
				$asset_id = strtolower( trim( (string) $asset_id ) );
				$records = self::raw_assets();
				if ( ! isset( $records[ $asset_id ] ) ) return new WP_Error( 'mad4b_brand_rollback_asset_missing', 'Generated Brand Context asset is missing from the registry.' );
				if ( empty( self::asset( $asset_id ) ) ) return new WP_Error( 'mad4b_context_asset_brand_mismatch', 'Asset is outside the current trusted brand scope.' );
				$current = $records[ $asset_id ];
				if ( ! $allow_unmarked ) {
					if ( empty( $current['generated_artifact_id'] ) || ! hash_equals( strtolower( (string) $current['generated_artifact_id'] ), strtolower( (string) $artifact_id ) ) ) return new WP_Error( 'mad4b_brand_rollback_artifact_binding_drift', 'Generated Brand Context Artifact binding changed; rollback denied.' );
					if ( empty( $current['materialization_receipt_sha256'] ) || ! hash_equals( strtolower( (string) $current['materialization_receipt_sha256'] ), strtolower( (string) $receipt_sha256 ) ) ) return new WP_Error( 'mad4b_brand_rollback_receipt_binding_drift', 'Generated Brand Context receipt binding changed; rollback denied.' );
					if ( 'rollback_pending' !== ( isset( $current['status'] ) ? (string) $current['status'] : '' ) ) return new WP_Error( 'mad4b_brand_rollback_intent_missing', 'Generated Brand Context rollback requires a persisted rollback_pending intent.' );
				}
				if ( empty( $current['file_id'] ) || ! hash_equals( (string) $current['file_id'], (string) $file_id ) ) return new WP_Error( 'mad4b_brand_rollback_file_binding_drift', 'Generated Brand Context file binding changed before rollback finalization.' );
				if ( empty( $current['content_hash'] ) || ! hash_equals( strtolower( (string) $current['content_hash'] ), strtolower( (string) $content_sha256 ) ) ) return new WP_Error( 'mad4b_brand_rollback_content_drift', 'Generated Brand Context content changed before rollback finalization.' );
				$records[ $asset_id ]['status'] = 'unavailable';
				$records[ $asset_id ]['availability_reason'] = 'generated_file_rolled_back';
				$records[ $asset_id ]['review_status'] = 'unreviewed';
				$records[ $asset_id ]['reviewed_content_hash'] = '';
				$records[ $asset_id ]['rolled_back_at'] = gmdate( 'c' );
				unset( $records[ $asset_id ]['rollback_started_at'], $records[ $asset_id ]['rollback_artifact_id'], $records[ $asset_id ]['rollback_receipt_sha256'] );
				$sources = self::raw_sources();
				$profile = self::refreshed_profile_record( $records, $sources, true );
				$changes = array( self::ASSETS_OPTION => $records );
				if ( ! empty( $profile ) ) $changes[ self::PROFILE_OPTION ] = $profile;
				$commit = self::commit_option_changes( $changes, 'mad4b_brand_rollback_registry_write_failed', 'Brand Context rollback registry state could not be committed.' );
				return is_wp_error( $commit ) ? $commit : $records[ $asset_id ];
			}
		);
	}

	public static function mark_asset_recreated( $old_asset_id, array $new_asset ){
		return self::with_registry_lock(
			'mark_asset_recreated',
			static function () use ( $old_asset_id, $new_asset ) {
			$old_asset_id = strtolower( trim( sanitize_text_field( (string) $old_asset_id ) ) );
			if ( empty( self::asset( $old_asset_id ) ) ) return new WP_Error( 'mad4b_context_asset_not_found', 'Context asset was not found in the live source-authorized registry view.' );
			$records = self::raw_assets();
			if ( isset( $records[ $old_asset_id ] ) ) {
				$records[ $old_asset_id ]['status'] = 'recreated';
				$records[ $old_asset_id ]['availability_reason'] = 'replacement_created';
				$records[ $old_asset_id ]['replacement_asset_id'] = isset( $new_asset['asset_id'] ) ? (string) $new_asset['asset_id'] : '';
				$records[ $old_asset_id ]['recreated_at'] = gmdate( 'c' );
			}
			if ( ! empty( $new_asset['asset_id'] ) ) {
				$new_asset_id = (string) $new_asset['asset_id'];
				$old_asset = self::asset( $old_asset_id );
				$source_id = isset( $old_asset['source_id'] ) ? (string) $old_asset['source_id'] : '';
				$source = self::source( $source_id );
				$expected_id = ! empty( $new_asset['file_id'] ) ? hash( 'sha256', $source_id . '|' . (string) $new_asset['file_id'] ) : '';
				if ( empty( $source ) || ! self::valid_asset( $new_asset ) || '' === $expected_id || ! hash_equals( $expected_id, $new_asset_id ) ||
					! hash_equals( $source_id, (string) $new_asset['source_id'] ) ||
					! hash_equals( (string) $source['site_uuid'], (string) ( isset( $new_asset['site_uuid'] ) ? $new_asset['site_uuid'] : '' ) ) ||
					! hash_equals( (string) $source['brand_id'], (string) ( isset( $new_asset['brand_id'] ) ? $new_asset['brand_id'] : '' ) ) ) {
					return new WP_Error( 'mad4b_context_recreate_scope_mismatch', 'Replacement asset identity and brand ownership must match the original source.' );
				}
				if ( isset( $records[ $new_asset_id ] ) && empty( self::asset( $new_asset_id ) ) ) return new WP_Error( 'mad4b_context_recreate_brand_collision', 'Replacement asset identity is held outside the current brand.' );
				if ( ! isset( $records[ $new_asset_id ] ) && count( $records ) >= self::MAX_ASSETS ) {
					return new WP_Error(
						'mad4b_context_asset_registry_capacity_limit',
						'Context asset registry is full; recreated lineage cannot add a replacement record safely.',
						array( 'limit' => self::MAX_ASSETS, 'stored_asset_count' => count( $records ) )
					);
				}
				$records[ $new_asset_id ] = $new_asset;
			}
			$sources = self::raw_sources();
			$profile = self::refreshed_profile_record( $records, $sources, true );
			$changes = array( self::ASSETS_OPTION => $records );
			if ( ! empty( $profile ) ) $changes[ self::PROFILE_OPTION ] = $profile;
			$commit = self::commit_option_changes(
				$changes,
				'mad4b_context_recreate_registry_write_failed',
				'Context recreated-asset lineage could not be committed atomically.'
			);
			if ( is_wp_error( $commit ) ) return $commit;
			return isset( $records[ $old_asset_id ] ) ? $records[ $old_asset_id ] : array();

			}
		);
	}

	public static function begin_recreated_asset_rollback( $old_asset_id, $replacement_asset_id ) {
		return self::with_registry_lock(
			'begin_recreated_asset_rollback',
			static function () use ( $old_asset_id, $replacement_asset_id ) {
				$old_asset_id = strtolower( trim( sanitize_text_field( (string) $old_asset_id ) ) );
				$replacement_asset_id = strtolower( trim( sanitize_text_field( (string) $replacement_asset_id ) ) );
				if ( empty( self::asset( $old_asset_id ) ) || empty( self::asset( $replacement_asset_id ) ) ) return new WP_Error( 'mad4b_context_recreate_rollback_registry_missing', 'Recreate rollback requires live source-authorized original and replacement assets.' );
				$records = self::raw_assets();
				if ( ! isset( $records[ $old_asset_id ], $records[ $replacement_asset_id ] ) ) return new WP_Error( 'mad4b_context_recreate_rollback_registry_missing', 'Recreate rollback intent requires both original and replacement registry records.' );
				$original = $records[ $old_asset_id ];
				$replacement = $records[ $replacement_asset_id ];
				if ( 'recreated' !== ( isset( $original['status'] ) ? (string) $original['status'] : '' ) ) return new WP_Error( 'mad4b_context_recreate_rollback_status_drift', 'Original Context asset is no longer in recreated state.' );
				if ( empty( $original['replacement_asset_id'] ) || ! hash_equals( (string) $original['replacement_asset_id'], $replacement_asset_id ) ) return new WP_Error( 'mad4b_context_recreate_rollback_binding_drift', 'Original Context asset no longer points to the recorded replacement.' );
				if ( empty( $replacement['source_id'] ) || empty( $original['source_id'] ) || ! hash_equals( (string) $original['source_id'], (string) $replacement['source_id'] ) ) return new WP_Error( 'mad4b_context_recreate_rollback_source_mismatch', 'Replacement no longer belongs to the original governed source.' );

				$started_at = gmdate( 'c' );
				$records[ $old_asset_id ]['status'] = 'rollback_pending';
				$records[ $old_asset_id ]['rollback_started_at'] = $started_at;
				$records[ $replacement_asset_id ]['status'] = 'rollback_pending';
				$records[ $replacement_asset_id ]['rollback_started_at'] = $started_at;
				$sources = self::raw_sources();
				$profile = self::refreshed_profile_record( $records, $sources, true );
				$changes = array( self::ASSETS_OPTION => $records );
				if ( ! empty( $profile ) ) $changes[ self::PROFILE_OPTION ] = $profile;
				$commit = self::commit_option_changes(
					$changes,
					'mad4b_context_recreate_rollback_intent_write_failed',
					'Recreate rollback intent and Context fingerprint could not be committed atomically.'
				);
				if ( is_wp_error( $commit ) ) return $commit;
				return array(
					'contract' => 'mad4b.context-recreate-rollback-intent.v1',
					'asset_id' => $old_asset_id,
					'replacement_asset_id' => $replacement_asset_id,
					'source_id' => (string) $original['source_id'],
					'started_at' => $started_at,
				);
			}
		);
	}

	public static function cancel_recreated_asset_rollback( $old_asset_id, $replacement_asset_id, $reason = '' ) {
		return self::with_registry_lock(
			'cancel_recreated_asset_rollback',
			static function () use ( $old_asset_id, $replacement_asset_id, $reason ) {
				$old_asset_id = strtolower( trim( sanitize_text_field( (string) $old_asset_id ) ) );
				$replacement_asset_id = strtolower( trim( sanitize_text_field( (string) $replacement_asset_id ) ) );
				if ( empty( self::asset( $old_asset_id ) ) || empty( self::asset( $replacement_asset_id ) ) ) return new WP_Error( 'mad4b_context_recreate_rollback_registry_missing', 'Recreate rollback requires live source-authorized original and replacement assets.' );
				$records = self::raw_assets();
				if ( ! isset( $records[ $old_asset_id ], $records[ $replacement_asset_id ] ) ) return new WP_Error( 'mad4b_context_recreate_rollback_cancel_registry_missing', 'Rollback cancellation requires both original and replacement registry records.' );
				if ( 'rollback_pending' !== ( isset( $records[ $old_asset_id ]['status'] ) ? (string) $records[ $old_asset_id ]['status'] : '' ) ) return new WP_Error( 'mad4b_context_recreate_rollback_cancel_state_drift', 'Original Context asset is not in rollback_pending state.' );
				if ( empty( $records[ $old_asset_id ]['replacement_asset_id'] ) || ! hash_equals( (string) $records[ $old_asset_id ]['replacement_asset_id'], $replacement_asset_id ) ) return new WP_Error( 'mad4b_context_recreate_rollback_cancel_binding_drift', 'Rollback cancellation replacement binding drifted.' );

				$records[ $old_asset_id ]['status'] = 'recreated';
				$records[ $old_asset_id ]['rollback_cancel_reason'] = sanitize_key( (string) $reason );
				unset( $records[ $old_asset_id ]['rollback_started_at'] );
				$records[ $replacement_asset_id ]['status'] = 'ready';
				unset( $records[ $replacement_asset_id ]['rollback_started_at'] );
				$sources = self::raw_sources();
				$profile = self::refreshed_profile_record( $records, $sources, true );
				$changes = array( self::ASSETS_OPTION => $records );
				if ( ! empty( $profile ) ) $changes[ self::PROFILE_OPTION ] = $profile;
				$commit = self::commit_option_changes(
					$changes,
					'mad4b_context_recreate_rollback_cancel_write_failed',
					'Rollback cancellation and Context fingerprint could not be committed atomically.'
				);
				if ( is_wp_error( $commit ) ) return $commit;
				return true;
			}
		);
	}

	public static function rollback_recreated_asset( $old_asset_id, $replacement_asset_id, array $before_state ) {
		return self::with_registry_lock(
			'rollback_recreated_asset',
			static function () use ( $old_asset_id, $replacement_asset_id, $before_state ) {
				$old_asset_id = strtolower( trim( sanitize_text_field( (string) $old_asset_id ) ) );
				$replacement_asset_id = strtolower( trim( sanitize_text_field( (string) $replacement_asset_id ) ) );
				if ( empty( self::asset( $old_asset_id ) ) || empty( self::asset( $replacement_asset_id ) ) ) return new WP_Error( 'mad4b_context_recreate_rollback_registry_missing', 'Recreate rollback requires live source-authorized original and replacement assets.' );
				$records = self::raw_assets();
				if ( ! isset( $records[ $old_asset_id ] ) || ! isset( $records[ $replacement_asset_id ] ) ) return new WP_Error( 'mad4b_context_recreate_rollback_registry_missing', 'Recreate rollback requires both original and replacement registry records.' );
				$current = $records[ $old_asset_id ];
				if ( ! in_array( isset( $current['status'] ) ? (string) $current['status'] : '', array( 'recreated', 'rollback_pending' ), true ) ) return new WP_Error( 'mad4b_context_recreate_rollback_status_drift', 'Original Context asset is no longer in a rollback-compatible recreated state.' );
				if ( empty( $current['replacement_asset_id'] ) || ! hash_equals( (string) $current['replacement_asset_id'], $replacement_asset_id ) ) return new WP_Error( 'mad4b_context_recreate_rollback_binding_drift', 'Original Context asset no longer points to the recorded replacement.' );
				if ( empty( $before_state['asset_id'] ) || ! hash_equals( (string) $before_state['asset_id'], $old_asset_id ) ) return new WP_Error( 'mad4b_context_recreate_rollback_before_mismatch', 'Rollback state is not bound to the original Context asset.' );
				if ( empty( $before_state['source_id'] ) || ! hash_equals( (string) $before_state['source_id'], (string) $current['source_id'] ) ) return new WP_Error( 'mad4b_context_recreate_rollback_source_mismatch', 'Rollback state is not bound to the original Context source.' );

				unset( $records[ $replacement_asset_id ] );
				$records[ $old_asset_id ]['status'] = isset( $before_state['status'] ) ? sanitize_key( (string) $before_state['status'] ) : 'unavailable';
				$records[ $old_asset_id ]['availability_reason'] = isset( $before_state['availability_reason'] ) ? sanitize_key( (string) $before_state['availability_reason'] ) : 'not_seen_in_complete_scan';
				if ( isset( $before_state['absence_scan_generation'] ) ) $records[ $old_asset_id ]['absence_scan_generation'] = (string) $before_state['absence_scan_generation'];
				unset( $records[ $old_asset_id ]['replacement_asset_id'], $records[ $old_asset_id ]['recreated_at'], $records[ $old_asset_id ]['rollback_started_at'] );
				$sources = self::raw_sources();
				$profile = self::refreshed_profile_record( $records, $sources, true );
				$changes = array( self::ASSETS_OPTION => $records );
				if ( ! empty( $profile ) ) $changes[ self::PROFILE_OPTION ] = $profile;
				$commit = self::commit_option_changes(
					$changes,
					'mad4b_context_recreate_rollback_registry_write_failed',
					'Replacement file was removed but Context registry rollback could not be finalized atomically.'
				);
				if ( is_wp_error( $commit ) ) return $commit;
				return self::audited_registry_result(
					$records[ $old_asset_id ],
					'mad4b/context-recreate-rollback',
					array(
						'asset_id' => $old_asset_id,
						'replacement_asset_id' => $replacement_asset_id,
						'source_id' => (string) $before_state['source_id'],
						'restored_status' => (string) $records[ $old_asset_id ]['status'],
					),
					'ok'
				);
			}
		);
	}

	private static function refresh_profile_fingerprint( array $assets, array $sources ) {
		$profile = self::refreshed_profile_record( $assets, $sources, true );
		if ( empty( $profile ) ) return true;
		return self::write_option( self::PROFILE_OPTION, $profile )
			? true
			: new WP_Error( 'mad4b_context_profile_fingerprint_write_failed', 'Context Profile fingerprint could not be persisted.' );
	}

	public static function update_source_write_policy( $source_id, $write_policy, $confirmed = false ){
		return self::with_registry_lock(
			'update_source_write_policy',
			static function () use ( $source_id, $write_policy, $confirmed ) {
			$audit_ready = self::audit_preflight();
			if ( is_wp_error( $audit_ready ) ) return $audit_ready;
			$site = self::site_binding();
			if ( is_wp_error( $site ) ) return $site;
			$source_id = strtolower( trim( sanitize_text_field( (string) $source_id ) ) );
			$write_policy = sanitize_key( (string) $write_policy );
			$authorized_sources = self::sources();
			if ( ! isset( $authorized_sources[ $source_id ] ) ) return new WP_Error( 'mad4b_context_source_not_found', 'Context source was not found.' );
			$source = $authorized_sources[ $source_id ];
			$sources = self::raw_sources();
			if ( ! hash_equals( (string) $site['site_uuid'], (string) $source['site_uuid'] ) ) return new WP_Error( 'mad4b_context_source_site_mismatch', 'Context source is not bound to this Site Profile.' );
			if ( ! isset( self::write_policies()[ $write_policy ] ) ) return new WP_Error( 'mad4b_context_source_write_policy_invalid', 'Context source write policy is invalid.' );
			if ( 'task_attachment' === ( isset( $source['mode'] ) ? (string) $source['mode'] : '' ) && 'read_only' !== $write_policy ) return new WP_Error( 'mad4b_context_task_source_write_forbidden', 'Task-only Context sources are read-only in this release.' );
			$previous = isset( $source['write_policy'] ) ? (string) $source['write_policy'] : 'read_only';
			$transition = self::assert_write_policy_transition( $previous, $write_policy, (bool) $confirmed );
			if ( is_wp_error( $transition ) ) return $transition;
			$sources[ $source_id ]['write_policy'] = $write_policy;
			$sources[ $source_id ]['updated_at'] = gmdate( 'c' );
			if ( ! self::write_option( self::SOURCES_OPTION, $sources ) ) return new WP_Error( 'mad4b_context_source_policy_write_failed', 'Context source write policy could not be persisted.' );
			return self::audited_registry_result(
				$sources[ $source_id ],
				'mad4b/context-source-write-policy',
				array(
					'source_id' => $source_id,
					'provider' => isset( $source['provider'] ) ? (string) $source['provider'] : '',
					'mode' => isset( $source['mode'] ) ? (string) $source['mode'] : '',
					'previous_write_policy' => $previous,
					'write_policy' => $write_policy,
					'write_policy_escalated' => self::write_policy_escalation_requires_confirmation( $previous, $write_policy ),
					'write_policy_confirmed' => (bool) $confirmed,
				),
				'ok'
			);

			}
		);
	}

	public static function remove_source( $source_id ){
		return self::with_registry_lock(
			'remove_source',
			static function () use ( $source_id ) {
			$audit_ready = self::audit_preflight();
			if ( is_wp_error( $audit_ready ) ) return $audit_ready;
			$site = self::site_binding();
			if ( is_wp_error( $site ) ) return $site;
			$source_id = strtolower( trim( sanitize_text_field( (string) $source_id ) ) );
			$authorized_sources = self::sources();
			if ( ! isset( $authorized_sources[ $source_id ] ) ) return new WP_Error( 'mad4b_context_source_not_found', 'Context source was not found.' );
			$source = $authorized_sources[ $source_id ];
			$sources = self::raw_sources();
			if ( ! hash_equals( (string) $site['site_uuid'], (string) $source['site_uuid'] ) ) return new WP_Error( 'mad4b_context_source_site_mismatch', 'Context source is not bound to this Site Profile.' );
			$assets = self::raw_assets();
			$removed_assets = 0;
			// Refuse destructive deletion when historical asset ownership is not
			// exact. Preserve the complete registry for a separate review/migration.
			foreach ( $assets as $asset ) {
				if ( ! isset( $asset['source_id'] ) || ! hash_equals( $source_id, (string) $asset['source_id'] ) ) continue;
				$asset_site = isset( $asset['site_uuid'] ) ? strtolower( trim( (string) $asset['site_uuid'] ) ) : '';
				$asset_brand = isset( $asset['brand_id'] ) ? strtolower( trim( (string) $asset['brand_id'] ) ) : '';
				if ( '' === $asset_site || ! hash_equals( strtolower( (string) $source['site_uuid'] ), $asset_site ) ||
					'' === $asset_brand || ! hash_equals( strtolower( (string) $source['brand_id'] ), $asset_brand ) ) {
					return new WP_Error( 'mad4b_context_remove_foreign_asset_quarantined', 'Source removal blocked: an asset has unverified or foreign brand ownership. Inspect lineage before a governed migration.' );
				}
			}
			foreach ( $assets as $asset_id => $asset ) {
				if ( isset( $asset['source_id'] ) && hash_equals( $source_id, (string) $asset['source_id'] ) ) {
					unset( $assets[ $asset_id ] );
					++$removed_assets;
				}
			}
			unset( $sources[ $source_id ] );
			$profile = self::refreshed_profile_record( $assets, $sources, true );
			$changes = array(
				self::ASSETS_OPTION => $assets,
				self::SOURCES_OPTION => $sources,
			);
			if ( ! empty( $profile ) ) $changes[ self::PROFILE_OPTION ] = $profile;
			$commit = self::commit_option_changes(
				$changes,
				'mad4b_context_source_remove_commit_failed',
				'Context source removal could not be committed atomically.'
			);
			if ( is_wp_error( $commit ) ) return $commit;
			return self::audited_registry_result(
				array(
					'source_id' => $source_id,
					'removed_asset_count' => $removed_assets,
					'context_fingerprint' => self::context_fingerprint( $assets, $sources ),
				),
				'mad4b/context-source-remove',
				array(
					'source_id' => $source_id,
					'provider' => isset( $source['provider'] ) ? (string) $source['provider'] : '',
					'mode' => isset( $source['mode'] ) ? (string) $source['mode'] : '',
					'removed_asset_count' => $removed_assets,
				),
				'ok'
			);

			}
		);
	}

	public static function review_asset( $asset_id, array $input ) {
		return self::review_asset_with_actor(
			$asset_id,
			$input,
			array(
				'actor_type' => 'wp_admin',
				'wp_user_id' => get_current_user_id(),
				'agent_public_id' => '',
				'subject_fingerprint' => '',
				'issuer_fingerprint' => '',
				'client_fingerprint' => '',
				'session_fingerprint' => '',
				'identity_method' => 'wp_admin_session',
			)
		);
	}

	public static function review_asset_by_agent( $input ) {
		$input = is_array( $input ) ? $input : array();
		foreach ( array( 'category', 'authority_class', 'required', 'required_scope_confirmed', 'quality_mode', 'quality_score' ) as $forbidden_key ) {
			if ( array_key_exists( $forbidden_key, $input ) ) return new WP_Error( 'mad4b_context_ai_review_governance_mutation_forbidden', 'AI Agent review cannot change Context category, authority class, required scope, or quality policy.' );
		}
		$policy = self::review_policy();
		if ( 'human_and_ai' !== (string) $policy['mode'] ) return new WP_Error( 'mad4b_context_ai_review_mode_disabled', 'AI Agent review is disabled. Human Review remains available.' );
		if ( 'staging' !== self::site_profile_environment() ) return new WP_Error( 'mad4b_context_ai_review_staging_only', 'AI Agent review is Staging-only in rc.54.' );
		if ( ! class_exists( 'MAD4B_SCP_Identity_Context' ) || ! class_exists( 'MAD4B_SCP_Agent_Registry' ) ) return new WP_Error( 'mad4b_context_ai_review_identity_unavailable', 'AI review identity authority is unavailable.' );
		$identity = MAD4B_SCP_Identity_Context::current();
		if ( is_wp_error( $identity ) || empty( $identity['authenticated'] ) || 'oauth2_bearer' !== ( isset( $identity['auth_method'] ) ? (string) $identity['auth_method'] : '' ) ) return new WP_Error( 'mad4b_context_ai_review_oauth_required', 'AI Agent review requires an authenticated OAuth2 bearer identity.' );
		$agent = MAD4B_SCP_Agent_Registry::resolve_agent( $identity );
		if ( is_wp_error( $agent ) ) return $agent;
		$configured_agent = isset( $policy['ai_agent_public_id'] ) ? (string) $policy['ai_agent_public_id'] : '';
		if ( empty( $agent['public_id'] ) || '' === $configured_agent || ! hash_equals( $configured_agent, (string) $agent['public_id'] ) ) return new WP_Error( 'mad4b_context_ai_review_agent_mismatch', 'Authenticated AI Agent does not match the Context review delegation.' );
		if ( 'enabled' !== ( isset( $agent['status'] ) ? (string) $agent['status'] : '' ) || 'staging' !== ( isset( $agent['environment'] ) ? (string) $agent['environment'] : '' ) ) return new WP_Error( 'mad4b_context_ai_review_agent_ineligible', 'AI review requires the configured enabled Staging agent.' );
		$profile_agent_slug = self::site_profile_agent_slug();
		if ( '' === $profile_agent_slug || $profile_agent_slug !== sanitize_key( isset( $agent['slug'] ) ? (string) $agent['slug'] : '' ) ) return new WP_Error( 'mad4b_context_ai_review_agent_not_profile_owned', 'AI review requires the canonical Site Profile governed-write agent.' );
		$grant = MAD4B_SCP_Agent_Registry::exact_grant( (int) $agent['id'], 'mad4b-write', self::AI_REVIEW_ABILITY, 'core' );
		if ( ! is_array( $grant ) || 'allow' !== ( isset( $grant['effect'] ) ? (string) $grant['effect'] : '' ) || 'staging' !== ( isset( $grant['environment'] ) ? (string) $grant['environment'] : '' ) ) return new WP_Error( 'mad4b_context_ai_review_exact_grant_missing', 'Configured AI Agent does not have the exact Staging grant for Context AI review.' );
		$asset_id = isset( $input['asset_id'] ) ? strtolower( trim( sanitize_text_field( (string) $input['asset_id'] ) ) ) : '';
		$asset = self::asset( $asset_id );
		if ( empty( $asset ) ) return new WP_Error( 'mad4b_context_asset_not_found', 'Context asset was not found in the live source-authorized registry view.' );
		$review_input = array(
			'category' => isset( $asset['category'] ) ? (string) $asset['category'] : '',
			'authority_class' => isset( $asset['authority_class'] ) ? (string) $asset['authority_class'] : '',
			'required' => ! empty( $asset['required'] ),
			'required_scope_confirmed' => false,
			'quality_mode' => 'preserve',
			'quality_score' => '',
			'decision' => isset( $input['decision'] ) ? (string) $input['decision'] : '',
			'review_note' => isset( $input['review_note'] ) ? (string) $input['review_note'] : '',
			'expected_content_hash' => isset( $input['expected_content_hash'] ) ? (string) $input['expected_content_hash'] : '',
			'expected_registry_revision' => isset( $input['expected_registry_revision'] ) ? (int) $input['expected_registry_revision'] : -1,
			'expected_authority_manifest_fingerprint' => isset( $input['expected_authority_manifest_fingerprint'] ) ? (string) $input['expected_authority_manifest_fingerprint'] : '',
		);
		$actor = array(
			'actor_type' => 'ai_agent',
			'wp_user_id' => 0,
			'agent_public_id' => (string) $agent['public_id'],
			'subject_fingerprint' => isset( $identity['subject_fingerprint'] ) ? (string) $identity['subject_fingerprint'] : '',
			'issuer_fingerprint' => isset( $identity['issuer_fingerprint'] ) ? (string) $identity['issuer_fingerprint'] : '',
			'client_fingerprint' => isset( $identity['client_fingerprint'] ) ? (string) $identity['client_fingerprint'] : '',
			'session_fingerprint' => isset( $identity['session_fingerprint'] ) ? (string) $identity['session_fingerprint'] : '',
			'identity_method' => isset( $identity['auth_method'] ) ? (string) $identity['auth_method'] : 'oauth2_bearer',
		);
		return self::review_asset_with_actor( $asset_id, $review_input, $actor );
	}

	private static function review_asset_with_actor( $asset_id, array $input, array $actor ) {
		$operation = 'ai_agent' === ( isset( $actor['actor_type'] ) ? (string) $actor['actor_type'] : '' ) ? 'review_asset_ai' : 'review_asset';
		return self::with_registry_lock(
			$operation,
			static function () use ( $asset_id, $input, $actor ) {
			$audit_ready = self::audit_preflight();
			if ( is_wp_error( $audit_ready ) ) return $audit_ready;
			$site = self::site_binding();
			if ( is_wp_error( $site ) ) return $site;
			$asset_id = strtolower( trim( sanitize_text_field( (string) $asset_id ) ) );
			$visible_asset = self::asset( $asset_id );
			if ( empty( $visible_asset ) ) return new WP_Error( 'mad4b_context_asset_not_found', 'Context asset was not found in the live source-authorized registry view.' );
			$records = self::raw_assets();
			if ( ! isset( $records[ $asset_id ] ) ) return new WP_Error( 'mad4b_context_asset_not_found', 'Context asset raw registry record was not found.' );
			$asset = $records[ $asset_id ];
			if ( ! hash_equals( (string) $site['site_uuid'], (string) $asset['site_uuid'] ) ) return new WP_Error( 'mad4b_context_asset_site_mismatch', 'Context asset is not bound to this Site Profile.' );
			$source_mode = isset( $asset['source_mode'] ) ? sanitize_key( (string) $asset['source_mode'] ) : '';
			if ( 'governed' !== $source_mode ) {
				return new WP_Error(
					'mad4b_context_review_source_mode_forbidden',
					'Context Review is only available for governed site-bound Context assets. Task attachments remain task-local and cannot become Brand Authority.',
					array( 'asset_id' => $asset_id, 'source_mode' => $source_mode )
				);
			}
			$normalization_status = isset( $asset['normalization_status'] ) ? sanitize_key( (string) $asset['normalization_status'] ) : ( ! empty( $asset['content_complete'] ) ? 'ready' : 'incomplete' );
			if ( empty( $asset['content_complete'] ) || ! in_array( $normalization_status, array( 'ready', 'reused' ), true ) ) {
				return new WP_Error(
					'mad4b_context_review_content_incomplete',
					'Context Review requires complete normalized source content. Repair or rescan the source before reviewing this asset.',
					array( 'asset_id' => $asset_id, 'normalization_status' => $normalization_status )
				);
			}
			$classification_confidence = isset( $asset['classification_confidence'] ) ? (float) $asset['classification_confidence'] : 0.0;
			$classification_source = isset( $asset['classification_source'] ) ? sanitize_key( (string) $asset['classification_source'] ) : '';
			$low_confidence_classification = $classification_confidence < 0.60 && 'human' !== $classification_source;
			if ( $low_confidence_classification ) {
				$actor_type = isset( $actor['actor_type'] ) ? sanitize_key( (string) $actor['actor_type'] ) : '';
				if ( 'ai_agent' === $actor_type ) {
					return new WP_Error(
						'mad4b_context_ai_review_classification_confirmation_required',
						'Delegated AI review cannot approve a low-confidence automatic classification. Human classification confirmation is required first.',
						array( 'asset_id' => $asset_id, 'classification_confidence' => $classification_confidence )
					);
				}
				if ( 'wp_admin' === $actor_type && empty( $input['classification_confirmed'] ) ) {
					return new WP_Error(
						'mad4b_context_review_classification_confirmation_required',
						'Low-confidence Context classification requires explicit human confirmation before the content decision can be committed.',
						array( 'asset_id' => $asset_id, 'classification_confidence' => $classification_confidence )
					);
				}
			}

			$registry_revision_before = self::registry_revision();
			$authority_manifest_before = self::authority_manifest_fingerprint( $records );
			$context_fingerprint_before = self::context_fingerprint( $records, self::raw_sources() );
			$expected_content_hash = strtolower( trim( isset( $input['expected_content_hash'] ) ? (string) $input['expected_content_hash'] : '' ) );
			$expected_registry_revision = isset( $input['expected_registry_revision'] ) ? (int) $input['expected_registry_revision'] : -1;
			$expected_authority_manifest = strtolower( trim( isset( $input['expected_authority_manifest_fingerprint'] ) ? (string) $input['expected_authority_manifest_fingerprint'] : '' ) );
			$current_content_hash = strtolower( trim( isset( $asset['content_hash'] ) ? (string) $asset['content_hash'] : '' ) );
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_content_hash ) || ! preg_match( '/^[a-f0-9]{64}$/', $current_content_hash ) || ! hash_equals( $current_content_hash, $expected_content_hash ) ) return new WP_Error( 'mad4b_context_review_content_drift', 'Context asset content changed after the review evidence was rendered. Reload the current asset before approving it.', array( 'asset_id' => $asset_id, 'expected_content_hash' => $expected_content_hash, 'current_content_hash' => $current_content_hash ) );
			if ( $expected_registry_revision < 0 || $expected_registry_revision !== $registry_revision_before ) return new WP_Error( 'mad4b_context_review_registry_drift', 'Context registry changed after the review evidence was rendered. Reload the current review state.', array( 'expected_registry_revision' => $expected_registry_revision, 'current_registry_revision' => $registry_revision_before ) );
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_authority_manifest ) || ! hash_equals( $authority_manifest_before, $expected_authority_manifest ) ) return new WP_Error( 'mad4b_context_review_authority_drift', 'Context authority changed after the review evidence was rendered. Reload the current review state.', array( 'expected_authority_manifest_fingerprint' => $expected_authority_manifest, 'current_authority_manifest_fingerprint' => $authority_manifest_before ) );

			$categories = self::categories();
			$authorities = self::authority_classes();
			$category = sanitize_key( isset( $input['category'] ) ? $input['category'] : '' );
			$authority = sanitize_key( isset( $input['authority_class'] ) ? $input['authority_class'] : '' );
			$previous_category = isset( $asset['category'] ) ? (string) $asset['category'] : '';
			$previous_authority = isset( $asset['authority_class'] ) ? (string) $asset['authority_class'] : '';
			$previous_classification_source = isset( $asset['classification_source'] ) ? (string) $asset['classification_source'] : '';
			$previous_required = ! empty( $asset['required'] );
			$previous_review_status = isset( $asset['review_status'] ) ? (string) $asset['review_status'] : 'unreviewed';
			$decision = sanitize_key( isset( $input['decision'] ) ? (string) $input['decision'] : 'approve' );
			if ( ! in_array( $decision, array( 'approve', 'needs_changes', 'reject' ), true ) ) return new WP_Error( 'mad4b_context_review_decision_invalid', 'Context review decision must be approve, needs_changes, or reject.' );
			// Operational documents that quote Brand Core terminology are not
			// sufficient evidence of owner-issued strategy, voice or editorial
			// authority. Automatically classified references need explicit
			// human classification before delegated AI can review them.
			if ( 'approve' === $decision
				&& in_array( $category, array( 'brand_strategy', 'tone_of_voice', 'editorial_guidelines' ), true )
				&& 'brand_authority' === $authority
				&& 'automatic_heuristic' === $previous_classification_source ) {
				$title = (string) ( isset( $asset['title'] ) ? $asset['title'] : '' );
				$operational = 1 === preg_match( '/\\b(wordpress|wp-json|connector|mcp|configuration|snapshot|workflow|import|export|api|operational|operations|publish preparation|data store|database)\\b/i', $title );
				if ( $operational ) {
					$actor_type = isset( $actor['actor_type'] ) ? sanitize_key( (string) $actor['actor_type'] ) : '';
					if ( 'ai_agent' === $actor_type ) return new WP_Error(
						'brand_strategy' === $category ? 'mad4b_context_ai_brand_strategy_source_human_review_required' : 'mad4b_context_ai_brand_core_operational_human_review_required',
						'Operational source cannot become Brand Core Authority from delegated AI review alone. Human classification is required.',
						array( 'asset_id' => $asset_id, 'category' => $category )
					);
					if ( 'wp_admin' === $actor_type && empty( $input['classification_confirmed'] ) ) return new WP_Error(
						'brand_strategy' === $category ? 'mad4b_context_brand_strategy_source_confirmation_required' : 'mad4b_context_brand_core_operational_confirmation_required',
						'Confirm operational source is genuinely authoritative before approving it as Brand Core.',
						array( 'asset_id' => $asset_id, 'category' => $category )
					);
				}
			}
			$generated_brand_asset = ! empty( $asset['generated_artifact_id'] ) || 'brand_context_builder' === $previous_classification_source;
			$generation_freshness = null;
			$stale_override = false;
			if ( $generated_brand_asset && 'approve' === $decision ) {
				if ( ! class_exists( 'MAD4B_SCP_Brand_Context_Builder' ) ) return new WP_Error( 'mad4b_brand_generation_freshness_runtime_unavailable', 'Generated Brand Context review requires the Brand Context Builder freshness verifier.' );
				$generation_freshness = MAD4B_SCP_Brand_Context_Builder::generation_evidence_status(
					$category,
					isset( $asset['generation_evidence_digest'] ) ? (string) $asset['generation_evidence_digest'] : '',
					! empty( $asset['generation_include_rendered_frontend'] )
				);
				if ( is_wp_error( $generation_freshness ) ) return $generation_freshness;
				if ( empty( $generation_freshness['fresh'] ) ) {
					$stale_override = 'wp_admin' === ( isset( $actor['actor_type'] ) ? (string) $actor['actor_type'] : '' ) && ! empty( $input['approve_stale_generation_evidence'] );
					if ( ! $stale_override ) return new WP_Error(
						'mad4b_brand_generation_evidence_stale',
						'Generated Brand Context evidence changed after generation. Regenerate before approval, or use the explicit human-only stale-evidence override.',
						array( 'asset_id' => $asset_id, 'generation_evidence' => $generation_freshness, 'ai_override_allowed' => false )
					);
				}
			}
			$review_note = substr( trim( sanitize_text_field( isset( $input['review_note'] ) ? (string) $input['review_note'] : '' ) ), 0, 1000 );
			if ( ! isset( $categories[ $category ] ) ) return new WP_Error( 'mad4b_context_category_invalid', 'Context category is invalid.' );
			if ( ! isset( $authorities[ $authority ] ) ) return new WP_Error( 'mad4b_context_authority_class_invalid', 'Context authority class is invalid.' );

			$requested_required = ! empty( $input['required'] );
			$required_scope_escalated = ! $previous_required && $requested_required;
			$required_scope_shifted = $previous_required && $requested_required && ! hash_equals( $previous_category, $category );
			$required_scope_reduced = $previous_required && ! $requested_required;
			$required_scope_changed = $required_scope_escalated || $required_scope_shifted || $required_scope_reduced;
			if ( $required_scope_changed && empty( $input['required_scope_confirmed'] ) ) return new WP_Error(
				'mad4b_context_required_scope_confirmation_required',
				'Changing required Context scope changes site-wide Brand Context requirements and requires explicit confirmation.',
				array(
					'previous_category' => $previous_category,
					'requested_category' => $category,
					'previous_required' => $previous_required,
					'requested_required' => $requested_required,
					'required_scope_escalated' => $required_scope_escalated,
					'required_scope_shifted' => $required_scope_shifted,
					'required_scope_reduced' => $required_scope_reduced,
				)
			);
			$governance_changed = ! hash_equals( $previous_category, $category ) || ! hash_equals( $previous_authority, $authority ) || $previous_required !== $requested_required;
			$asset['category'] = $category;
			if ( 'wp_admin' === ( isset( $actor['actor_type'] ) ? (string) $actor['actor_type'] : '' ) ) {
				$asset['classification_confidence'] = 1.0;
				if ( ! $generated_brand_asset ) $asset['classification_source'] = 'human';
				$asset['review_classification_source'] = 'human';
			}
			$asset['authority_class'] = $authority;
			$asset['required'] = $requested_required;
			$asset['priority'] = 'brand_authority' === $authority || 'policy_authority' === $authority ? 100 : ( 'task_knowledge' === $authority ? 70 : 40 );
			$quality_input = isset( $input['quality_score'] ) ? trim( (string) $input['quality_score'] ) : '';
			$current_quality = isset( $asset['quality'] ) && is_array( $asset['quality'] ) ? $asset['quality'] : array( 'contract' => self::QUALITY_CONTRACT );
			$quality_mode = sanitize_key(
				isset( $input['quality_mode'] )
					? (string) $input['quality_mode']
					: ( '' !== $quality_input ? 'manual' : ( ! empty( $current_quality['human_override'] ) ? 'manual' : 'automatic' ) )
			);
			$quality_modes = 'ai_agent' === ( isset( $actor['actor_type'] ) ? (string) $actor['actor_type'] : '' ) ? array( 'preserve' ) : array( 'preserve', 'automatic', 'manual' );
			if ( ! in_array( $quality_mode, $quality_modes, true ) ) return new WP_Error( 'mad4b_context_quality_mode_invalid', 'Quality mode is invalid for this review actor.' );
			if ( ( 'approve' !== $decision || $governance_changed || 'manual' === $quality_mode ) && '' === $review_note ) return new WP_Error( 'mad4b_context_review_note_required', 'A review note is required for rejection, requested changes, classification/authority changes, required-scope changes, or manual quality overrides.' );
			$automatic = isset( $asset['quality_auto_score'] ) ? (int) $asset['quality_auto_score'] : ( isset( $current_quality['automatic_score'] ) ? (int) $current_quality['automatic_score'] : null );

			if ( 'preserve' === $quality_mode ) {
				// Delegated AI review is a decision surface only. Existing quality evidence is immutable here.
			} elseif ( 'automatic' === $quality_mode ) {
				if ( null === $automatic ) return new WP_Error( 'mad4b_context_automatic_quality_unavailable', 'Automatic quality score is unavailable for this asset. Rescan the source before resetting the review score.' );
				$automatic_mode = isset( $current_quality['automatic_mode'] ) && '' !== (string) $current_quality['automatic_mode']
					? sanitize_key( (string) $current_quality['automatic_mode'] )
					: ( isset( $current_quality['mode'] ) && 'human_override' !== (string) $current_quality['mode']
						? sanitize_key( (string) $current_quality['mode'] )
						: ( ! empty( $asset['content_available'] ) ? 'content_heuristic_v2' : 'metadata_provisional' ) );
				$automatic_provisional = array_key_exists( 'automatic_provisional', $current_quality )
					? (bool) $current_quality['automatic_provisional']
					: ( 'metadata_provisional' === $automatic_mode );
				$asset['quality_score'] = $automatic;
				$current_quality['overall_score'] = $automatic;
				$current_quality['automatic_score'] = $automatic;
				$current_quality['mode'] = $automatic_mode;
				$current_quality['provisional'] = $automatic_provisional;
				unset( $current_quality['human_override'], $current_quality['automatic_mode'], $current_quality['automatic_provisional'] );
				$asset['quality'] = $current_quality;
			} else {
				if ( '' === $quality_input ) return new WP_Error( 'mad4b_context_quality_score_required', 'Manual quality mode requires a score between 0 and 100.' );
				if ( ! preg_match( '/^\d{1,3}$/', $quality_input ) || (int) $quality_input < 0 || (int) $quality_input > 100 ) return new WP_Error( 'mad4b_context_quality_score_invalid', 'Quality score must be between 0 and 100.' );
				if ( null === $automatic ) $automatic = isset( $asset['quality_score'] ) ? (int) $asset['quality_score'] : null;
				if ( null === $automatic ) return new WP_Error( 'mad4b_context_automatic_quality_unavailable', 'Automatic quality score is unavailable for this asset. Rescan the source before applying a manual override.' );
				if ( empty( $current_quality['human_override'] ) ) {
					$current_quality['automatic_mode'] = isset( $current_quality['mode'] ) && '' !== (string) $current_quality['mode']
						? sanitize_key( (string) $current_quality['mode'] )
						: ( ! empty( $asset['content_available'] ) ? 'content_heuristic_v2' : 'metadata_provisional' );
					$current_quality['automatic_provisional'] = ! empty( $current_quality['provisional'] );
				}
				$asset['quality_score'] = (int) $quality_input;
				$current_quality['automatic_score'] = $automatic;
				$current_quality['overall_score'] = (int) $quality_input;
				$current_quality['mode'] = 'human_override';
				$current_quality['human_override'] = true;
				$current_quality['provisional'] = false;
				$asset['quality'] = $current_quality;
			}
			$asset['reviewed_by'] = isset( $actor['wp_user_id'] ) ? absint( $actor['wp_user_id'] ) : 0;
			$asset['review_actor_type'] = isset( $actor['actor_type'] ) ? sanitize_key( (string) $actor['actor_type'] ) : 'unknown';
			$asset['review_agent_public_id'] = isset( $actor['agent_public_id'] ) ? strtolower( trim( (string) $actor['agent_public_id'] ) ) : '';
			$asset['reviewed_at'] = gmdate( 'c' );
			$asset['review_decision'] = $decision;
			$asset['review_note'] = $review_note;
			$asset['review_status'] = 'approve' === $decision ? 'approved' : ( 'needs_changes' === $decision ? 'needs_changes' : 'rejected' );
			$asset['reviewed_content_hash'] = $current_content_hash;
			if ( $generated_brand_asset ) {
				$asset['generation_evidence_fresh_at_review'] = is_array( $generation_freshness ) ? ! empty( $generation_freshness['fresh'] ) : false;
				$asset['generation_evidence_stale_override'] = $stale_override;
				$asset['generation_evidence_reviewed_current_digest'] = is_array( $generation_freshness ) && isset( $generation_freshness['current_evidence_digest'] ) ? (string) $generation_freshness['current_evidence_digest'] : '';
				$asset['generation_evidence_stale_override_actor'] = $stale_override ? ( isset( $actor['actor_id'] ) ? (string) $actor['actor_id'] : ( isset( $actor['actor_login'] ) ? (string) $actor['actor_login'] : 'wp_admin' ) ) : '';
			}
			$records[ $asset_id ] = $asset;
			$sources = self::raw_sources();
			$profile = self::refreshed_profile_record( $records, $sources, true );
			$changes = array( self::ASSETS_OPTION => $records );
			if ( ! empty( $profile ) ) $changes[ self::PROFILE_OPTION ] = $profile;
			$commit = self::commit_option_changes(
				$changes,
				'mad4b_context_asset_review_commit_failed',
				'Context asset review and authority fingerprint could not be committed atomically.'
			);
			if ( is_wp_error( $commit ) ) return $commit;
			$authority_manifest_after = self::authority_manifest_fingerprint( $records );
			$context_fingerprint_after = self::context_fingerprint( $records, $sources );
			$review_event = 'ai_agent' === ( isset( $actor['actor_type'] ) ? (string) $actor['actor_type'] : '' ) ? 'mad4b/context-asset-ai-review' : 'mad4b/context-asset-review';
			$review_contract = 'ai_agent' === ( isset( $actor['actor_type'] ) ? (string) $actor['actor_type'] : '' ) ? self::AI_REVIEW_CONTRACT : self::HUMAN_REVIEW_CONTRACT;
			$public_asset = $asset;
			$public_asset['review_binding'] = array(
				'registry_revision_before' => $registry_revision_before,
				'registry_revision_after' => $registry_revision_before + 1,
				'authority_manifest_before' => $authority_manifest_before,
				'authority_manifest_after' => $authority_manifest_after,
				'content_hash' => $current_content_hash,
			);
			$result = self::audited_registry_result(
				$public_asset,
				$review_event,
				array(
					'contract' => $review_contract,
					'decision_id' => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : hash( 'sha256', $asset_id . '|' . microtime( true ) ),
					'asset_id' => $asset_id,
					'source_id' => isset( $asset['source_id'] ) ? (string) $asset['source_id'] : '',
					'expected_content_hash' => $expected_content_hash,
					'observed_content_hash' => $current_content_hash,
					'registry_revision_before' => $registry_revision_before,
					'registry_revision_after' => $registry_revision_before + 1,
					'authority_manifest_before' => $authority_manifest_before,
					'authority_manifest_after' => $authority_manifest_after,
					'context_fingerprint_before' => $context_fingerprint_before,
					'context_fingerprint_after' => $context_fingerprint_after,
					'previous_review_status' => $previous_review_status,
					'review_status' => (string) $asset['review_status'],
					'decision' => $decision,
					'review_note' => $review_note,
					'actor_type' => isset( $actor['actor_type'] ) ? (string) $actor['actor_type'] : 'unknown',
					'wp_user_id' => isset( $actor['wp_user_id'] ) ? absint( $actor['wp_user_id'] ) : 0,
					'agent_public_id' => isset( $actor['agent_public_id'] ) ? (string) $actor['agent_public_id'] : '',
					'subject_fingerprint' => isset( $actor['subject_fingerprint'] ) ? (string) $actor['subject_fingerprint'] : '',
					'issuer_fingerprint' => isset( $actor['issuer_fingerprint'] ) ? (string) $actor['issuer_fingerprint'] : '',
					'client_fingerprint' => isset( $actor['client_fingerprint'] ) ? (string) $actor['client_fingerprint'] : '',
					'session_fingerprint' => isset( $actor['session_fingerprint'] ) ? (string) $actor['session_fingerprint'] : '',
					'identity_method' => isset( $actor['identity_method'] ) ? (string) $actor['identity_method'] : '',
					'previous_category' => $previous_category,
					'category' => $category,
					'automatic_classification' => isset( $asset['automatic_classification'] ) && is_array( $asset['automatic_classification'] ) ? $asset['automatic_classification'] : array(),
					'previous_classification_source' => $previous_classification_source,
					'previous_classification_confidence' => $classification_confidence,
					'low_confidence_classification' => $low_confidence_classification,
					'classification_confirmed' => ! empty( $input['classification_confirmed'] ),
					'previous_authority_class' => $previous_authority,
					'authority_class' => $authority,
					'previous_required' => $previous_required,
					'required' => ! empty( $asset['required'] ),
					'required_scope_changed' => $required_scope_changed,
					'required_scope_escalated' => $required_scope_escalated,
					'required_scope_shifted' => $required_scope_shifted,
					'required_scope_reduced' => $required_scope_reduced,
					'required_scope_confirmed' => ! empty( $input['required_scope_confirmed'] ),
					'quality_score' => isset( $asset['quality_score'] ) ? (int) $asset['quality_score'] : null,
					'automatic_quality_score' => isset( $asset['quality_auto_score'] ) ? (int) $asset['quality_auto_score'] : null,
					'quality_mode' => $quality_mode,
					'reviewed_content_hash' => $current_content_hash,
					'mutation_performed' => true,
					'generation_evidence_fresh' => is_array( $generation_freshness ) ? ! empty( $generation_freshness['fresh'] ) : null,
					'generation_evidence_stale_override' => $stale_override,
				),
				'ok'
			);
			if ( is_wp_error( $result ) ) return $result;
			if ( $generated_brand_asset && 'approve' === $decision && class_exists( 'MAD4B_SCP_Brand_Context_Builder' ) ) {
				$job_transition = MAD4B_SCP_Brand_Context_Builder::complete_generation_job_for_asset( $asset );
				$result['result']['generation_job_transition'] = is_wp_error( $job_transition )
					? array( 'ready' => false, 'error_code' => $job_transition->get_error_code() )
					: array( 'ready' => true, 'job' => isset( $job_transition['job'] ) ? $job_transition['job'] : array() );
			}
			return $result;

			}
		);
	}

	public static function review_queue() {
		$items = array();
		$counts = array( 'required_pending' => 0, 'optional_pending' => 0, 'content_changed' => 0, 'legacy_unbound' => 0, 'approved' => 0, 'approved_exact' => 0 );
		foreach ( self::assets() as $asset ) {
			if ( ! is_array( $asset ) || 'governed' !== ( isset( $asset['source_mode'] ) ? (string) $asset['source_mode'] : '' ) ) continue;
			$review_status = isset( $asset['review_status'] ) ? (string) $asset['review_status'] : 'unreviewed';
			$current_hash = isset( $asset['content_hash'] ) ? strtolower( trim( (string) $asset['content_hash'] ) ) : '';
			$reviewed_hash = isset( $asset['reviewed_content_hash'] ) ? strtolower( trim( (string) $asset['reviewed_content_hash'] ) ) : '';
			$exact = 'approved' === $review_status && preg_match( '/^[a-f0-9]{64}$/', $current_hash ) && preg_match( '/^[a-f0-9]{64}$/', $reviewed_hash ) && hash_equals( $current_hash, $reviewed_hash );
			if ( 'approved' === $review_status ) { ++$counts['approved']; if ( $exact ) ++$counts['approved_exact']; else ++$counts['legacy_unbound']; }
			elseif ( 'needs_review_content_changed' === $review_status ) ++$counts['content_changed'];
			elseif ( ! empty( $asset['required'] ) ) ++$counts['required_pending'];
			else ++$counts['optional_pending'];
			if ( 'approved' === $review_status && $exact ) continue;
			$items[] = array(
				'asset_id' => isset( $asset['asset_id'] ) ? (string) $asset['asset_id'] : '',
				'source_id' => isset( $asset['source_id'] ) ? (string) $asset['source_id'] : '',
				'title' => isset( $asset['title'] ) ? (string) $asset['title'] : '',
				'category' => isset( $asset['category'] ) ? (string) $asset['category'] : '',
				'authority_class' => isset( $asset['authority_class'] ) ? (string) $asset['authority_class'] : '',
				'required' => ! empty( $asset['required'] ),
				'review_status' => $review_status,
				'review_decision' => isset( $asset['review_decision'] ) ? (string) $asset['review_decision'] : '',
				'review_note' => isset( $asset['review_note'] ) ? (string) $asset['review_note'] : '',
				'review_actor_type' => isset( $asset['review_actor_type'] ) ? (string) $asset['review_actor_type'] : '',
				'review_agent_public_id' => isset( $asset['review_agent_public_id'] ) ? (string) $asset['review_agent_public_id'] : '',
				'content_hash' => $current_hash,
				'reviewed_content_hash' => $reviewed_hash,
				'review_binding_exact' => $exact,
				'content_complete' => ! array_key_exists( 'content_complete', $asset ) || ! empty( $asset['content_complete'] ),
				'normalization_status' => isset( $asset['normalization_status'] ) ? (string) $asset['normalization_status'] : '',
				'content_excerpt' => isset( $asset['content_excerpt'] ) ? (string) $asset['content_excerpt'] : '',
				'quality_score' => isset( $asset['quality_score'] ) ? (int) $asset['quality_score'] : null,
				'classification_confidence' => isset( $asset['classification_confidence'] ) ? (float) $asset['classification_confidence'] : 0.0,
				'classification_source' => isset( $asset['classification_source'] ) ? (string) $asset['classification_source'] : '',
				'automatic_classification' => isset( $asset['automatic_classification'] ) && is_array( $asset['automatic_classification'] ) ? $asset['automatic_classification'] : array(),
				'last_synced_at' => isset( $asset['last_synced_at'] ) ? (string) $asset['last_synced_at'] : '',
			);
		}
		usort( $items, static function ( $a, $b ) { $required = (int) ! empty( $b['required'] ) <=> (int) ! empty( $a['required'] ); return 0 !== $required ? $required : strcmp( (string) $a['title'], (string) $b['title'] ); } );
		return array( 'contract' => 'mad4b.context-review-queue.v1', 'read_only' => true, 'mutation_performed' => false, 'registry_revision' => self::registry_revision(), 'context_fingerprint' => self::context_fingerprint(), 'authority_manifest_fingerprint' => self::authority_manifest_fingerprint(), 'counts' => $counts, 'count' => count( $items ), 'items' => $items );
	}

	public static function brand_core_coverage() {
		$required_sets = array( 'brand_strategy', 'tone_of_voice', 'editorial_guidelines' );
		$coverage = array(); $missing = array(); $conflicting = array(); $freshness_cache = array();
		foreach ( $required_sets as $category ) {
			$eligible = array(); $observed = array(); $hashes = array();
			foreach ( self::assets() as $asset ) {
				if ( ! is_array( $asset ) || $category !== ( isset( $asset['category'] ) ? (string) $asset['category'] : '' ) ) continue;
				$review_status = isset( $asset['review_status'] ) ? (string) $asset['review_status'] : 'unreviewed';
				$current_hash = isset( $asset['content_hash'] ) ? strtolower( trim( (string) $asset['content_hash'] ) ) : '';
				$reviewed_hash = isset( $asset['reviewed_content_hash'] ) ? strtolower( trim( (string) $asset['reviewed_content_hash'] ) ) : '';
				$review_exact = 'approved' === $review_status && preg_match( '/^[a-f0-9]{64}$/', $current_hash ) && preg_match( '/^[a-f0-9]{64}$/', $reviewed_hash ) && hash_equals( $current_hash, $reviewed_hash );
				$reasons = array();
				if ( 'governed' !== ( isset( $asset['source_mode'] ) ? (string) $asset['source_mode'] : '' ) ) $reasons[] = 'source_not_governed';
				if ( 'ready' !== ( isset( $asset['status'] ) ? (string) $asset['status'] : '' ) ) $reasons[] = 'status_not_ready';
				if ( array_key_exists( 'content_complete', $asset ) && empty( $asset['content_complete'] ) ) $reasons[] = 'content_incomplete';
				if ( 'brand_authority' !== ( isset( $asset['authority_class'] ) ? (string) $asset['authority_class'] : '' ) ) $reasons[] = 'wrong_authority_class';
				if ( 'approved' !== $review_status ) $reasons[] = 'review_not_approved'; elseif ( ! $review_exact ) $reasons[] = 'review_not_exactly_bound';
				$generation_fresh = null; $stale_override = ! empty( $asset['generation_evidence_stale_override'] ); $stale_override_bound = false;
				$generated = ! empty( $asset['generated_artifact_id'] ) || 'brand_context_builder' === ( isset( $asset['classification_source'] ) ? (string) $asset['classification_source'] : '' );
				if ( $generated && in_array( $category, array( 'tone_of_voice', 'editorial_guidelines' ), true ) ) {
					$digest = isset( $asset['generation_evidence_digest'] ) ? strtolower( trim( (string) $asset['generation_evidence_digest'] ) ) : '';
					$key = $category . '|' . $digest . '|' . ( ! empty( $asset['generation_include_rendered_frontend'] ) ? '1' : '0' );
					if ( ! array_key_exists( $key, $freshness_cache ) ) {
						$freshness_cache[ $key ] = class_exists( 'MAD4B_SCP_Brand_Context_Builder' )
							? MAD4B_SCP_Brand_Context_Builder::generation_evidence_status( $category, $digest, ! empty( $asset['generation_include_rendered_frontend'] ) )
							: new WP_Error( 'mad4b_brand_generation_freshness_runtime_unavailable', 'Brand generation freshness runtime unavailable.' );
					}
					$freshness = $freshness_cache[ $key ];
					$generation_fresh = is_array( $freshness ) && ! empty( $freshness['fresh'] );
					$current_generation_digest = is_array( $freshness ) && isset( $freshness['current_evidence_digest'] ) ? (string) $freshness['current_evidence_digest'] : '';
					$override_digest = isset( $asset['generation_evidence_reviewed_current_digest'] ) ? (string) $asset['generation_evidence_reviewed_current_digest'] : '';
					$stale_override_bound = $stale_override && '' !== $current_generation_digest && '' !== $override_digest && hash_equals( $current_generation_digest, $override_digest );
					if ( ! $generation_fresh && ! $stale_override_bound ) $reasons[] = 'generation_evidence_stale';
				}
				$row = array(
					'asset_id' => isset( $asset['asset_id'] ) ? (string) $asset['asset_id'] : '',
					'title' => isset( $asset['title'] ) ? (string) $asset['title'] : '',
					'content_hash' => $current_hash,
					'review_status' => $review_status,
					'review_binding_exact' => $review_exact,
					'generation_evidence_fresh' => $generation_fresh,
					'generation_evidence_stale_override' => $stale_override,
					'generation_evidence_stale_override_bound' => $stale_override_bound,
					'reasons' => $reasons,
				);
				$observed[] = $row;
				if ( empty( $reasons ) ) {
					$eligible[] = $row;
					if ( '' !== $current_hash ) $hashes[ $current_hash ] = true;
				}
			}
			$distinct_hashes = array_keys( $hashes );
			sort( $distinct_hashes, SORT_STRING );
			$conflict = count( $distinct_hashes ) > 1;
			$ready = ! empty( $eligible ) && ! $conflict;
			if ( empty( $eligible ) ) $missing[] = $category;
			if ( $conflict ) $conflicting[] = $category;
			$coverage[ $category ] = array(
				'ready' => $ready,
				'conflict' => $conflict,
				'distinct_approved_content_hash_count' => count( $distinct_hashes ),
				'distinct_approved_content_hashes' => $distinct_hashes,
				'eligible_asset_count' => count( $eligible ),
				'eligible_assets' => $eligible,
				'observed_assets' => $observed,
			);
		}
		return array(
			'contract' => 'mad4b.brand-core-context-coverage.v1',
			'read_only' => true,
			'mutation_performed' => false,
			'required_context_sets' => $required_sets,
			'coverage' => $coverage,
			'missing_required_context_sets' => $missing,
			'conflicting_required_context_sets' => $conflicting,
			'ready' => empty( $missing ) && empty( $conflicting ),
			'registry_revision' => self::registry_revision(),
			'context_fingerprint' => self::context_fingerprint(),
			'authority_manifest_fingerprint' => self::authority_manifest_fingerprint(),
		);
	}

	public static function status() {
		$site_status = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::status() : array();
		$profile = self::profile();
		$raw_sources = self::raw_sources();
		$raw_assets = self::raw_assets();
		$sources = self::sources();
		$assets = self::assets();
		$governed_sources = array();
		$task_sources = array();
		$partial_sources = 0;
		foreach ( $sources as $source ) {
			if ( 'governed' === $source['mode'] ) {
				$governed_sources[] = $source;
				if ( 'partial_scan' === ( isset( $source['status'] ) ? (string) $source['status'] : '' ) || empty( $source['last_scan_complete'] ) ) ++$partial_sources;
			} else $task_sources[] = $source;
		}

		$governed_assets = array();
		$required = array();
		$quality_values = array();
		$issue_counts = array(
			'stale' => 0,
			'conflicting' => 0,
			'unavailable' => 0,
			'incomplete' => 0,
			'required_stale' => 0,
			'required_conflicting' => 0,
			'required_unavailable' => 0,
			'required_incomplete' => 0,
			'optional_stale' => 0,
			'optional_conflicting' => 0,
			'optional_unavailable' => 0,
			'optional_incomplete' => 0,
		);
		foreach ( $assets as $asset ) {
			if ( 'governed' !== $asset['source_mode'] ) continue;
			$governed_assets[] = $asset;
			$is_required = ! empty( $asset['required'] );
			if ( $is_required ) $required[] = $asset;
			if ( null !== $asset['quality_score'] ) $quality_values[] = (int) $asset['quality_score'];
			$content_incomplete = 'incomplete' === ( isset( $asset['status'] ) ? (string) $asset['status'] : '' )
				|| ( array_key_exists( 'content_complete', $asset ) && empty( $asset['content_complete'] ) );
			foreach (
				array(
					'stale' => 'stale' === ( isset( $asset['status'] ) ? (string) $asset['status'] : '' ),
					'conflicting' => 'conflicting' === ( isset( $asset['status'] ) ? (string) $asset['status'] : '' ),
					'unavailable' => 'unavailable' === ( isset( $asset['status'] ) ? (string) $asset['status'] : '' ),
					'incomplete' => $content_incomplete,
				) as $issue => $present
			) {
				if ( ! $present ) continue;
				++$issue_counts[ $issue ];
				++$issue_counts[ ( $is_required ? 'required_' : 'optional_' ) . $issue ];
			}
		}

		$ready_required = 0;
		$approved_required = 0;
		$legacy_unbound_review = 0;
		foreach ( $required as $asset ) {
			$content_complete = ! array_key_exists( 'content_complete', $asset ) || ! empty( $asset['content_complete'] );
			if ( 'ready' === $asset['status'] && $content_complete ) ++$ready_required;
			$approved = 'approved' === ( isset( $asset['review_status'] ) ? (string) $asset['review_status'] : '' );
			$current_hash = isset( $asset['content_hash'] ) ? strtolower( trim( (string) $asset['content_hash'] ) ) : '';
			$reviewed_hash = isset( $asset['reviewed_content_hash'] ) ? strtolower( trim( (string) $asset['reviewed_content_hash'] ) ) : '';
			$review_exact = $approved && preg_match( '/^[a-f0-9]{64}$/', $current_hash ) && preg_match( '/^[a-f0-9]{64}$/', $reviewed_hash ) && hash_equals( $current_hash, $reviewed_hash );
			if ( $approved && ! $review_exact ) ++$legacy_unbound_review;
			if ( 'ready' === $asset['status'] && $content_complete && $review_exact ) ++$approved_required;
		}

		$blockers = array();
		$warnings = array();
		if ( empty( $site_status['configured'] ) || empty( $site_status['origin_match'] ) || empty( $site_status['environment_match'] ) ) $blockers[] = 'site_profile_not_enrolled';
		if ( empty( $profile ) ) $blockers[] = 'brand_context_profile_unconfigured';
		if ( count( $raw_sources ) > self::MAX_SOURCES ) $blockers[] = 'context_source_registry_capacity_exceeded';
		if ( count( $raw_assets ) > self::MAX_ASSETS ) $blockers[] = 'context_asset_registry_capacity_exceeded';
		if ( empty( $governed_sources ) ) $blockers[] = 'governed_context_source_missing';
		if ( $partial_sources > 0 ) $blockers[] = 'governed_context_source_scan_incomplete';
		if ( empty( $governed_assets ) ) $blockers[] = 'governed_context_assets_missing';
		if ( ! empty( $governed_assets ) && empty( $required ) ) $blockers[] = 'mandatory_context_unclassified';
		if ( count( $required ) !== $ready_required ) $blockers[] = 'mandatory_context_not_ready';
		if ( count( $required ) !== $approved_required ) $blockers[] = 'mandatory_context_review_required';
		if ( $legacy_unbound_review > 0 ) $blockers[] = 'mandatory_context_review_binding_required';
		if ( $issue_counts['required_stale'] > 0 ) $blockers[] = 'mandatory_context_contains_stale_assets';
		if ( $issue_counts['required_unavailable'] > 0 ) $blockers[] = 'mandatory_context_contains_unavailable_assets';
		if ( $issue_counts['required_conflicting'] > 0 ) $blockers[] = 'mandatory_context_conflict';
		if ( $issue_counts['required_incomplete'] > 0 ) $blockers[] = 'mandatory_context_contains_incomplete_assets';
		if ( $issue_counts['optional_stale'] > 0 ) $warnings[] = 'optional_context_contains_stale_assets';
		if ( $issue_counts['optional_unavailable'] > 0 ) $warnings[] = 'optional_context_contains_unavailable_assets';
		if ( $issue_counts['optional_conflicting'] > 0 ) $warnings[] = 'optional_context_contains_conflicting_assets';
		if ( $issue_counts['optional_incomplete'] > 0 ) $warnings[] = 'optional_context_contains_incomplete_assets';

		$ready = empty( $blockers );
		$state = $ready ? ( empty( $warnings ) ? 'ready' : 'ready_with_warnings' ) : ( empty( $profile ) ? 'unconfigured' : 'blocked' );
		return array(
			'contract' => 'mad4b.context-authority.v2',
			'ready' => $ready,
			'state' => $state,
			'site_uuid' => isset( $site_status['site_uuid'] ) ? (string) $site_status['site_uuid'] : '',
			'brand_id' => isset( $profile['brand_id'] ) ? (string) $profile['brand_id'] : '',
			'brand_name' => isset( $profile['brand_name'] ) ? (string) $profile['brand_name'] : '',
			'profile_revision' => isset( $profile['revision'] ) ? absint( $profile['revision'] ) : 0,
			'review_policy' => self::ai_review_policy_status(),
			'registry_revision' => self::registry_revision(),
			'raw_source_count' => count( $raw_sources ),
			'raw_asset_count' => count( $raw_assets ),
			// Counts only; never expose foreign record identities through the status API.
			'quarantined_source_record_count' => max( 0, count( $raw_sources ) - count( $sources ) ),
			'quarantined_asset_record_count' => max( 0, count( $raw_assets ) - count( $assets ) ),
			'ownership_review_required' => count( $raw_sources ) > count( $sources ) || count( $raw_assets ) > count( $assets ),
			'ownership_migration_automatic' => false,
			'source_registry_capacity_exceeded' => count( $raw_sources ) > self::MAX_SOURCES,
			'asset_registry_capacity_exceeded' => count( $raw_assets ) > self::MAX_ASSETS,
			'context_fingerprint' => self::context_fingerprint( $assets, $sources ),
			'authority_manifest_fingerprint' => self::authority_manifest_fingerprint( $assets ),
			'governed_source_count' => count( $governed_sources ),
			'task_source_count' => count( $task_sources ),
			'partial_source_count' => $partial_sources,
			'asset_count' => count( $assets ),
			'governed_asset_count' => count( $governed_assets ),
			'required_asset_count' => count( $required ),
			'ready_required_asset_count' => $ready_required,
			'approved_required_asset_count' => $approved_required,
			'legacy_unbound_review_asset_count' => $legacy_unbound_review,
			'stale_asset_count' => $issue_counts['stale'],
			'unavailable_asset_count' => $issue_counts['unavailable'],
			'incomplete_asset_count' => $issue_counts['incomplete'],
			'conflicting_asset_count' => $issue_counts['conflicting'],
			'required_stale_asset_count' => $issue_counts['required_stale'],
			'required_unavailable_asset_count' => $issue_counts['required_unavailable'],
			'required_incomplete_asset_count' => $issue_counts['required_incomplete'],
			'required_conflicting_asset_count' => $issue_counts['required_conflicting'],
			'optional_stale_asset_count' => $issue_counts['optional_stale'],
			'optional_unavailable_asset_count' => $issue_counts['optional_unavailable'],
			'optional_incomplete_asset_count' => $issue_counts['optional_incomplete'],
			'optional_conflicting_asset_count' => $issue_counts['optional_conflicting'],
			'average_quality_score' => $quality_values ? (int) round( array_sum( $quality_values ) / count( $quality_values ) ) : null,
			'quality_scored_asset_count' => count( $quality_values ),
			'blockers' => array_values( array_unique( $blockers ) ),
			'warnings' => array_values( array_unique( $warnings ) ),
		);
	}
	public static function classify_asset( $name, $path = '', $content = '' ) {
		$haystack = strtolower( trim( (string) $name . ' ' . (string) $path . ' ' . substr( (string) $content, 0, 6000 ) ) );
		$rules = array(
			 'brand_strategy' => array( 'brand strategy', 'brand core', 'core brand identity', 'brand identity', 'brand plan', 'استراتيجية العلامة', 'استراتيجية البراند', 'جوهر العلامة' ),
			 'brand_positioning' => array( 'positioning', 'brand position', 'تموضع العلامة', 'التموضع' ),
			 'audience_persona' => array( 'persona', 'audience', 'customer profile', 'buyer profile', 'الجمهور', 'شخصية العميل', 'العميل المثالي' ),
			 'tone_of_voice' => array( 'tone of voice', 'tone-of-voice', 'brand voice', 'tov', 'نبرة الصوت', 'نبرة العلامة', 'أسلوب الكتابة' ),
			'messaging' => array( 'messaging', 'message framework', 'key messages' ),
			 'editorial_guidelines' => array( 'editorial', 'writing guideline', 'style guide', 'content guideline', 'دليل التحرير', 'إرشادات الكتابة', 'قواعد المحتوى' ),
			 'terminology' => array( 'terminology', 'naming rule', 'glossary', 'vocabulary', 'المصطلحات', 'قاموس', 'التسمية' ),
			'claim_policy' => array( 'prohibited claim', 'claim policy', 'restriction', 'legal claim' ),
			 'seo_strategy' => array( 'seo', 'search strategy', 'keyword strategy', 'استراتيجية السيو', 'الكلمات المفتاحية', 'تحسين محركات البحث' ),
			'content_strategy' => array( 'content strategy', 'blog strategy', 'content pillar' ),
			'campaign_strategy' => array( 'campaign plan', 'campaign strategy' ),
			'product_knowledge' => array( 'product knowledge', 'product guide', 'product catalog' ),
			'service_knowledge' => array( 'service knowledge', 'service guide', 'services' ),
			'destination_knowledge' => array( 'destination guide', 'destination knowledge', 'travel guide' ),
			 'market_research' => array( 'market research', 'market report', 'research report', 'market insight', 'بحث السوق', 'دراسة السوق', 'تقرير السوق' ),
			 'writer_reference' => array( 'writer reference', 'author reference', 'journalist', 'writing sample', 'style profile', 'مرجع كاتب', 'نموذج كتابة', 'أسلوب الكاتب' ),
			'content_example' => array( 'content example', 'sample article', 'sample blog', 'example copy' ),
		);
		$best = 'uncategorized';
		$best_hits = 0;
		foreach ( $rules as $category => $needles ) {
			$hits = 0;
			foreach ( $needles as $needle ) if ( false !== strpos( $haystack, $needle ) ) ++$hits;
			if ( $hits > $best_hits ) { $best = $category; $best_hits = $hits; }
		}
		// Operational/connector inventories often quote "brand strategy" while
		// describing data plumbing, not the approved commercial Brand Strategy.
		// When only document body text produced a strategy hit, never elevate
		// an operational document into mandatory Brand Authority automatically.
		// An explicit human-classified asset is preserved by the caller and can
		// still be reviewed via the separate Context review authority surface.
		if ( in_array( $best, array( 'brand_strategy', 'tone_of_voice', 'editorial_guidelines' ), true ) ) {
			$title_path = strtolower( trim( (string) $name . ' ' . (string) $path ) );
			$named_authority = false;
			foreach ( $rules[ $best ] as $needle ) {
				if ( false !== strpos( $title_path, $needle ) ) { $named_authority = true; break; }
			}
			$operational_title = 1 === preg_match(
				'/\\b(wordpress|wp-json|connector|mcp|configuration|snapshot|workflow|import|export|api|operational|operations|publish preparation|data store|database)\\b/i',
				(string) $name . ' ' . (string) $path
			);
			if ( ! $named_authority && $operational_title ) {
				$best = 'uncategorized';
				$best_hits = 0;
			}
		}
		$confidence = 0.35;
		if ( 1 === $best_hits ) $confidence = 0.72;
		if ( 2 === $best_hits ) $confidence = 0.88;
		if ( $best_hits >= 3 ) $confidence = 0.96;
		$authority = 'reference';
		if ( in_array( $best, array( 'brand_strategy', 'brand_positioning', 'audience_persona', 'tone_of_voice', 'messaging', 'editorial_guidelines', 'terminology', 'claim_policy' ), true ) ) $authority = 'brand_authority';
		elseif ( in_array( $best, array( 'seo_strategy', 'content_strategy', 'campaign_strategy', 'product_knowledge', 'service_knowledge', 'destination_knowledge', 'market_research' ), true ) ) $authority = 'task_knowledge';
		$required = in_array( $best, array( 'brand_strategy', 'tone_of_voice', 'editorial_guidelines', 'terminology', 'claim_policy' ), true );
		$priority = 'brand_authority' === $authority ? 100 : ( 'task_knowledge' === $authority ? 70 : 40 );
		return array(
			'category' => $best,
			'classification_confidence' => $confidence,
			'classification_source' => 'automatic_heuristic',
			'authority_class' => $authority,
			'required' => $required,
			'priority' => $priority,
		);
	}

	public static function score_asset( array $metadata, $content = '' ) {
		$content = trim( (string) $content );
		$word_count = '' === $content ? 0 : count( preg_split( '/\s+/u', $content, -1, PREG_SPLIT_NO_EMPTY ) );
		$paragraphs = '' === $content ? array() : array_values( array_filter( preg_split( '/\R{2,}/u', $content ) ) );
		$paragraph_count = count( $paragraphs );
		$category = isset( $metadata['category'] ) ? sanitize_key( (string) $metadata['category'] ) : 'uncategorized';
		$profile = self::quality_profile_for_category( $category );
		$target_words = self::quality_target_words( $profile );

		$modified = isset( $metadata['modifiedTime'] ) ? strtotime( (string) $metadata['modifiedTime'] ) : false;
		$age_days = false === $modified ? null : max( 0, (int) floor( ( time() - $modified ) / DAY_IN_SECONDS ) );
		$freshness = null === $age_days ? 50 : ( $age_days <= 90 ? 100 : ( $age_days <= 365 ? 85 : ( $age_days <= 730 ? 65 : 45 ) ) );
		$extractability = '' !== $content ? 100 : 45;

		$metadata_signals = 0;
		foreach ( array( 'file_id', 'mimeType', 'modifiedTime', 'webViewLink' ) as $field ) if ( ! empty( $metadata[ $field ] ) ) ++$metadata_signals;
		$source_quality = min( 100, 55 + ( $metadata_signals * 10 ) + ( ! empty( $metadata['classification_confidence'] ) ? 5 : 0 ) );

		$reasons = array();
		if ( null === $age_days ) $reasons[] = 'freshness_unknown';
		elseif ( $age_days <= 90 ) $reasons[] = 'recent_source';
		elseif ( $age_days > 730 ) $reasons[] = 'source_older_than_two_years';

		if ( '' === $content ) {
			$overall = (int) round( $freshness * 0.35 + $source_quality * 0.35 + $extractability * 0.30 );
			$reasons[] = 'metadata_only_no_normalized_text';
			return array(
				'contract' => self::QUALITY_CONTRACT,
				'profile' => $profile,
				'overall_score' => max( 0, min( 100, $overall ) ),
				'confidence' => 0.45,
				'mode' => 'metadata_provisional',
				'provisional' => true,
				'word_count' => 0,
				'paragraph_count' => 0,
				'weights' => array( 'freshness' => 0.35, 'source_quality' => 0.35, 'extractability' => 0.30 ),
				'dimensions' => array(
					'freshness' => $freshness,
					'completeness' => null,
					'structure' => null,
					'specificity' => null,
					'source_quality' => $source_quality,
					'language_quality' => null,
					'retrieval_quality' => null,
					'extractability' => $extractability,
				),
				'reasons' => $reasons,
			);
		}

		$ratio = $target_words > 0 ? min( 1, $word_count / $target_words ) : 1;
		$completeness = (int) round( 35 + ( $ratio * 65 ) );
		if ( $ratio < 0.35 ) $reasons[] = 'content_short_for_quality_profile';
		elseif ( $ratio >= 0.85 ) $reasons[] = 'content_depth_matches_quality_profile';

		$heading_count = preg_match_all( '/(^|\R)\s*#{1,6}\s+|(^|\R)\s*[^\r\n]{2,80}:\s*(?=\R|$)/um', $content, $unused );
		$bullet_count = preg_match_all( '/(^|\R)\s*(?:[-*•]|\d+[.)])\s+/u', $content, $unused );
		$table_signal = preg_match( '/\|[^\r\n]+\|/u', $content ) ? 1 : 0;
		$structure = min( 100, 42 + min( 28, $paragraph_count * 4 ) + min( 15, $heading_count * 5 ) + min( 10, $bullet_count * 2 ) + ( $table_signal ? 5 : 0 ) );
		if ( $structure >= 80 ) $reasons[] = 'well_structured_for_retrieval';
		elseif ( $structure < 55 ) $reasons[] = 'weak_document_structure';

		$number_signals = preg_match_all( '/\p{N}+(?:[.,]\p{N}+)?%?/u', $content, $unused );
		$reference_signals = preg_match_all( '/https?:\/\/|\[[0-9]+\]|\([^\)]{2,80},\s*20[0-9]{2}\)/u', $content, $unused );
		$list_signals = min( 5, $bullet_count );
		$specificity = min( 100, 45 + min( 30, $number_signals * 3 ) + min( 15, $reference_signals * 5 ) + ( $list_signals * 2 ) );
		if ( $specificity >= 75 ) $reasons[] = 'strong_specificity_signals';
		elseif ( $specificity < 55 ) $reasons[] = 'limited_specificity_signals';

		$sentence_count = max( 1, preg_match_all( '/[.!?؟]+(?:\s|$)/u', $content, $unused ) );
		$long_paragraphs = 0;
		foreach ( $paragraphs as $paragraph ) {
			$words = count( preg_split( '/\s+/u', trim( $paragraph ), -1, PREG_SPLIT_NO_EMPTY ) );
			if ( $words > 160 ) ++$long_paragraphs;
		}
		$language_quality = 82;
		if ( $word_count < 80 ) $language_quality -= 12;
		if ( $sentence_count < 3 && $word_count > 150 ) $language_quality -= 10;
		$language_quality -= min( 20, $long_paragraphs * 5 );
		if ( preg_match( '/([!?؟.,])\1{2,}/u', $content ) ) $language_quality -= 8;
		$language_quality = max( 35, min( 100, $language_quality ) );
		if ( $long_paragraphs > 0 ) $reasons[] = 'very_long_paragraphs_reduce_readability';

		$retrieval_quality = min( 100, 45 + min( 25, $paragraph_count * 4 ) + min( 15, $heading_count * 5 ) + ( $word_count >= 200 ? 10 : 0 ) + ( $extractability >= 100 ? 5 : 0 ) );
		if ( $retrieval_quality >= 80 ) $reasons[] = 'high_retrieval_readiness';

		$dimensions = array(
			'freshness' => $freshness,
			'completeness' => $completeness,
			'structure' => $structure,
			'specificity' => $specificity,
			'source_quality' => $source_quality,
			'language_quality' => $language_quality,
			'retrieval_quality' => $retrieval_quality,
			'extractability' => $extractability,
		);
		$weights = self::quality_weights( $profile );
		$overall = 0.0;
		foreach ( $weights as $dimension => $weight ) $overall += ( isset( $dimensions[ $dimension ] ) ? (float) $dimensions[ $dimension ] : 0.0 ) * (float) $weight;
		$confidence = min( 0.96, 0.62 + ( min( 1, $ratio ) * 0.22 ) + ( min( 1, $paragraph_count / 5 ) * 0.08 ) + ( $metadata_signals >= 3 ? 0.04 : 0 ) );

		return array(
			'contract' => self::QUALITY_CONTRACT,
			'profile' => $profile,
			'overall_score' => max( 0, min( 100, (int) round( $overall ) ) ),
			'confidence' => round( $confidence, 2 ),
			'mode' => 'content_heuristic_v2',
			'provisional' => false,
			'word_count' => $word_count,
			'paragraph_count' => $paragraph_count,
			'target_word_count' => $target_words,
			'weights' => $weights,
			'dimensions' => $dimensions,
			'reasons' => array_values( array_unique( $reasons ) ),
		);
	}

	private static function quality_profile_for_category( $category ) {
		$category = sanitize_key( (string) $category );
		if ( in_array( $category, array( 'brand_strategy', 'brand_positioning', 'audience_persona', 'tone_of_voice', 'messaging', 'editorial_guidelines', 'terminology', 'claim_policy', 'legal_policy', 'operational_policy' ), true ) ) return 'brand_policy';
		if ( 'market_research' === $category ) return 'market_research';
		if ( in_array( $category, array( 'writer_reference', 'content_example', 'historical_content' ), true ) ) return 'writer_reference';
		if ( in_array( $category, array( 'seo_strategy', 'content_strategy', 'campaign_strategy', 'product_knowledge', 'service_knowledge', 'destination_knowledge' ), true ) ) return 'knowledge';
		return 'generic';
	}

	private static function quality_target_words( $profile ) {
		$targets = array(
			'brand_policy' => 300,
			'knowledge' => 600,
			'market_research' => 900,
			'writer_reference' => 700,
			'generic' => 400,
		);
		return isset( $targets[ $profile ] ) ? (int) $targets[ $profile ] : 400;
	}

	private static function quality_weights( $profile ) {
		$profiles = array(
			'brand_policy' => array( 'freshness' => 0.12, 'completeness' => 0.18, 'structure' => 0.10, 'specificity' => 0.18, 'source_quality' => 0.14, 'language_quality' => 0.10, 'retrieval_quality' => 0.10, 'extractability' => 0.08 ),
			'knowledge' => array( 'freshness' => 0.15, 'completeness' => 0.20, 'structure' => 0.10, 'specificity' => 0.18, 'source_quality' => 0.14, 'language_quality' => 0.08, 'retrieval_quality' => 0.10, 'extractability' => 0.05 ),
			'market_research' => array( 'freshness' => 0.22, 'completeness' => 0.17, 'structure' => 0.08, 'specificity' => 0.22, 'source_quality' => 0.17, 'language_quality' => 0.04, 'retrieval_quality' => 0.06, 'extractability' => 0.04 ),
			'writer_reference' => array( 'freshness' => 0.05, 'completeness' => 0.15, 'structure' => 0.18, 'specificity' => 0.12, 'source_quality' => 0.10, 'language_quality' => 0.25, 'retrieval_quality' => 0.10, 'extractability' => 0.05 ),
			'generic' => array( 'freshness' => 0.15, 'completeness' => 0.20, 'structure' => 0.15, 'specificity' => 0.15, 'source_quality' => 0.15, 'language_quality' => 0.08, 'retrieval_quality' => 0.08, 'extractability' => 0.04 ),
		);
		return isset( $profiles[ $profile ] ) ? $profiles[ $profile ] : $profiles['generic'];
	}

	public static function context_fingerprint( $assets = null, $sources = null ) {
		$site = self::site_binding();
		if ( is_wp_error( $site ) ) return hash( 'sha256', '[]' );
		$source_records = null === $sources ? self::raw_sources() : ( is_array( $sources ) ? $sources : array() );
		$authorized_sources = self::authorized_sources_from_records( $source_records, $site );
		$asset_records = null === $assets ? self::raw_assets() : ( is_array( $assets ) ? $assets : array() );
		$assets = self::authorized_assets_from_records( $asset_records, $authorized_sources, $site );
		$rows = array();
		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) || 'governed' !== ( isset( $asset['source_mode'] ) ? $asset['source_mode'] : '' ) ) continue;
			$rows[] = array(
				'asset_id' => isset( $asset['asset_id'] ) ? (string) $asset['asset_id'] : '',
				'source_id' => isset( $asset['source_id'] ) ? (string) $asset['source_id'] : '',
				'version' => isset( $asset['version'] ) ? (string) $asset['version'] : '',
				'content_hash' => isset( $asset['content_hash'] ) ? (string) $asset['content_hash'] : '',
				'content_complete' => ! array_key_exists( 'content_complete', $asset ) || ! empty( $asset['content_complete'] ),
				'normalization_status' => isset( $asset['normalization_status'] ) ? (string) $asset['normalization_status'] : '',
				'parent_folder_id' => isset( $asset['parent_folder_id'] ) ? (string) $asset['parent_folder_id'] : '',
				'category' => isset( $asset['category'] ) ? (string) $asset['category'] : '',
				'authority_class' => isset( $asset['authority_class'] ) ? (string) $asset['authority_class'] : '',
				'classification_source' => isset( $asset['classification_source'] ) ? (string) $asset['classification_source'] : '',
				'review_status' => isset( $asset['review_status'] ) ? (string) $asset['review_status'] : '',
				'reviewed_content_hash' => isset( $asset['reviewed_content_hash'] ) ? (string) $asset['reviewed_content_hash'] : '',
				'review_decision' => isset( $asset['review_decision'] ) ? (string) $asset['review_decision'] : '',
				'priority' => isset( $asset['priority'] ) ? (int) $asset['priority'] : 0,
				'required' => ! empty( $asset['required'] ),
				'status' => isset( $asset['status'] ) ? (string) $asset['status'] : '',
			);
		}
		usort( $rows, static function ( $a, $b ) { return strcmp( $a['asset_id'], $b['asset_id'] ); } );
		$json = wp_json_encode( $rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return hash( 'sha256', is_string( $json ) ? $json : '[]' );
	}

	private static function normalize_asset( array $source, array $asset ) {
		$file_id = self::bounded_external_id( isset( $asset['file_id'] ) ? $asset['file_id'] : '' );
		if ( '' === $file_id ) return new WP_Error( 'mad4b_context_asset_file_id_required', 'Asset requires a canonical external file ID.' );
		$parent_folder_id = self::bounded_external_id( isset( $asset['parent_folder_id'] ) ? $asset['parent_folder_id'] : '' );
		if ( '' === $parent_folder_id && ! empty( $asset['parents'] ) && is_array( $asset['parents'] ) ) $parent_folder_id = self::bounded_external_id( (string) reset( $asset['parents'] ) );
		$title = trim( sanitize_text_field( isset( $asset['title'] ) ? $asset['title'] : '' ) );
		if ( '' === $title ) $title = 'Untitled';
		$content_complete = ! array_key_exists( 'content_complete', $asset ) || ! empty( $asset['content_complete'] );
		$content = $content_complete && isset( $asset['normalized_text'] ) ? (string) $asset['normalized_text'] : '';
		$classification = self::classify_asset( $title, isset( $asset['path'] ) ? $asset['path'] : '', $content );
		$metadata = $asset;
		$metadata['category'] = $classification['category'];
		$metadata['classification_confidence'] = $classification['classification_confidence'];
		$quality = self::score_asset( $metadata, $content );
		$content_hash = isset( $asset['content_hash'] ) ? strtolower( trim( (string) $asset['content_hash'] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $content_hash ) ) {
			$basis = '' !== $content ? $content : $file_id . '|' . ( isset( $asset['modifiedTime'] ) ? $asset['modifiedTime'] : '' );
			$content_hash = hash( 'sha256', $basis );
		}
		$asset_id = hash( 'sha256', (string) $source['source_id'] . '|' . $file_id );
		$provider_identity = array();
		$app_properties = isset( $asset['appProperties'] ) && is_array( $asset['appProperties'] ) ? $asset['appProperties'] : array();
		if ( isset( $app_properties['mad4b_kind'] ) && 'brand_context' === (string) $app_properties['mad4b_kind'] ) {
			$candidate_identity = array(
				'mad4b_kind' => 'brand_context',
				'mad4b_artifact' => isset( $app_properties['mad4b_artifact'] ) ? strtolower( trim( (string) $app_properties['mad4b_artifact'] ) ) : '',
				'mad4b_source' => isset( $app_properties['mad4b_source'] ) ? strtolower( trim( (string) $app_properties['mad4b_source'] ) ) : '',
				'mad4b_idempotency' => isset( $app_properties['mad4b_idempotency'] ) ? strtolower( trim( (string) $app_properties['mad4b_idempotency'] ) ) : '',
				'mad4b_request' => isset( $app_properties['mad4b_request'] ) ? strtolower( trim( (string) $app_properties['mad4b_request'] ) ) : '',
			);
			$valid_identity = 1 === preg_match( '/^[a-f0-9-]{36}$/', $candidate_identity['mad4b_artifact'] );
			foreach ( array( 'mad4b_source', 'mad4b_idempotency', 'mad4b_request' ) as $identity_field ) {
				$valid_identity = $valid_identity && 1 === preg_match( '/^[a-f0-9]{64}$/', $candidate_identity[ $identity_field ] );
			}
			if ( $valid_identity ) $provider_identity = $candidate_identity;
		}
		return array(
			'contract' => self::ASSET_CONTRACT,
			'asset_id' => $asset_id,
			'site_uuid' => (string) $source['site_uuid'],
			'brand_id' => (string) $source['brand_id'],
			'source_id' => (string) $source['source_id'],
			'source_mode' => (string) $source['mode'],
			'task_scope' => isset( $source['task_scope'] ) ? (string) $source['task_scope'] : '',
			'provider' => (string) $source['provider'],
			'provider_identity' => $provider_identity,
			'file_id' => $file_id,
			'parent_folder_id' => $parent_folder_id,
			'title' => $title,
			'path' => isset( $asset['path'] ) ? substr( sanitize_text_field( (string) $asset['path'] ), 0, 500 ) : '',
			'mime_type' => isset( $asset['mimeType'] ) ? substr( sanitize_text_field( (string) $asset['mimeType'] ), 0, 191 ) : '',
			'version' => isset( $asset['modifiedTime'] ) ? sanitize_text_field( (string) $asset['modifiedTime'] ) : '',
			'content_hash' => $content_hash,
			'content_complete' => (bool) $content_complete,
			'content_bytes' => isset( $asset['content_bytes'] ) ? max( 0, (int) $asset['content_bytes'] ) : strlen( $content ),
			'normalization_status' => isset( $asset['normalization_status'] ) ? sanitize_key( (string) $asset['normalization_status'] ) : ( $content_complete ? 'ready' : 'incomplete' ),
			'normalization_reason' => isset( $asset['normalization_reason'] ) ? sanitize_key( (string) $asset['normalization_reason'] ) : '',
			'content_available' => $content_complete && '' !== $content,
			'content_excerpt' => $content_complete && '' !== $content ? wp_trim_words( wp_strip_all_tags( $content ), 45, '…' ) : '',
			'automatic_classification' => array(
				'category' => $classification['category'],
				'authority_class' => $classification['authority_class'],
				'required' => ! empty( $classification['required'] ),
				'confidence' => isset( $classification['classification_confidence'] ) ? (float) $classification['classification_confidence'] : 0.0,
				'source' => isset( $classification['classification_source'] ) ? (string) $classification['classification_source'] : 'automatic_heuristic',
			),
			'category' => $classification['category'],
			'classification_confidence' => $classification['classification_confidence'],
			'classification_source' => $classification['classification_source'],
			'authority_class' => $classification['authority_class'],
			'required' => $classification['required'],
			'priority' => $classification['priority'],
			'language' => isset( $asset['language'] ) ? sanitize_key( (string) $asset['language'] ) : '',
			'scope' => 'all',
			'quality_score' => isset( $quality['overall_score'] ) ? (int) $quality['overall_score'] : null,
			'quality_auto_score' => isset( $quality['overall_score'] ) ? (int) $quality['overall_score'] : null,
			'quality' => $quality,
			'reviewed_by' => 0,
			'reviewed_at' => '',
			'review_status' => 'unreviewed',
			'reviewed_content_hash' => '',
			'review_decision' => '',
			'review_note' => '',
			'status' => $content_complete ? 'ready' : 'incomplete',
			'last_synced_at' => gmdate( 'c' ),
		);
	}

	private static function audit_preflight() {
		if ( ! class_exists( 'MAD4B_SCP_Audit' ) ) return new WP_Error( 'mad4b_context_audit_unavailable', 'Append-only audit service is unavailable.' );
		$status = MAD4B_SCP_Audit::storage_status();
		if ( ! is_array( $status ) || empty( $status['ready'] ) ) return new WP_Error( 'mad4b_context_audit_not_ready', 'Append-only audit storage is not ready; Context governance mutation remains fail-closed.' );
		return true;
	}

	private static function site_binding() {
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) ) return new WP_Error( 'mad4b_context_site_profile_unavailable', 'Site Profile service is unavailable.' );
		$status = MAD4B_SCP_Site_Profile::status();
		if ( empty( $status['configured'] ) || empty( $status['origin_match'] ) || empty( $status['environment_match'] ) ) return new WP_Error( 'mad4b_context_site_profile_not_enrolled', 'Context Authority requires an enrolled Site Profile with matching origin and environment.' );
		$site_uuid = isset( $status['site_uuid'] ) ? strtolower( trim( (string) $status['site_uuid'] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $site_uuid ) ) return new WP_Error( 'mad4b_context_site_uuid_invalid', 'Site Profile does not expose a valid site UUID.' );
		return array( 'site_uuid' => $site_uuid, 'environment' => isset( $status['environment'] ) ? (string) $status['environment'] : '' );
	}

	private static function valid_profile( $record ) {
		if ( ! is_array( $record ) || self::PROFILE_CONTRACT !== ( isset( $record['contract'] ) ? (string) $record['contract'] : '' ) ) return false;
		if ( empty( $record['site_uuid'] ) || ! preg_match( '/^[a-f0-9-]{36}$/', (string) $record['site_uuid'] ) ) return false;
		return ! empty( $record['brand_id'] ) && ! empty( $record['brand_name'] ) && ! empty( $record['revision'] );
	}

	private static function valid_source( $record ) {
		if ( ! is_array( $record ) || self::SOURCE_CONTRACT !== ( isset( $record['contract'] ) ? (string) $record['contract'] : '' ) ) return false;
		$external_root_id = isset( $record['external_root_id'] ) ? trim( (string) $record['external_root_id'] ) : '';
		if ( '' === $external_root_id || 'root' === strtolower( $external_root_id ) ) return false;
		return ! empty( $record['source_id'] ) && ! empty( $record['site_uuid'] ) && in_array( isset( $record['mode'] ) ? $record['mode'] : '', array( 'governed', 'task_attachment' ), true );
	}

	private static function valid_asset( $record ) {
		if ( ! is_array( $record ) || self::ASSET_CONTRACT !== ( isset( $record['contract'] ) ? (string) $record['contract'] : '' ) ) return false;
		return ! empty( $record['asset_id'] ) && ! empty( $record['source_id'] ) && ! empty( $record['file_id'] );
	}

	private static function bounded_external_id( $value ) {
		$value = trim( sanitize_text_field( (string) $value ) );
		if ( '' === $value || strlen( $value ) > 255 || ! preg_match( '/^[A-Za-z0-9_\-\.]+$/', $value ) ) return '';
		return $value;
	}

	public static function registry_revision() {
		return max( 0, (int) get_option( self::REGISTRY_REVISION_OPTION, 0 ) );
	}

	public static function authority_manifest_fingerprint( $assets = null, $sources = null ) {
		$site = self::site_binding();
		if ( is_wp_error( $site ) ) return hash( 'sha256', '[]' );
		$source_records = null === $sources ? self::raw_sources() : ( is_array( $sources ) ? $sources : array() );
		$authorized_sources = self::authorized_sources_from_records( $source_records, $site );
		$asset_records = null === $assets ? self::raw_assets() : ( is_array( $assets ) ? $assets : array() );
		$assets = self::authorized_assets_from_records( $asset_records, $authorized_sources, $site );
		$rows = array();
		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) || 'governed' !== ( isset( $asset['source_mode'] ) ? (string) $asset['source_mode'] : '' ) ) continue;
			$rows[] = array(
				'asset_id' => isset( $asset['asset_id'] ) ? (string) $asset['asset_id'] : '',
				'category' => isset( $asset['category'] ) ? (string) $asset['category'] : '',
				'authority_class' => isset( $asset['authority_class'] ) ? (string) $asset['authority_class'] : '',
				'classification_source' => isset( $asset['classification_source'] ) ? (string) $asset['classification_source'] : '',
				'review_status' => isset( $asset['review_status'] ) ? (string) $asset['review_status'] : '',
				'reviewed_content_hash' => isset( $asset['reviewed_content_hash'] ) ? (string) $asset['reviewed_content_hash'] : '',
				'review_decision' => isset( $asset['review_decision'] ) ? (string) $asset['review_decision'] : '',
				'required' => ! empty( $asset['required'] ),
				'priority' => isset( $asset['priority'] ) ? (int) $asset['priority'] : 0,
				'status' => isset( $asset['status'] ) ? (string) $asset['status'] : '',
				'content_complete' => ! array_key_exists( 'content_complete', $asset ) || ! empty( $asset['content_complete'] ),
			);
		}
		usort( $rows, static function ( $a, $b ) { return strcmp( $a['asset_id'], $b['asset_id'] ); } );
		$json = wp_json_encode( $rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return hash( 'sha256', is_string( $json ) ? $json : '[]' );
	}

	private static function with_registry_lock( $operation, $callback ) {
		// Initial Brand enrollment/rename establishes identity. All other
		// registry writes require the same exact revision-bound scope as jobs.
		$checkpoint = null;
		if ( 'save_profile' !== (string) $operation ) {
			$checkpoint = class_exists( 'MAD4B_SCP_Operational_Integrity', false )
				? MAD4B_SCP_Operational_Integrity::capture()
				: new WP_Error( 'mad4b_scope_integrity_missing', 'Verified operational scope is unavailable.' );
			if ( is_wp_error( $checkpoint ) ) return $checkpoint;
		}
		$lock = self::acquire_registry_lock( $operation );
		if ( is_wp_error( $lock ) ) return $lock;
		$snapshot = self::registry_option_snapshot();
		try {
			$result = call_user_func( $callback );
			if ( is_wp_error( $result ) ) return self::compensate_registry_error( $snapshot, $operation, $result, 'callback' );
			// Recheck site, brand, revision and actor before registry revision/audit.
			// A drift forces the existing registry snapshot compensation path.
			if ( null !== $checkpoint ) {
				$rechecked = MAD4B_SCP_Operational_Integrity::assert_unchanged( $checkpoint );
				if ( is_wp_error( $rechecked ) ) {
					return self::compensate_registry_error( $snapshot, $operation, $rechecked, 'scope_drift' );
				}
			}

			$wrapped = self::is_audited_registry_result( $result );
			$public_result = $wrapped ? $result['result'] : $result;
			$audit = $wrapped ? $result['audit'] : array();

			$revision = self::bump_registry_revision();
			if ( is_wp_error( $revision ) ) {
				$failure = self::compensate_registry_error( $snapshot, $operation, $revision, 'revision' );
				if ( class_exists( 'MAD4B_SCP_Audit' ) ) {
					MAD4B_SCP_Audit::record(
						'mad4b/context-registry-revision-commit-failed',
						array(
							'operation' => sanitize_key( (string) $operation ),
							'error_code' => $revision->get_error_code(),
							'compensated' => 'mad4b_context_registry_recovery_required' !== ( is_wp_error( $failure ) ? $failure->get_error_code() : '' ),
						),
						'failure'
					);
				}
				return $failure;
			}

			if ( $audit ) {
				if ( ! class_exists( 'MAD4B_SCP_Audit' ) ) {
					$audit_result = new WP_Error( 'mad4b_context_audit_unavailable', 'Append-only audit service is unavailable for the committed Context governance mutation.' );
				} else {
					$audit_result = MAD4B_SCP_Audit::record(
						(string) $audit['ability'],
						isset( $audit['summary'] ) && is_array( $audit['summary'] ) ? $audit['summary'] : array(),
						isset( $audit['status'] ) ? (string) $audit['status'] : 'ok'
					);
				}
				if ( is_wp_error( $audit_result ) ) return self::compensate_registry_error( $snapshot, $operation, $audit_result, 'audit' );
			}

			return $public_result;
		} finally {
			self::release_registry_lock( $lock );
		}
	}

	private static function audited_registry_result( $result, $ability, array $summary, $status = 'ok' ) {
		return array(
			'__mad4b_registry_mutation_v1' => true,
			'result' => $result,
			'audit' => array(
				'ability' => (string) $ability,
				'summary' => $summary,
				'status' => sanitize_key( (string) $status ),
			),
		);
	}

	private static function is_audited_registry_result( $value ) {
		return is_array( $value )
			&& ! empty( $value['__mad4b_registry_mutation_v1'] )
			&& array_key_exists( 'result', $value )
			&& ! empty( $value['audit'] )
			&& is_array( $value['audit'] );
	}

	private static function registry_snapshot_changed( array $snapshot ) {
		$sentinel = isset( $snapshot['_sentinel'] ) ? (string) $snapshot['_sentinel'] : '__mad4b_context_snapshot_compare_missing__';
		foreach ( array( self::PROFILE_OPTION, self::SOURCES_OPTION, self::ASSETS_OPTION, self::REGISTRY_REVISION_OPTION ) as $name ) {
			if ( ! isset( $snapshot[ $name ] ) || ! is_array( $snapshot[ $name ] ) ) return true;
			$current = get_option( $name, $sentinel );
			$expected = $snapshot[ $name ];
			if ( ! empty( $expected['existed'] ) ) {
				if ( $current !== $expected['value'] ) return true;
			} elseif ( $sentinel !== $current ) {
				return true;
			}
		}
		return false;
	}

	private static function compensate_registry_error( array $snapshot, $operation, $error, $phase ) {
		if ( ! is_wp_error( $error ) ) return $error;
		if ( ! self::registry_snapshot_changed( $snapshot ) ) return $error;

		$restored = self::restore_registry_option_snapshot( $snapshot );
		if ( ! is_wp_error( $restored ) ) {
			if ( 'audit' === (string) $phase ) {
				return new WP_Error(
					'mad4b_context_registry_audit_commit_failed',
					'Context governance mutation was compensated because append-only audit evidence could not be committed.',
					array(
						'operation' => sanitize_key( (string) $operation ),
						'audit_error_code' => $error->get_error_code(),
						'compensated' => true,
					)
				);
			}
			return $error;
		}

		return new WP_Error(
			'mad4b_context_registry_recovery_required',
			'Context registry mutation failed after changing local state and the pre-operation snapshot could not be fully restored.',
			array(
				'operation' => sanitize_key( (string) $operation ),
				'phase' => sanitize_key( (string) $phase ),
				'original_error_code' => $error->get_error_code(),
				'compensation_error_code' => $restored->get_error_code(),
			)
		);
	}

	private static function acquire_registry_lock( $operation ) {
		$operation = sanitize_key( (string) $operation );
		$owner = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : hash( 'sha256', uniqid( 'mad4b-context-lock-', true ) );
		$record = array( 'owner' => $owner, 'operation' => $operation, 'expires_at' => time() + self::REGISTRY_LOCK_TTL );
		if ( add_option( self::REGISTRY_LOCK_OPTION, $record, '', false ) ) return $owner;
		$current = get_option( self::REGISTRY_LOCK_OPTION, array() );
		if ( is_array( $current ) && isset( $current['expires_at'] ) && (int) $current['expires_at'] < time() ) {
			delete_option( self::REGISTRY_LOCK_OPTION );
			if ( add_option( self::REGISTRY_LOCK_OPTION, $record, '', false ) ) return $owner;
		}
		return new WP_Error( 'mad4b_context_registry_busy', 'Context registry is being changed by another governed operation. Retry against the new registry revision.', array( 'operation' => $operation, 'registry_revision' => self::registry_revision() ) );
	}

	private static function release_registry_lock( $owner ) {
		$current = get_option( self::REGISTRY_LOCK_OPTION, array() );
		if ( is_array( $current ) && isset( $current['owner'] ) && hash_equals( (string) $current['owner'], (string) $owner ) ) delete_option( self::REGISTRY_LOCK_OPTION );
	}

	private static function bump_registry_revision() {
		$current = self::registry_revision();
		$next = $current + 1;
		if ( ! self::write_option( self::REGISTRY_REVISION_OPTION, $next ) ) {
			return new WP_Error(
				'mad4b_context_registry_revision_write_failed',
				'Context registry state changed but its monotonic revision could not be persisted.',
				array( 'current_revision' => $current, 'attempted_revision' => $next )
			);
		}
		return $next;
	}

	private static function registry_option_snapshot() {
		$sentinel = '__mad4b_context_snapshot_missing__' . hash( 'sha256', microtime( true ) . '|' . self::registry_revision() );
		$snapshot = array( '_sentinel' => $sentinel );
		foreach ( array( self::PROFILE_OPTION, self::SOURCES_OPTION, self::ASSETS_OPTION, self::REGISTRY_REVISION_OPTION ) as $name ) {
			$value = get_option( $name, $sentinel );
			$snapshot[ $name ] = array(
				'existed' => $sentinel !== $value,
				'value' => $value,
			);
		}
		return $snapshot;
	}

	private static function restore_registry_option_snapshot( array $snapshot ) {
		$failures = array();
		foreach ( array( self::PROFILE_OPTION, self::SOURCES_OPTION, self::ASSETS_OPTION, self::REGISTRY_REVISION_OPTION ) as $name ) {
			if ( ! isset( $snapshot[ $name ] ) || ! is_array( $snapshot[ $name ] ) ) {
				$failures[] = $name . ':snapshot_missing';
				continue;
			}
			$entry = $snapshot[ $name ];
			if ( ! empty( $entry['existed'] ) ) {
				if ( ! self::write_option( $name, $entry['value'] ) ) $failures[] = $name;
			} else {
				$deleted = delete_option( $name );
				if ( ! $deleted && false !== get_option( $name, false ) ) $failures[] = $name;
			}
		}
		return $failures
			? new WP_Error( 'mad4b_context_registry_snapshot_restore_failed', 'Context registry pre-operation snapshot could not be fully restored.', array( 'failures' => $failures ) )
			: true;
	}

	private static function commit_option_changes( array $changes, $error_code, $message ) {
		if ( empty( $changes ) ) return true;
		$sentinel = '__mad4b_context_option_missing__' . hash( 'sha256', implode( '|', array_keys( $changes ) ) . '|' . microtime( true ) );
		$before = array();
		$applied = array();
		foreach ( $changes as $name => $value ) {
			$current = get_option( $name, $sentinel );
			$before[ $name ] = array(
				'existed' => $sentinel !== $current,
				'value' => $current,
			);
			if ( self::write_option( $name, $value ) ) {
				$applied[] = $name;
				continue;
			}

			$rollback_failures = array();
			foreach ( array_reverse( $applied ) as $applied_name ) {
				$restore = $before[ $applied_name ];
				$ok = ! empty( $restore['existed'] )
					? self::write_option( $applied_name, $restore['value'] )
					: delete_option( $applied_name );
				if ( ! $ok && ( ! empty( $restore['existed'] ) ? get_option( $applied_name, $sentinel ) !== $restore['value'] : false !== get_option( $applied_name, false ) ) ) $rollback_failures[] = $applied_name;
			}
			if ( $rollback_failures ) {
				return new WP_Error(
					'mad4b_context_registry_compensation_failed',
					'Context registry persistence failed and the previous option snapshot could not be fully restored.',
					array(
						'failed_option' => $name,
						'rollback_failures' => $rollback_failures,
						'original_error_code' => sanitize_key( (string) $error_code ),
					)
				);
			}
			return new WP_Error( sanitize_key( (string) $error_code ), (string) $message, array( 'failed_option' => $name ) );
		}
		return true;
	}

	private static function refreshed_profile_record( array $assets, array $sources, $verified = true ) {
		$profile = self::profile();
		if ( empty( $profile ) ) return array();
		$profile['context_fingerprint'] = self::context_fingerprint( $assets, $sources );
		$profile['authority_manifest_fingerprint'] = self::authority_manifest_fingerprint( $assets, $sources );
		if ( $verified ) $profile['last_verified_at'] = gmdate( 'c' );
		$profile['updated_at'] = gmdate( 'c' );
		return $profile;
	}

	private static function option_values_equal( $left, $right ) {
		// WordPress stores top-level scalar options as strings. After invalidating
		// the option cache, a persisted integer revision is read back as e.g. "1".
		// Keep structured records type-exact; only compare scalar storage values.
		if ( ( is_scalar( $left ) || null === $left ) && ( is_scalar( $right ) || null === $right ) ) {
			return (string) $left === (string) $right;
		}
		return serialize( $left ) === serialize( $right );
	}

	private static function clear_option_read_cache( $name, $aggressive = false ) {
		if ( ! function_exists( 'wp_cache_delete' ) ) return;
		$name = sanitize_key( (string) $name );
		if ( '' === $name ) return;
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		if ( $aggressive && function_exists( 'wp_cache_flush_group' ) ) wp_cache_flush_group( 'options' );
	}

	private static function write_option( $name, $value ) {
		$name = sanitize_key( (string) $name );
		if ( '' === $name ) return false;

		self::clear_option_read_cache( $name, true );
		$missing = '__mad4b_missing_option__' . hash( 'sha256', $name );
		$current = get_option( $name, $missing );
		if ( $missing !== $current && self::option_values_equal( $current, $value ) ) return true;

		update_option( $name, $value, false );
		self::clear_option_read_cache( $name );
		$readback = get_option( $name, $missing );
		if ( $missing !== $readback && self::option_values_equal( $readback, $value ) ) return true;

		self::clear_option_read_cache( $name, true );
		update_option( $name, $value, false );
		self::clear_option_read_cache( $name, true );
		$readback = get_option( $name, $missing );
		return $missing !== $readback && self::option_values_equal( $readback, $value );
	}
}
