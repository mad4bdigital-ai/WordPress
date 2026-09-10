<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Automatic local runtime certification for the Staging Skill lifecycle.
 *
 * This certifies only facts WordPress can prove locally. It deliberately does
 * not claim that a remote ChatGPT client has installed/refreshed a portable
 * Plugin snapshot, because that is outside the WordPress trust boundary.
 */
final class MAD4B_SCP_Skill_Runtime_Certification {
	const CONTRACT = 'mad4b.skill-runtime-certification.v1';
	const OPTION = 'mad4b_scp_skill_runtime_certification_v1';

	private static $observing = false;

	public static function boot() {
		// One automatic observation at the canonical Abilities lifecycle is enough.
		// Older builds also observed mcp_adapter_init and every admin_init, causing
		// redundant registry/provider/snapshot work on ordinary wp-admin requests.
		// Explicit Ability execution can call observe() when fresh evidence is wanted.
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'observe' ), 99 );
	}

	public static function observe() {
		if ( self::$observing ) return self::status();
		self::$observing = true;
		$result = self::evaluate();
		self::$observing = false;

		if ( ! is_array( $result ) ) return self::status();
		$previous = get_option( self::OPTION, array() );
		$previous_digest = is_array( $previous ) && isset( $previous['evidence_digest'] ) ? (string) $previous['evidence_digest'] : '';
		$current_digest = isset( $result['evidence_digest'] ) ? (string) $result['evidence_digest'] : '';
		if ( '' !== $current_digest && hash_equals( $previous_digest, $current_digest ) ) return $previous;

		$audit_status = class_exists( 'MAD4B_SCP_Audit' ) ? MAD4B_SCP_Audit::storage_status() : array( 'ready' => false );
		if ( empty( $audit_status['ready'] ) ) {
			$result['ready'] = false;
			$result['blockers'][] = 'audit_unavailable';
			$result['blockers'] = array_values( array_unique( $result['blockers'] ) );
			$result['persistence'] = 'not_recorded';
			return $result;
		}

		$audit = MAD4B_SCP_Audit::record(
			'mad4b/skill-runtime-certification',
			array(
				'ready' => ! empty( $result['ready'] ),
				'evidence_digest' => $current_digest,
				'snapshot_identity_token' => isset( $result['snapshot_identity_token'] ) ? $result['snapshot_identity_token'] : '',
				'blockers' => isset( $result['blockers'] ) ? $result['blockers'] : array(),
				'local_runtime_only' => true,
			),
			! empty( $result['ready'] ) ? 'ok' : 'blocked'
		);
		if ( is_wp_error( $audit ) ) {
			$result['ready'] = false;
			$result['blockers'][] = 'audit_commit_failed';
			$result['blockers'] = array_values( array_unique( $result['blockers'] ) );
			$result['persistence'] = 'rolled_back';
			return $result;
		}

		$result['persistence'] = 'recorded';
		update_option( self::OPTION, $result, false );
		return $result;
	}

	public static function status() {
		$stored = get_option( self::OPTION, array() );
		if ( is_array( $stored ) && isset( $stored['contract'] ) && self::CONTRACT === $stored['contract'] ) return $stored;
		return array(
			'contract' => self::CONTRACT,
			'ready' => false,
			'state' => 'pending',
			'blockers' => array( 'runtime_certification_not_observed' ),
			'snapshot_identity_token' => '',
			'local_runtime_only' => true,
			'external_client_snapshot_verified' => false,
			'external_client_action' => 'ChatGPT/Codex must install or refresh the published snapshot outside WordPress, then compare the package MAD4B-SNAPSHOT-ID.txt token with snapshot_identity_token.',
		);
	}

	private static function evaluate() {
		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
		$blockers = array();
		$checks = array();

		$checks['environment_staging'] = 'staging' === $environment;
		if ( ! $checks['environment_staging'] ) $blockers[] = 'environment_not_staging';

		$autoconfig = class_exists( 'MAD4B_SCP_Skill_Autoconfig' ) ? MAD4B_SCP_Skill_Autoconfig::status() : array();
		$checks['editor_enabled'] = class_exists( 'MAD4B_SCP_Skill_Registry' ) && MAD4B_SCP_Skill_Registry::editor_enabled();
		if ( ! $checks['editor_enabled'] ) $blockers[] = 'skill_editor_disabled';
		$checks['scripts_disabled'] = class_exists( 'MAD4B_SCP_Skill_Registry' ) && ! MAD4B_SCP_Skill_Registry::scripts_enabled();
		if ( ! $checks['scripts_disabled'] ) $blockers[] = 'scripts_editor_enabled';

		$registry = class_exists( 'MAD4B_SCP_Skill_Registry' ) ? MAD4B_SCP_Skill_Registry::status() : array();
		$checks['storage_initialized'] = ! empty( $registry['storage_initialized'] );
		$checks['storage_writable'] = ! empty( $registry['storage_writable'] );
		if ( ! $checks['storage_initialized'] ) $blockers[] = 'skill_storage_uninitialized';
		if ( ! $checks['storage_writable'] ) $blockers[] = 'skill_storage_not_writable';

		$expected_app = class_exists( 'MAD4B_SCP_Skill_Autoconfig' ) ? MAD4B_SCP_Skill_Autoconfig::staging_app_id() : '';
		$current_app = class_exists( 'MAD4B_SCP_Skill_Registry' ) ? MAD4B_SCP_Skill_Registry::openai_app_id() : '';
		$checks['staging_app_mapping_bound'] = '' !== $expected_app && '' !== $current_app && hash_equals( $expected_app, $current_app );
		if ( ! $checks['staging_app_mapping_bound'] ) $blockers[] = 'staging_app_mapping_mismatch';

		$seed = class_exists( 'MAD4B_SCP_Skill_Seeder' ) ? MAD4B_SCP_Skill_Seeder::status() : array();
		$checks['seed_pack_ready'] = isset( $seed['state'] ) && 'ready' === $seed['state'];
		if ( ! $checks['seed_pack_ready'] ) $blockers[] = 'seed_pack_not_ready';

		$provider = class_exists( 'MAD4B_SCP_Skill_Provider_Discovery' ) ? MAD4B_SCP_Skill_Provider_Discovery::status() : array();
		$checks['provider_reconciliation_ready'] = isset( $provider['state'] ) && 'ready' === $provider['state'];
		$checks['provider_mutation_absent'] = empty( $provider['provider_plugin_mutation'] );
		$checks['provider_skill_delete_absent'] = empty( $provider['deletes_skills'] );
		if ( ! $checks['provider_reconciliation_ready'] ) $blockers[] = 'provider_reconciliation_not_ready';
		if ( ! $checks['provider_mutation_absent'] ) $blockers[] = 'provider_plugin_mutation_detected';
		if ( ! $checks['provider_skill_delete_absent'] ) $blockers[] = 'provider_skill_delete_detected';

		$base_skills = array(
			array( 'site', '_site', 'wordpress-site-diagnostics' ),
			array( 'connection', 'mad4b-chatgpt', 'wordpress-connection-diagnostics' ),
			array( 'workflow', 'archive-audit', 'wordpress-archive-audit' ),
			array( 'workflow', 'change-safety', 'wordpress-change-safety' ),
		);
		$missing_base = array();
		foreach ( $base_skills as $identity ) {
			$skill = MAD4B_SCP_Skill_Registry::get_skill( $identity[0], $identity[1], $identity[2] );
			if ( is_wp_error( $skill ) || empty( $skill['enabled'] ) ) $missing_base[] = implode( ':', $identity );
		}
		$checks['base_skills_enabled'] = empty( $missing_base );
		if ( ! $checks['base_skills_enabled'] ) $blockers[] = 'base_skills_missing_or_disabled';

		$required_read = array(
			'mad4b/skills-list',
			'mad4b/skill-get',
			'mad4b/skills-export-status',
			'mad4b/skills-runtime-certification',
		);
		$missing_abilities = array();
		if ( ! function_exists( 'wp_has_ability' ) ) {
			$missing_abilities = $required_read;
		} else {
			foreach ( $required_read as $ability ) if ( ! wp_has_ability( $ability ) ) $missing_abilities[] = $ability;
		}
		$checks['read_abilities_registered'] = empty( $missing_abilities );
		if ( ! $checks['read_abilities_registered'] ) $blockers[] = 'skills_read_abilities_incomplete';

		$forbidden = array( 'mad4b/skill-create', 'mad4b/skill-update', 'mad4b/skill-delete', 'mad4b/skill-write' );
		$write_leaks = array();
		if ( function_exists( 'wp_has_ability' ) ) foreach ( $forbidden as $ability ) if ( wp_has_ability( $ability ) ) $write_leaks[] = $ability;
		$checks['skill_write_abilities_absent'] = empty( $write_leaks );
		if ( ! $checks['skill_write_abilities_absent'] ) $blockers[] = 'skill_write_ability_leak';

		$snapshot = class_exists( 'MAD4B_SCP_Skill_Registry' ) ? MAD4B_SCP_Skill_Registry::portable_snapshot() : array();
		$checks['snapshot_has_enabled_skills'] = isset( $snapshot['skill_count'] ) && (int) $snapshot['skill_count'] >= count( $base_skills );
		if ( ! $checks['snapshot_has_enabled_skills'] ) $blockers[] = 'portable_snapshot_empty';

		$snapshot_identity = class_exists( 'MAD4B_SCP_Skill_Snapshot_Identity' ) ? MAD4B_SCP_Skill_Snapshot_Identity::build() : array();
		$checks['snapshot_identity_ready'] = ! empty( $snapshot_identity['ready'] ) && ! empty( $snapshot_identity['snapshot_digest'] ) && ! empty( $snapshot_identity['identity_token'] );
		if ( ! $checks['snapshot_identity_ready'] ) $blockers[] = 'snapshot_identity_unavailable';
		$checks['snapshot_identity_skill_count_matches'] = $checks['snapshot_identity_ready']
			&& isset( $snapshot_identity['skill_count'], $snapshot['skill_count'] )
			&& (int) $snapshot_identity['skill_count'] === (int) $snapshot['skill_count'];
		if ( ! $checks['snapshot_identity_skill_count_matches'] ) $blockers[] = 'snapshot_identity_count_mismatch';

		$identity_token = isset( $snapshot_identity['identity_token'] ) ? (string) $snapshot_identity['identity_token'] : '';
		$evidence = array(
			'environment' => $environment,
			'checks' => $checks,
			'missing_base_skills' => $missing_base,
			'missing_read_abilities' => $missing_abilities,
			'write_ability_leaks' => $write_leaks,
			'app_mapping_source' => isset( $autoconfig['app_mapping_source'] ) ? $autoconfig['app_mapping_source'] : '',
			'provider_families' => isset( $provider['families'] ) ? $provider['families'] : array(),
			'snapshot_skill_count' => isset( $snapshot['skill_count'] ) ? (int) $snapshot['skill_count'] : 0,
			'snapshot_identity_token' => $identity_token,
		);
		$digest = hash( 'sha256', wp_json_encode( $evidence, JSON_UNESCAPED_SLASHES ) );

		return array(
			'contract' => self::CONTRACT,
			'ready' => empty( $blockers ),
			'state' => empty( $blockers ) ? 'ready' : 'blocked',
			'blockers' => array_values( array_unique( $blockers ) ),
			'checks' => $checks,
			'missing_base_skills' => $missing_base,
			'missing_read_abilities' => $missing_abilities,
			'write_ability_leaks' => $write_leaks,
			'provider_families' => isset( $provider['families'] ) ? $provider['families'] : array(),
			'snapshot_skill_count' => isset( $snapshot['skill_count'] ) ? (int) $snapshot['skill_count'] : 0,
			'snapshot_identity_token' => $identity_token,
			'snapshot_digest' => isset( $snapshot_identity['snapshot_digest'] ) ? (string) $snapshot_identity['snapshot_digest'] : '',
			'app_mapping_source' => isset( $autoconfig['app_mapping_source'] ) ? $autoconfig['app_mapping_source'] : '',
			'evidence_digest' => $digest,
			'observed_at' => gmdate( 'c' ),
			'local_runtime_only' => true,
			'external_client_snapshot_verified' => false,
			'external_client_action' => 'ChatGPT/Codex must install or refresh the published snapshot outside WordPress, then compare the package MAD4B-SNAPSHOT-ID.txt token with snapshot_identity_token.',
		);
	}
}
