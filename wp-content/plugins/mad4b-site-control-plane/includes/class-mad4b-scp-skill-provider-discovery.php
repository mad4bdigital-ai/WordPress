<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Zero-touch provider Skill discovery for Staging.
 *
 * Installed/active provider discovery is read-only. This class only manages
 * Skill files that MAD4B itself provisioned; administrator-authored Skills are
 * never overwritten, disabled, moved, or deleted. Production remains fail-closed.
 */
final class MAD4B_SCP_Skill_Provider_Discovery {
	const CONTRACT = 'mad4b.skill-provider-discovery.v1';
	const CATALOG_CONTRACT = 'mad4b.skill-provider-catalog.v1';
	const OPTION = 'mad4b_scp_skill_provider_discovery_v1';
	const MAX_PACKS = 100;

	private static $ran = false;
	private static $catalog = null;

	public static function bootstrap() {
		if ( self::$ran ) return self::status();
		self::$ran = true;

		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
		if ( 'staging' !== $environment ) return self::status( 'environment_not_staging' );
		if ( ! MAD4B_SCP_Skill_Registry::editor_enabled() ) return self::status( 'skill_editor_disabled' );
		if ( ! class_exists( 'MAD4B_SCP_Plugin_Discovery' ) ) return self::status( 'plugin_discovery_unavailable' );

		$audit_status = class_exists( 'MAD4B_SCP_Audit' ) ? MAD4B_SCP_Audit::storage_status() : array( 'ready' => false );
		if ( empty( $audit_status['ready'] ) ) return self::status( 'audit_unavailable' );

		$catalog = self::catalog();
		$packs = isset( $catalog['packs'] ) && is_array( $catalog['packs'] ) ? $catalog['packs'] : array();
		if ( empty( $packs ) ) return self::status( 'catalog_empty' );

		$coverage = MAD4B_SCP_Plugin_Discovery::coverage();
		$providers = self::provider_state_map( isset( $coverage['plugins'] ) && is_array( $coverage['plugins'] ) ? $coverage['plugins'] : array() );
		$created = array();
		$activated = array();
		$deactivated = array();
		$skipped_user_owned = array();
		$skipped_conflict = array();
		$families = array();
		$processed = 0;

		foreach ( $packs as $family => $definitions ) {
			if ( $processed >= self::MAX_PACKS ) break;
			$family = sanitize_key( (string) $family );
			if ( '' === $family || ! is_array( $definitions ) ) continue;
			++$processed;

			$provider = isset( $providers[ $family ] ) ? $providers[ $family ] : null;
			$active = is_array( $provider ) && ! empty( $provider['active'] );
			$adapter_ready = $active && ! empty( $provider['adapter_registered'] ) && ! empty( $provider['adapter_runtime_available'] );
			$desired_enabled = $active && $adapter_ready;
			$reason = ! $active ? 'provider_inactive' : ( $adapter_ready ? 'provider_active_adapter_ready' : 'provider_adapter_unavailable' );

			$families[ $family ] = array(
				'active' => $active,
				'adapter_ready' => $adapter_ready,
				'coverage_state' => is_array( $provider ) && isset( $provider['coverage_state'] ) ? sanitize_key( (string) $provider['coverage_state'] ) : 'not_installed',
				'desired_enabled' => $desired_enabled,
			);

			foreach ( array_slice( $definitions, 0, 20 ) as $definition ) {
				if ( ! is_array( $definition ) ) continue;
				$result = self::reconcile_definition( $family, $definition, $desired_enabled, $reason );
				if ( is_wp_error( $result ) ) {
					$skipped_conflict[] = $family . ':' . $result->get_error_code();
					continue;
				}
				if ( ! empty( $result['created'] ) ) $created[] = $result['logical_id'];
				if ( ! empty( $result['activated'] ) ) $activated[] = $result['logical_id'];
				if ( ! empty( $result['deactivated'] ) ) $deactivated[] = $result['logical_id'];
				if ( ! empty( $result['user_owned'] ) ) $skipped_user_owned[] = $result['logical_id'];
				if ( ! empty( $result['conflict'] ) ) $skipped_conflict[] = $result['logical_id'];
			}
		}

		$record = array(
			'contract' => self::CONTRACT,
			'state' => 'ready',
			'families' => $families,
			'created' => array_values( array_unique( $created ) ),
			'activated' => array_values( array_unique( $activated ) ),
			'deactivated' => array_values( array_unique( $deactivated ) ),
			'skipped_user_owned' => array_values( array_unique( $skipped_user_owned ) ),
			'skipped_conflict' => array_values( array_unique( $skipped_conflict ) ),
			'updated_at' => gmdate( 'c' ),
			'production_auto_provision' => false,
			'provider_plugin_mutation' => false,
			'deletes_skills' => false,
		);
		update_option( self::OPTION, $record, false );
		return $record;
	}

