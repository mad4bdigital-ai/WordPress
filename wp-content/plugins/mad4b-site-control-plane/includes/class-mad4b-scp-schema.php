<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Schema {
	const VERSION = 4;
	const OPTION  = 'mad4b_scp_schema_version';

	public static function tables() {
		global $wpdb;
		return array(
			'agents'         => $wpdb->prefix . 'mad4b_scp_agents',
			'subjects'       => $wpdb->prefix . 'mad4b_scp_agent_subjects',
			'grants'         => $wpdb->prefix . 'mad4b_scp_agent_grants',
			'approvals'      => $wpdb->prefix . 'mad4b_scp_approval_tickets',
			'mutations'      => $wpdb->prefix . 'mad4b_scp_mutations',
			'budgets'        => $wpdb->prefix . 'mad4b_scp_agent_budgets',
			'budget_windows' => $wpdb->prefix . 'mad4b_scp_agent_budget_windows',
			'audit_events'   => $wpdb->prefix . 'mad4b_scp_audit_events',
			'audit_heads'    => $wpdb->prefix . 'mad4b_scp_audit_heads',
		);
	}

	public static function install_or_upgrade() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$t = self::tables();

		$sql = array();
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
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY ticket_id (ticket_id),
			KEY agent_status_expiry (agent_id,status,expires_at),
			KEY payload_status (payload_sha256,status)
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

		foreach ( $sql as $statement ) dbDelta( $statement );
		if ( ! self::is_ready() ) return new WP_Error( 'mad4b_governance_schema_unavailable', 'MAD4B governance schema is incomplete after migration.' );
		update_option( self::OPTION, self::VERSION, false );
		return true;
	}

	public static function is_ready() {
		global $wpdb;
		foreach ( self::tables() as $table ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			if ( $found !== $table ) return false;
		}
		return true;
	}

	public static function status() {
		return array(
			'expected_version' => self::VERSION,
			'installed_version' => (int) get_option( self::OPTION, 0 ),
			'ready' => self::is_ready(),
			'tables' => self::tables(),
		);
	}
}
