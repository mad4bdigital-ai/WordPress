<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Schema {
	const VERSION = 9;
	const OPTION  = 'mad4b_scp_schema_version';
	const INTEGRITY_OPTION = 'mad4b_scp_schema_integrity_v9';
	const MIGRATION_CONTRACT = 'mad4b.schema-migration.v1';
	const MIGRATION_ID = '20260924-feature007-durable-execution-v9';
	const MIGRATION_RECEIPT_OPTION = 'mad4b_scp_schema_migration_receipt_v9';
	const LEGACY_BINDINGS_OPTION = 'mad4b_scp_approval_candidate_bindings_v1';

	private static $critical_ready_cache = null;
	private static $physical_status_cache = null;

	public static function tables() {
		global $wpdb;
		return array(
			'agents' => $wpdb->prefix . 'mad4b_scp_agents', 'subjects' => $wpdb->prefix . 'mad4b_scp_agent_subjects', 'grants' => $wpdb->prefix . 'mad4b_scp_agent_grants',
			'approvals' => $wpdb->prefix . 'mad4b_scp_approval_tickets', 'mutations' => $wpdb->prefix . 'mad4b_scp_mutations', 'budgets' => $wpdb->prefix . 'mad4b_scp_agent_budgets',
			'budget_windows' => $wpdb->prefix . 'mad4b_scp_agent_budget_windows', 'audit_events' => $wpdb->prefix . 'mad4b_scp_audit_events', 'audit_heads' => $wpdb->prefix . 'mad4b_scp_audit_heads',
			'content_jobs' => $wpdb->prefix . 'mad4b_content_jobs', 'content_job_events' => $wpdb->prefix . 'mad4b_content_job_events',
			'work_leases' => $wpdb->prefix . 'mad4b_work_leases', 'idempotency' => $wpdb->prefix . 'mad4b_idempotency',
			'outbox' => $wpdb->prefix . 'mad4b_execution_outbox', 'inbox' => $wpdb->prefix . 'mad4b_execution_inbox',
		);
	}

	public static function migration_contract() {
		return array(
			'contract' => self::MIGRATION_CONTRACT,
			'migration_id' => self::MIGRATION_ID,
			'target_schema_version' => self::VERSION,
			'prerequisite_schema_versions' => array( 0, 6, 7, 8, 9 ),
			'forward_operation' => 'dbdelta_additive_mad4b_tables_columns_and_indexes',
			'rollback_or_forward_fix' => 'forward_fix_only_preserve_additive_schema_old_code_ignores_new_surfaces',
			'destructive' => false,
			'expected_locks_downtime' => 'bounded_metadata_ddl_no_maintenance_mode_expected',
			'data_volume_assumption' => 'feature007_durable_tables_new_or_sparse_existing_governance_rows_preserved',
			'preflight_checks' => array(
				'supported_prerequisite_schema_version',
				'wordpress_database_handle_available',
				'nonempty_site_table_prefix',
				'no_future_schema_downgrade',
			),
			'post_migration_verification' => array(
				'deep_physical_integrity_ready',
				'approval_binding_columns_present',
				'durable_columns_present',
				'durable_unique_indexes_present',
				'integrity_token_written_after_verification_only',
			),
			'partial_failure_recovery' => 'target_version_and_integrity_token_not_advanced_until_deep_verification_passes_retry_is_idempotent',
			'mixed_version_compatibility' => 'additive_v9_schema_is_readable_by_previous_v6_runtime_new_surfaces_remain_unused',
			'authority_widening' => false,
		);
	}

	public static function migration_contract_sha256() {
		$encoded = self::stable_json( self::migration_contract() );
		return '' === $encoded ? '' : hash( 'sha256', $encoded );
	}

	public static function migration_preflight_status() {
		global $wpdb;
		$installed = (int) get_option( self::OPTION, 0 );
		$prerequisites = self::migration_contract()['prerequisite_schema_versions'];
		$db_ready = isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'get_charset_collate' );
		$prefix = $db_ready && isset( $wpdb->prefix ) ? (string) $wpdb->prefix : '';
		$supported = in_array( $installed, $prerequisites, true );
		$future_schema = $installed > self::VERSION;
		$blockers = array();
		if ( ! $supported ) $blockers[] = 'unsupported_prerequisite_schema_version';
		if ( $future_schema ) $blockers[] = 'future_schema_downgrade_forbidden';
		if ( ! $db_ready ) $blockers[] = 'wordpress_database_handle_unavailable';
		if ( '' === $prefix ) $blockers[] = 'site_table_prefix_missing';
		return array(
			'contract' => 'mad4b.schema-migration-preflight.v1',
			'migration_id' => self::MIGRATION_ID,
			'installed_version' => $installed,
			'target_version' => self::VERSION,
			'fresh_install' => 0 === $installed,
			'repair_run' => self::VERSION === $installed,
			'contract_sha256' => self::migration_contract_sha256(),
			'blockers' => $blockers,
			'ready' => empty( $blockers ),
			'read_only' => true,
			'mutation_performed' => false,
		);
	}

	private static function migration_receipt( $from_version, array $physical, $readiness_finalized = false ) {
		$physical_json = self::stable_json( $physical );
		return array(
			'contract' => 'mad4b.schema-migration-receipt.v1',
			'migration_id' => self::MIGRATION_ID,
			'from_version' => (int) $from_version,
			'to_version' => self::VERSION,
			'run_type' => 0 === (int) $from_version ? 'fresh_install' : ( self::VERSION === (int) $from_version ? 'repair' : 'upgrade' ),
			'contract_sha256' => self::migration_contract_sha256(),
			'target_integrity_token' => self::expected_integrity_token(),
			'physical_integrity_sha256' => '' === $physical_json ? '' : hash( 'sha256', $physical_json ),
			'physical_verified' => ! empty( $physical['ready'] ),
			'readiness_finalized' => (bool) $readiness_finalized,
			'destructive' => false,
			'authority_widened' => false,
			'completed_at' => gmdate( 'c' ),
		);
	}

	private static function migration_receipt_matches_contract( $receipt, $require_finalized = false ) {
		if ( ! is_array( $receipt ) ) return false;
		if ( 'mad4b.schema-migration-receipt.v1' !== ( isset( $receipt['contract'] ) ? (string) $receipt['contract'] : '' ) ) return false;
		if ( self::MIGRATION_ID !== ( isset( $receipt['migration_id'] ) ? (string) $receipt['migration_id'] : '' ) ) return false;
		if ( self::VERSION !== (int) ( isset( $receipt['to_version'] ) ? $receipt['to_version'] : 0 ) ) return false;
		$from_version = (int) ( isset( $receipt['from_version'] ) ? $receipt['from_version'] : -1 );
		if ( ! in_array( $from_version, self::migration_contract()['prerequisite_schema_versions'], true ) ) return false;
		$expected_run_type = 0 === $from_version ? 'fresh_install' : ( self::VERSION === $from_version ? 'repair' : 'upgrade' );
		if ( $expected_run_type !== ( isset( $receipt['run_type'] ) ? (string) $receipt['run_type'] : '' ) ) return false;
		if ( empty( $receipt['physical_verified'] ) || ( $require_finalized && empty( $receipt['readiness_finalized'] ) ) ) return false;
		if ( ! empty( $receipt['destructive'] ) || ! empty( $receipt['authority_widened'] ) ) return false;
		$contract_sha = isset( $receipt['contract_sha256'] ) ? strtolower( trim( (string) $receipt['contract_sha256'] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $contract_sha ) || ! hash_equals( self::migration_contract_sha256(), $contract_sha ) ) return false;
		$integrity = isset( $receipt['target_integrity_token'] ) ? strtolower( trim( (string) $receipt['target_integrity_token'] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $integrity ) || ! hash_equals( self::expected_integrity_token(), $integrity ) ) return false;
		$physical_sha = isset( $receipt['physical_integrity_sha256'] ) ? strtolower( trim( (string) $receipt['physical_integrity_sha256'] ) ) : '';
		return (bool) preg_match( '/^[a-f0-9]{64}$/', $physical_sha );
	}

	private static function migration_receipt_valid( $receipt = null ) {
		if ( null === $receipt ) $receipt = get_option( self::MIGRATION_RECEIPT_OPTION, array() );
		return self::migration_receipt_matches_contract( $receipt, true );
	}

	private static function migration_origin_version( $installed_version ) {
		$installed_version = (int) $installed_version;
		$receipt = get_option( self::MIGRATION_RECEIPT_OPTION, array() );
		if ( ! self::migration_receipt_matches_contract( $receipt, false ) ) return $installed_version;
		return (int) $receipt['from_version'];
	}

	private static function persist_and_verify_option( $option, $value ) {
		update_option( $option, $value, false );
		$stored = get_option( $option, null );
		if ( is_array( $value ) ) return is_array( $stored ) && self::stable_json( $value ) === self::stable_json( $stored );
		return (string) $stored === (string) $value;
	}

	private static function stable_json( $value ) {
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value, JSON_UNESCAPED_SLASHES ) : json_encode( $value, JSON_UNESCAPED_SLASHES );
		return false === $encoded ? '' : (string) $encoded;
	}

	public static function install_or_upgrade() {
		global $wpdb;
		$preflight = self::migration_preflight_status();
		if ( empty( $preflight['ready'] ) ) {
			return new WP_Error( 'mad4b_schema_migration_preflight_failed', 'MAD4B schema migration preflight failed closed.', $preflight );
		}
		$installed_version = (int) $preflight['installed_version'];
		$from_version = self::migration_origin_version( $installed_version );
		if ( self::VERSION === $installed_version && self::is_ready() ) {
			self::$physical_status_cache = null;
			$existing_physical = self::physical_integrity_status();
			if ( ! empty( $existing_physical['ready'] ) ) {
				self::$critical_ready_cache = true;
				return true;
			}
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$t = self::tables();
		$sql = array();

		// Keep every field and key on its own physical line. WordPress dbDelta()
		// discovers upgrade candidates line-by-line; collapsing definitions makes
		// fresh CREATE work while silently hiding later ADD COLUMN/KEY upgrades.
		$sql[] = "CREATE TABLE {$t['agents']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			public_id char(36) NOT NULL,
			slug varchar(191) NOT NULL,
			label varchar(191) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'disabled',
			wp_user_id bigint(20) unsigned NULL,
			environment varchar(32) NOT NULL DEFAULT 'unknown',
			revision bigint(20) unsigned NOT NULL DEFAULT 1,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY public_id (public_id),
			UNIQUE KEY slug (slug),
			KEY status (status),
			KEY wp_user_id (wp_user_id)
		) $charset;";

		$sql[] = "CREATE TABLE {$t['subjects']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			agent_id bigint(20) unsigned NOT NULL,
			subject_type varchar(64) NOT NULL,
			subject_fingerprint char(64) NOT NULL,
			label varchar(191) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'enabled',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY subject_binding (subject_type,subject_fingerprint),
			KEY agent_status (agent_id,status)
		) $charset;";

		$sql[] = "CREATE TABLE {$t['grants']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			agent_id bigint(20) unsigned NOT NULL,
			effect varchar(10) NOT NULL DEFAULT 'allow',
			server_id varchar(64) NOT NULL,
			ability_name varchar(191) NOT NULL,
			provider varchar(64) NOT NULL DEFAULT 'core',
			resource_schema_version varchar(32) NOT NULL DEFAULT 'v1',
			resource_constraints longtext NULL,
			environment varchar(32) NOT NULL DEFAULT 'all',
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY exact_grant (agent_id,effect,server_id,ability_name,provider,environment),
			KEY agent_ability (agent_id,ability_name),
			KEY server_ability (server_id,ability_name)
		) $charset;";

		$sql[] = "CREATE TABLE {$t['approvals']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			ticket_id char(36) NOT NULL,
			ticket_class varchar(32) NOT NULL,
			agent_id bigint(20) unsigned NOT NULL,
			server_id varchar(64) NOT NULL,
			ability_name varchar(191) NOT NULL,
			provider varchar(64) NOT NULL DEFAULT 'core',
			target_fingerprint varchar(191) NOT NULL DEFAULT '',
			payload_sha256 char(64) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			reason text NOT NULL,
			approved_by bigint(20) unsigned NOT NULL DEFAULT 0,
			approved_at datetime NULL,
			expires_at datetime NOT NULL,
			used_at datetime NULL,
			candidate_binding_contract varchar(64) NOT NULL DEFAULT '',
			candidate_sha char(40) NOT NULL DEFAULT '',
			build_fingerprint char(64) NOT NULL DEFAULT '',
			binding_environment varchar(32) NOT NULL DEFAULT '',
			binding_host varchar(191) NOT NULL DEFAULT '',
			site_uuid char(36) NOT NULL DEFAULT '',
			site_profile_revision bigint(20) unsigned NOT NULL DEFAULT 0,
			site_profile_digest char(64) NOT NULL DEFAULT '',
			bound_at datetime NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY ticket_id (ticket_id),
			KEY agent_status_expiry (agent_id,status,expires_at),
			KEY payload_status (payload_sha256,status),
			KEY decision_inbox (status,expires_at,id),
			KEY candidate_inbox (candidate_sha,build_fingerprint,status,expires_at),
			KEY site_profile_inbox (site_uuid,site_profile_revision,status,expires_at)
		) $charset;";

		$sql[] = "CREATE TABLE {$t['mutations']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			mutation_id char(36) NOT NULL,
			request_id varchar(64) NOT NULL,
			parent_mutation_id char(36) NULL,
			agent_id bigint(20) unsigned NOT NULL,
			subject_type varchar(64) NOT NULL DEFAULT '',
			subject_fingerprint char(64) NOT NULL DEFAULT '',
			wp_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			server_id varchar(64) NOT NULL,
			ability_name varchar(191) NOT NULL,
			provider varchar(64) NOT NULL DEFAULT 'core',
			provider_version varchar(64) NOT NULL DEFAULT '',
			target_type varchar(64) NOT NULL DEFAULT '',
			target_id varchar(191) NOT NULL DEFAULT '',
			approval_ticket_id char(36) NULL,
			impact varchar(20) NOT NULL,
			status varchar(32) NOT NULL,
			reversible tinyint(1) NOT NULL DEFAULT 0,
			before_sha256 char(64) NOT NULL DEFAULT '',
			after_sha256 char(64) NOT NULL DEFAULT '',
			rollback_payload longtext NULL,
			rollback_payload_sha256 char(64) NOT NULL DEFAULT '',
			undo_expires_at datetime NULL,
			verification_code varchar(64) NOT NULL DEFAULT '',
			error_code varchar(64) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY mutation_id (mutation_id),
			KEY agent_created (agent_id,created_at),
			KEY ability_created (ability_name,created_at),
			KEY status_created (status,created_at),
			KEY parent_mutation_id (parent_mutation_id),
			KEY request_id (request_id)
		) $charset;";

		$sql[] = "CREATE TABLE {$t['budgets']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			agent_id bigint(20) unsigned NOT NULL,
			budget_type varchar(32) NOT NULL,
			window_seconds int(10) unsigned NOT NULL,
			max_count int(10) unsigned NOT NULL,
			enabled tinyint(1) NOT NULL DEFAULT 1,
			updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY agent_budget (agent_id,budget_type)
		) $charset;";

		$sql[] = "CREATE TABLE {$t['budget_windows']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			agent_id bigint(20) unsigned NOT NULL,
			budget_type varchar(32) NOT NULL,
			window_start bigint(20) unsigned NOT NULL,
			window_seconds int(10) unsigned NOT NULL,
			used_count bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY agent_budget_window (agent_id,budget_type,window_start),
			KEY window_cleanup (window_start),
			KEY agent_window (agent_id,window_start)
		) $charset;";

		$sql[] = "CREATE TABLE {$t['audit_events']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			chain_name varchar(64) NOT NULL,
			sequence bigint(20) unsigned NOT NULL,
			event_id char(36) NOT NULL,
			occurred_at varchar(32) NOT NULL,
			request_id varchar(100) NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			ability varchar(191) NOT NULL,
			status varchar(32) NOT NULL,
			summary_json longtext NOT NULL,
			previous_hash char(64) NOT NULL,
			entry_hash char(64) NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY chain_sequence (chain_name,sequence),
			UNIQUE KEY event_id (event_id),
			KEY request_id (request_id),
			KEY ability_sequence (ability,sequence),
			KEY entry_hash (entry_hash)
		) $charset;";

		$sql[] = "CREATE TABLE {$t['audit_heads']} (
			chain_name varchar(64) NOT NULL,
			sequence bigint(20) unsigned NOT NULL DEFAULT 0,
			entry_hash char(64) NOT NULL,
			legacy_anchor_sha256 char(64) NOT NULL,
			legacy_chain_valid tinyint(1) NOT NULL DEFAULT 1,
			legacy_entry_count bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (chain_name)
		) $charset;";

		$sql[] = "CREATE TABLE {$t['content_jobs']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_id char(36) NOT NULL,
			tenant_id varchar(191) NOT NULL DEFAULT '',
			site_uuid char(36) NOT NULL,
			brand_id varchar(191) NOT NULL,
			subject text NOT NULL,
			primary_keyword varchar(191) NOT NULL DEFAULT '',
			language varchar(32) NOT NULL,
			country varchar(32) NOT NULL,
			content_type varchar(64) NOT NULL,
			writer_profile_id varchar(191) NOT NULL DEFAULT '',
			writer_profile_version varchar(64) NOT NULL DEFAULT '',
			research_depth varchar(32) NOT NULL DEFAULT 'standard',
			automation_level varchar(32) NOT NULL DEFAULT 'review_gated',
			state varchar(32) NOT NULL,
			stage varchar(64) NOT NULL,
			target_post_type varchar(64) NOT NULL DEFAULT '',
			target_post_id bigint(20) unsigned NULL,
			desired_publish_at datetime NULL,
			current_artifact_id varchar(191) NOT NULL DEFAULT '',
			quality_status varchar(32) NOT NULL DEFAULT 'unknown',
			last_error_code varchar(64) NOT NULL DEFAULT '',
			last_error_summary varchar(500) NOT NULL DEFAULT '',
			job_revision bigint(20) unsigned NOT NULL DEFAULT 1,
			created_by_nhi char(36) NOT NULL DEFAULT '',
			created_by_user bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			completed_at datetime NULL,
			cancelled_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY job_id (job_id),
			KEY site_lifecycle (site_uuid,state,stage),
			KEY brand_market (brand_id,language,country),
			KEY target_post_id (target_post_id),
			KEY updated_at (updated_at)
		) $charset;";

		$sql[] = "CREATE TABLE {$t['content_job_events']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id char(36) NOT NULL,
			job_id char(36) NOT NULL,
			sequence bigint(20) unsigned NOT NULL,
			event_type varchar(64) NOT NULL,
			previous_state varchar(32) NOT NULL DEFAULT '',
			new_state varchar(32) NOT NULL DEFAULT '',
			previous_stage varchar(64) NOT NULL DEFAULT '',
			new_stage varchar(64) NOT NULL DEFAULT '',
			reason_code varchar(64) NOT NULL DEFAULT '',
			correlation_id varchar(100) NOT NULL DEFAULT '',
			actor_type varchar(64) NOT NULL DEFAULT '',
			actor_id varchar(191) NOT NULL DEFAULT '',
			plan_sha256 char(64) NOT NULL DEFAULT '',
			artifact_id varchar(191) NOT NULL DEFAULT '',
			provider_id varchar(64) NOT NULL DEFAULT '',
			metadata_json longtext NULL,
			previous_entry_sha256 char(64) NOT NULL DEFAULT '',
			entry_sha256 char(64) NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_id (event_id),
			UNIQUE KEY job_sequence (job_id,sequence),
			KEY correlation_id (correlation_id),
			KEY created_at (created_at)
		) $charset;";

		$sql[] = "CREATE TABLE {$t['work_leases']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			work_id char(36) NOT NULL,
			aggregate_type varchar(64) NOT NULL,
			aggregate_id varchar(191) NOT NULL,
			worker_id varchar(191) NOT NULL DEFAULT '',
			lease_epoch bigint(20) unsigned NOT NULL DEFAULT 0,
			expected_aggregate_revision bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(32) NOT NULL DEFAULT 'available',
			acquired_at datetime NULL,
			heartbeat_at datetime NULL,
			expires_at datetime NULL,
			reconciliation_ref varchar(191) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY work_id (work_id),
			KEY aggregate_status (aggregate_type,aggregate_id,status),
			KEY lease_expiry (status,expires_at)
		) $charset;";

		$sql[] = "CREATE TABLE {$t['idempotency']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			scope_key char(64) NOT NULL,
			idempotency_key varchar(191) NOT NULL,
			request_sha256 char(64) NOT NULL,
			claim_epoch bigint(20) unsigned NOT NULL DEFAULT 1,
			status varchar(32) NOT NULL DEFAULT 'pending',
			result_json longtext NULL,
			result_sha256 char(64) NOT NULL DEFAULT '',
			reconciliation_ref varchar(191) NOT NULL DEFAULT '',
			expires_at datetime NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY scope_idempotency (scope_key,idempotency_key),
			KEY expiry_status (expires_at,status)
		) $charset;";

		$sql[] = "CREATE TABLE {$t['outbox']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			outbox_id char(36) NOT NULL,
			job_id char(36) NOT NULL,
			expected_job_revision bigint(20) unsigned NOT NULL,
			provider_id varchar(64) NOT NULL,
			capability_id varchar(191) NOT NULL,
			workflow_plan_sha256 char(64) NOT NULL,
			idempotency_key varchar(191) NOT NULL,
			request_sha256 char(64) NOT NULL,
			payload_json longtext NULL,
			status varchar(32) NOT NULL DEFAULT 'pending',
			attempts int(10) unsigned NOT NULL DEFAULT 0,
			provider_execution_ref varchar(191) NOT NULL DEFAULT '',
			last_error_class varchar(64) NOT NULL DEFAULT '',
			available_at datetime NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY outbox_id (outbox_id),
			UNIQUE KEY provider_idempotency (provider_id,idempotency_key),
			KEY delivery_queue (status,available_at,id)
		) $charset;";

		$sql[] = "CREATE TABLE {$t['inbox']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			provider_id varchar(64) NOT NULL,
			provider_event_id varchar(191) NOT NULL,
			job_id char(36) NOT NULL DEFAULT '',
			payload_sha256 char(64) NOT NULL,
			status varchar(32) NOT NULL DEFAULT 'accepted',
			provider_execution_ref varchar(191) NOT NULL DEFAULT '',
			result_ref varchar(191) NOT NULL DEFAULT '',
			received_at datetime NOT NULL,
			processed_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY provider_event (provider_id,provider_event_id),
			KEY job_status (job_id,status),
			KEY received_at (received_at)
		) $charset;";

		foreach ( $sql as $statement ) dbDelta( $statement );
		self::migrate_legacy_candidate_bindings();
		self::$physical_status_cache = null;
		$physical = self::physical_integrity_status();
		if ( empty( $physical['ready'] ) ) {
			return new WP_Error(
				'mad4b_governance_schema_unavailable',
				'MAD4B governance schema is incomplete after migration.',
				array(
					'migration_id' => self::MIGRATION_ID,
					'from_version' => $from_version,
					'target_version' => self::VERSION,
					'contract_sha256' => self::migration_contract_sha256(),
					'physical_integrity' => $physical,
				)
			);
		}
		$physical_receipt = self::migration_receipt( $from_version, $physical, false );
		if ( ! self::persist_and_verify_option( self::MIGRATION_RECEIPT_OPTION, $physical_receipt ) ) {
			return new WP_Error( 'mad4b_schema_migration_receipt_persist_failed', 'MAD4B schema migration physical-verification receipt could not be persisted exactly.', array( 'migration_id' => self::MIGRATION_ID ) );
		}

		$integrity_token = self::expected_integrity_token();
		if ( ! self::persist_and_verify_option( self::OPTION, self::VERSION )
			|| ! self::persist_and_verify_option( self::INTEGRITY_OPTION, $integrity_token ) ) {
			return new WP_Error( 'mad4b_schema_migration_readiness_persist_failed', 'MAD4B schema migration readiness markers could not be persisted exactly.', array( 'migration_id' => self::MIGRATION_ID ) );
		}

		$final_receipt = self::migration_receipt( $from_version, $physical, true );
		if ( ! self::persist_and_verify_option( self::MIGRATION_RECEIPT_OPTION, $final_receipt ) || ! self::migration_receipt_valid() ) {
			return new WP_Error( 'mad4b_schema_migration_final_receipt_failed', 'MAD4B schema migration final receipt could not be verified.', array( 'migration_id' => self::MIGRATION_ID ) );
		}
		self::$critical_ready_cache = true;
		return true;
	}

	public static function is_ready() {
		$version = (int) get_option( self::OPTION, 0 );
		$token = (string) get_option( self::INTEGRITY_OPTION, '' );
		return self::VERSION === $version
			&& '' !== $token
			&& hash_equals( self::expected_integrity_token(), $token )
			&& self::migration_receipt_valid();
	}
	public static function critical_ready() { if ( null !== self::$critical_ready_cache ) return (bool) self::$critical_ready_cache; if ( ! self::is_ready() ) { self::$critical_ready_cache = false; return false; } $status = self::physical_integrity_status(); self::$critical_ready_cache = ! empty( $status['ready'] ); return (bool) self::$critical_ready_cache; }
	public static function physical_integrity_status() {
		if ( null !== self::$physical_status_cache ) return self::$physical_status_cache;
		global $wpdb;
		$missing_tables = array();
		foreach ( self::tables() as $key => $table ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $found !== $table ) $missing_tables[] = $key;
		}
		$missing_columns = array();
		$missing_durable_columns = array();
		$missing_durable_indexes = array();
		if ( empty( $missing_tables ) ) {
			$t = self::tables();
			$columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$t['approvals']}`", 0 );
			$columns = is_array( $columns ) ? array_map( 'strval', $columns ) : array();
			foreach ( self::required_approval_binding_columns() as $column ) if ( ! in_array( $column, $columns, true ) ) $missing_columns[] = $column;
			foreach ( self::required_durable_columns() as $table_key => $required ) {
				$durable_columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$t[ $table_key ]}`", 0 );
				$durable_columns = is_array( $durable_columns ) ? array_map( 'strval', $durable_columns ) : array();
				foreach ( $required as $column ) if ( ! in_array( $column, $durable_columns, true ) ) $missing_durable_columns[] = $table_key . '.' . $column;
			}
			foreach ( self::required_durable_indexes() as $table_key => $required ) {
				$index_rows = $wpdb->get_results( "SHOW INDEX FROM `{$t[ $table_key ]}`", ARRAY_A );
				$index_rows = is_array( $index_rows ) ? $index_rows : array();
				$index_state = array();
				foreach ( $index_rows as $row ) {
					if ( ! is_array( $row ) || empty( $row['Key_name'] ) ) continue;
					$name = (string) $row['Key_name'];
					if ( ! isset( $index_state[ $name ] ) ) $index_state[ $name ] = array( 'unique' => isset( $row['Non_unique'] ) && 0 === (int) $row['Non_unique'] );
				}
				foreach ( $required as $name => $must_be_unique ) {
					if ( ! isset( $index_state[ $name ] ) || ( $must_be_unique && empty( $index_state[ $name ]['unique'] ) ) ) $missing_durable_indexes[] = $table_key . '.' . $name;
				}
			}
		}
		self::$physical_status_cache = array(
			'contract' => 'mad4b.schema-integrity.v4',
			'expected_version' => self::VERSION,
			'missing_tables' => $missing_tables,
			'missing_approval_columns' => $missing_columns,
			'missing_durable_columns' => $missing_durable_columns,
			'missing_durable_indexes' => $missing_durable_indexes,
			'ready' => empty( $missing_tables ) && empty( $missing_columns ) && empty( $missing_durable_columns ) && empty( $missing_durable_indexes ),
		);
		return self::$physical_status_cache;
	}
	public static function status( $deep = false ) {
		$status = array(
			'expected_version' => self::VERSION,
			'installed_version' => (int) get_option( self::OPTION, 0 ),
			'ready' => self::is_ready(),
			'integrity_token_valid' => self::is_ready(),
			'tables' => self::tables(),
			'migration' => array(
				'contract' => self::migration_contract(),
				'contract_sha256' => self::migration_contract_sha256(),
				'preflight' => self::migration_preflight_status(),
				'receipt' => get_option( self::MIGRATION_RECEIPT_OPTION, array() ),
				'receipt_valid' => self::migration_receipt_valid(),
			),
		);
		if ( $deep ) $status['physical_integrity'] = self::physical_integrity_status();
		return $status;
	}
	private static function expected_integrity_token() {
		$durable = array();
		foreach ( self::required_durable_columns() as $table => $columns ) foreach ( $columns as $column ) $durable[] = $table . '.' . $column;
		$indexes = array();
		foreach ( self::required_durable_indexes() as $table => $required ) foreach ( $required as $name => $unique ) $indexes[] = $table . '.' . $name . ':' . ( $unique ? 'unique' : 'index' );
		return hash( 'sha256', 'mad4b-schema-v9|' . implode( '|', array_keys( self::tables() ) ) . '|' . implode( '|', self::required_approval_binding_columns() ) . '|' . implode( '|', $durable ) . '|' . implode( '|', $indexes ) );
	}
	private static function required_approval_binding_columns() { return array( 'candidate_binding_contract', 'candidate_sha', 'build_fingerprint', 'binding_environment', 'binding_host', 'site_uuid', 'site_profile_revision', 'site_profile_digest', 'bound_at' ); }
	private static function required_durable_columns() {
		return array(
			'content_jobs' => array( 'job_id', 'site_uuid', 'state', 'stage', 'current_artifact_id', 'job_revision', 'updated_at' ),
			'content_job_events' => array( 'event_id', 'job_id', 'sequence', 'event_type', 'plan_sha256', 'artifact_id', 'entry_sha256', 'created_at' ),
			'work_leases' => array( 'work_id', 'aggregate_type', 'aggregate_id', 'worker_id', 'lease_epoch', 'expected_aggregate_revision', 'status', 'heartbeat_at', 'expires_at', 'reconciliation_ref' ),
			'idempotency' => array( 'scope_key', 'idempotency_key', 'request_sha256', 'claim_epoch', 'status', 'result_sha256', 'reconciliation_ref', 'expires_at' ),
			'outbox' => array( 'outbox_id', 'job_id', 'expected_job_revision', 'provider_id', 'capability_id', 'workflow_plan_sha256', 'idempotency_key', 'request_sha256', 'status', 'attempts', 'available_at' ),
			'inbox' => array( 'provider_id', 'provider_event_id', 'job_id', 'payload_sha256', 'status', 'provider_execution_ref', 'result_ref', 'received_at' ),
		);
	}
	private static function required_durable_indexes() {
		return array(
			'content_jobs' => array( 'job_id' => true ),
			'content_job_events' => array( 'event_id' => true, 'job_sequence' => true ),
			'work_leases' => array( 'work_id' => true ),
			'idempotency' => array( 'scope_idempotency' => true ),
			'outbox' => array( 'outbox_id' => true, 'provider_idempotency' => true ),
			'inbox' => array( 'provider_event' => true ),
		);
	}

	private static function migrate_legacy_candidate_bindings() {
		global $wpdb; $legacy = get_option( self::LEGACY_BINDINGS_OPTION, array() ); if ( ! is_array( $legacy ) || empty( $legacy ) ) return; $t = self::tables();
		foreach ( array_slice( $legacy, -100, 100, true ) as $ticket_id => $binding ) {
			if ( ! is_array( $binding ) || ! preg_match( '/^[a-f0-9-]{36}$/', (string) $ticket_id ) ) continue;
			$sha = isset( $binding['candidate_sha'] ) ? strtolower( trim( (string) $binding['candidate_sha'] ) ) : ''; $build = isset( $binding['build_fingerprint'] ) ? strtolower( trim( (string) $binding['build_fingerprint'] ) ) : ''; $payload = isset( $binding['payload_sha256'] ) ? strtolower( trim( (string) $binding['payload_sha256'] ) ) : '';
			if ( ! preg_match( '/^[a-f0-9]{40}$/', $sha ) || ! preg_match( '/^[a-f0-9]{64}$/', $build ) || ! preg_match( '/^[a-f0-9]{64}$/', $payload ) ) continue; $bound_at = ! empty( $binding['bound_at'] ) ? strtotime( (string) $binding['bound_at'] ) : false;
			$wpdb->query( $wpdb->prepare( "UPDATE {$t['approvals']} SET candidate_binding_contract=%s,candidate_sha=%s,build_fingerprint=%s,binding_environment=%s,binding_host=%s,bound_at=%s WHERE ticket_id=%s AND payload_sha256=%s AND candidate_binding_contract=''", isset( $binding['contract'] ) ? (string) $binding['contract'] : 'mad4b.approval-candidate-binding.v1', $sha, $build, isset( $binding['environment'] ) ? sanitize_key( (string) $binding['environment'] ) : '', isset( $binding['host'] ) ? strtolower( rtrim( (string) $binding['host'], '.' ) ) : '', false === $bound_at ? gmdate( 'Y-m-d H:i:s' ) : gmdate( 'Y-m-d H:i:s', $bound_at ), (string) $ticket_id, $payload ) );
		}
	}
}