	public static function status( $state = '' ) {
		$stored = get_option( self::OPTION, array() );
		if ( '' === $state && is_array( $stored ) && ! empty( $stored['state'] ) ) return $stored;
		return array(
			'contract' => self::CONTRACT,
			'state' => '' !== $state ? $state : 'pending',
			'families' => array(),
			'created' => array(),
			'activated' => array(),
			'deactivated' => array(),
			'skipped_user_owned' => array(),
			'skipped_conflict' => array(),
			'production_auto_provision' => false,
			'provider_plugin_mutation' => false,
			'deletes_skills' => false,
		);
	}

	public static function catalog() {
		if ( null !== self::$catalog ) return self::$catalog;
		$path = MAD4B_SCP_DIR . 'config/skill-provider-catalog.json';
		$data = array();
		if ( is_readable( $path ) ) {
			$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$decoded = false === $raw ? null : json_decode( $raw, true );
			if ( is_array( $decoded ) && isset( $decoded['contract'] ) && self::CATALOG_CONTRACT === $decoded['contract'] ) $data = $decoded;
		}
		$data = apply_filters( 'mad4b_scp_skill_provider_catalog', $data );
		self::$catalog = is_array( $data ) ? $data : array();
		return self::$catalog;
	}

	private static function provider_state_map( array $plugins ) {
		$out = array();
		foreach ( $plugins as $plugin ) {
			if ( ! is_array( $plugin ) || empty( $plugin['family'] ) ) continue;
			$family = sanitize_key( (string) $plugin['family'] );
			if ( '' === $family || 'unknown' === $family ) continue;
			if ( ! isset( $out[ $family ] ) ) {
				$out[ $family ] = array(
					'active' => false,
					'adapter_registered' => false,
					'adapter_runtime_available' => false,
					'coverage_state' => isset( $plugin['coverage_state'] ) ? sanitize_key( (string) $plugin['coverage_state'] ) : '',
				);
			}
			$out[ $family ]['active'] = $out[ $family ]['active'] || ! empty( $plugin['active'] );
			$out[ $family ]['adapter_registered'] = $out[ $family ]['adapter_registered'] || ! empty( $plugin['adapter_registered'] );
			$out[ $family ]['adapter_runtime_available'] = $out[ $family ]['adapter_runtime_available'] || ! empty( $plugin['adapter_runtime_available'] );
			if ( ! empty( $plugin['active'] ) && isset( $plugin['coverage_state'] ) ) $out[ $family ]['coverage_state'] = sanitize_key( (string) $plugin['coverage_state'] );
		}
		return $out;
	}

	private static function reconcile_definition( $family, array $definition, $desired_enabled, $reason ) {
		$level = isset( $definition['level'] ) ? sanitize_key( (string) $definition['level'] ) : '';
		$target = isset( $definition['target'] ) ? sanitize_key( (string) $definition['target'] ) : '';
		$name = isset( $definition['name'] ) ? sanitize_key( (string) $definition['name'] ) : '';
		$description = isset( $definition['description'] ) ? trim( (string) $definition['description'] ) : '';
		$body = isset( $definition['body'] ) ? trim( (string) $definition['body'] ) : '';
		if ( ! in_array( $level, MAD4B_SCP_Skill_Registry::levels(), true ) ) return new WP_Error( 'invalid_level', 'Provider Skill level is invalid.' );
		if ( '' === $target || $target !== sanitize_key( $target ) ) return new WP_Error( 'invalid_target', 'Provider Skill target is invalid.' );
		if ( '' === $name || ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $name ) ) return new WP_Error( 'invalid_name', 'Provider Skill name is invalid.' );
		if ( '' === $description || strlen( $description ) > 2000 || '' === $body ) return new WP_Error( 'invalid_document', 'Provider Skill document is invalid.' );

		$root = MAD4B_SCP_Skill_Registry::storage_root();
		if ( '' === $root || ( ! is_dir( $root ) && ! wp_mkdir_p( $root ) ) ) return new WP_Error( 'storage_unavailable', 'Skill storage is unavailable.' );
		$dir = wp_normalize_path( $root . '/' . $level . '/' . $target . '/' . $name );
		$file = $dir . '/SKILL.md';
		$meta_file = $dir . '/' . MAD4B_SCP_Skill_Registry::META_FILE;
		$logical_id = $level . ':' . $target . ':' . $name;

		if ( is_file( $file ) ) return self::reconcile_existing( $family, $logical_id, $meta_file, $desired_enabled, $reason );
		if ( ! $desired_enabled ) return array( 'logical_id' => $logical_id, 'created' => false, 'activated' => false, 'deactivated' => false, 'user_owned' => false, 'conflict' => false );

		foreach ( MAD4B_SCP_Skill_Registry::list_skills() as $existing ) {
			if ( isset( $existing['name'] ) && $name === $existing['name'] ) return array( 'logical_id' => $logical_id, 'conflict' => true );
		}

		if ( ! wp_mkdir_p( $dir ) ) return new WP_Error( 'directory_failed', 'Unable to create provider Skill directory.' );
		if ( ! self::within_root( $root, $dir ) ) return new WP_Error( 'path_escape_denied', 'Provider Skill path escaped managed storage.' );

		$document = "---\nname: " . $name . "\ndescription: " . self::yaml_scalar( $description ) . "\n---\n\n" . $body . "\n";
		if ( strlen( $document ) > MAD4B_SCP_Skill_Registry::MAX_SKILL_BYTES || false !== strpos( $document, "\0" ) ) return new WP_Error( 'document_too_large', 'Provider Skill document is invalid.' );
		$sha = hash( 'sha256', $document );
		$meta = array(
			'contract' => MAD4B_SCP_Skill_Registry::CONTRACT,
			'level' => $level,
			'target' => $target,
			'name' => $name,
			'enabled' => true,
			'sha256' => $sha,
			'previous_sha256' => '',
			'updated_at' => gmdate( 'c' ),
			'updated_by' => 0,
			'provisioned_by' => self::CONTRACT,
			'provider_family' => $family,
			'provider_activation_reason' => $reason,
		);

		$written = self::atomic_write( $file, $document );
		if ( is_wp_error( $written ) ) return $written;
		$written_meta = self::atomic_write( $meta_file, wp_json_encode( $meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
		if ( is_wp_error( $written_meta ) ) { @unlink( $file ); return $written_meta; }
		$audit = MAD4B_SCP_Audit::record( 'mad4b/skill-provider-autoprovision', array( 'logical_id' => $logical_id, 'provider_family' => $family, 'enabled' => true, 'after_sha256' => $sha ), 'ok' );
		if ( is_wp_error( $audit ) ) { @unlink( $file ); @unlink( $meta_file ); return new WP_Error( 'audit_failed', 'Provider Skill provisioning rolled back because audit commit failed.' ); }
		return array( 'logical_id' => $logical_id, 'created' => true, 'activated' => true, 'deactivated' => false, 'user_owned' => false, 'conflict' => false );
	}

	private static function reconcile_existing( $family, $logical_id, $meta_file, $desired_enabled, $reason ) {
		if ( ! is_file( $meta_file ) || is_link( $meta_file ) ) return array( 'logical_id' => $logical_id, 'user_owned' => true );
		$raw = file_get_contents( $meta_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$meta = false === $raw ? null : json_decode( $raw, true );
		if ( ! is_array( $meta ) ) return array( 'logical_id' => $logical_id, 'user_owned' => true );
		$owner = isset( $meta['provisioned_by'] ) ? (string) $meta['provisioned_by'] : '';
		if ( ! in_array( $owner, array( self::CONTRACT, 'mad4b.skill-seeder.v1' ), true ) ) return array( 'logical_id' => $logical_id, 'user_owned' => true );
		$current = ! empty( $meta['enabled'] );
		if ( $current === (bool) $desired_enabled ) return array( 'logical_id' => $logical_id, 'created' => false, 'activated' => false, 'deactivated' => false, 'user_owned' => false, 'conflict' => false );

		$before = $raw;
		$meta['enabled'] = (bool) $desired_enabled;
		$meta['updated_at'] = gmdate( 'c' );
		$meta['updated_by'] = 0;
		$meta['provider_family'] = $family;
		$meta['provider_activation_reason'] = $reason;
		$meta['provider_managed_by'] = self::CONTRACT;
		$written = self::atomic_write( $meta_file, wp_json_encode( $meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
		if ( is_wp_error( $written ) ) return $written;
		$audit = MAD4B_SCP_Audit::record( 'mad4b/skill-provider-activation', array( 'logical_id' => $logical_id, 'provider_family' => $family, 'before_enabled' => $current, 'after_enabled' => (bool) $desired_enabled, 'reason' => $reason ), 'ok' );
		if ( is_wp_error( $audit ) ) { self::atomic_write( $meta_file, $before ); return new WP_Error( 'audit_failed', 'Provider Skill activation change rolled back because audit commit failed.' ); }
		return array( 'logical_id' => $logical_id, 'created' => false, 'activated' => (bool) $desired_enabled, 'deactivated' => ! $desired_enabled, 'user_owned' => false, 'conflict' => false );
	}

	private static function within_root( $root, $dir ) {
		$root_real = realpath( $root );
		$dir_real = realpath( $dir );
		if ( false === $root_real || false === $dir_real ) return false;
		$root_real = trailingslashit( wp_normalize_path( $root_real ) );
		$dir_real = trailingslashit( wp_normalize_path( $dir_real ) );
		return 0 === strpos( $dir_real, $root_real );
	}

	private static function atomic_write( $file, $content ) {
		$tmp = $file . '.tmp-' . wp_generate_uuid4();
		$bytes = file_put_contents( $tmp, $content, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === $bytes ) return new WP_Error( 'write_failed', 'Unable to write provider Skill state.' );
		if ( ! @rename( $tmp, $file ) ) { @unlink( $tmp ); return new WP_Error( 'replace_failed', 'Unable to atomically publish provider Skill state.' ); }
		return true;
	}

	private static function yaml_scalar( $value ) {
		$value = str_replace( array( "\r", "\n" ), ' ', trim( (string) $value ) );
		return '"' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $value ) . '"';
	}
}
