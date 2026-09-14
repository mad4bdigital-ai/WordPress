<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Tenant-neutral Skill editor and ChatGPT App bootstrap.
 *
 * Installation is not configuration. Automatic Staging setup is available only
 * after an exact Site Profile is enrolled for the current origin/environment,
 * the Skills feature is enabled, and the profile carries a valid ChatGPT App
 * ID. Legacy deployments continue to work through the Site Profile preset
 * migration layer rather than product-specific constants in this runtime.
 */
final class MAD4B_SCP_Skill_Autoconfig {
	const CONTRACT = 'mad4b.skill-autoconfig.v2';

	private static $bootstrapped = false;
	private static $status = array();

	public static function bootstrap() {
		if ( self::$bootstrapped ) return self::$status;
		self::$bootstrapped = true;

		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
		$host = self::current_host();
		$profile_configured = class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::configured();
		$profile = $profile_configured ? MAD4B_SCP_Site_Profile::status() : array();
		$expected_host = $profile_configured && class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::site_host() : '';
		$app_id = $profile_configured && class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::chatgpt_app_id() : '';

		self::$status = array(
			'contract' => self::CONTRACT,
			'environment' => $environment,
			'eligible' => false,
			'configured' => false,
			'configuration_source' => 'none',
			'blocker' => '',
			'production_auto_enable' => false,
			'scripts_auto_enable' => false,
			'app_mapping_configured' => false,
			'app_mapping_source' => 'none',
			'app_mapping_matches_profile' => false,
			'app_mapping_origin_bound' => false,
			'profile_enrolled' => $profile_configured,
			'profile_revision' => ! empty( $profile['revision'] ) ? (int) $profile['revision'] : 0,
			'profile_digest' => ! empty( $profile['profile_digest'] ) ? (string) $profile['profile_digest'] : '',
			'site_uuid' => ! empty( $profile['site_uuid'] ) ? (string) $profile['site_uuid'] : '',
			'expected_profile_host' => $expected_host,
			'observed_host' => $host,
			// Compatibility aliases for older diagnostics. They now mean profile-bound,
			// not a hard-coded deployment-specific Staging App/host.
			'app_mapping_matches_staging' => false,
			'expected_staging_host' => $expected_host,
		);

		if ( 'staging' !== $environment ) {
			self::$status['blocker'] = 'environment_not_staging';
			return self::$status;
		}
		if ( ! $profile_configured ) {
			self::$status['blocker'] = 'site_profile_unconfigured';
			return self::$status;
		}
		if ( empty( $profile['origin_match'] ) || empty( $profile['environment_match'] ) ) {
			self::$status['blocker'] = ! empty( $profile['blockers'] ) ? (string) reset( $profile['blockers'] ) : 'site_profile_not_ready';
			return self::$status;
		}
		if ( empty( $profile['skills_enabled'] ) ) {
			self::$status['blocker'] = 'site_profile_skills_disabled';
			self::$status['configuration_source'] = 'site_profile_disable';
			return self::$status;
		}
		if ( '' === $app_id ) {
			self::$status['blocker'] = 'site_profile_app_mapping_missing';
			return self::$status;
		}

		self::$status['eligible'] = true;
		self::$status['app_mapping_origin_bound'] = true;

		if ( defined( 'MAD4B_SKILLS_EDITOR_ENABLED' ) ) {
			if ( true !== constant( 'MAD4B_SKILLS_EDITOR_ENABLED' ) ) {
				self::$status['blocker'] = 'explicit_editor_disabled';
				self::$status['configuration_source'] = 'explicit_disable';
				self::configure_app_mapping( $app_id );
				return self::$status;
			}
			self::$status['configured'] = true;
			self::$status['configuration_source'] = 'explicit_enable';
		} else {
			define( 'MAD4B_SKILLS_EDITOR_ENABLED', true );
			self::$status['configured'] = true;
			self::$status['configuration_source'] = 'site_profile_staging_auto';
		}

		self::configure_app_mapping( $app_id );
		return self::$status;
	}

	public static function status() {
		return self::$bootstrapped ? self::$status : self::bootstrap();
	}

	/** Compatibility accessor; the authoritative value is the enrolled profile. */
	public static function staging_app_id() {
		return class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::configured()
			? MAD4B_SCP_Site_Profile::chatgpt_app_id()
			: '';
	}

	/** Compatibility accessor; the authoritative value is the enrolled profile. */
	public static function staging_app_host() {
		return class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::configured()
			? MAD4B_SCP_Site_Profile::site_host()
			: '';
	}

	private static function configure_app_mapping( $profile_app_id ) {
		$profile_app_id = trim( (string) $profile_app_id );
		if ( '' === $profile_app_id || ! preg_match( '/^plugin_asdk_app_[A-Za-z0-9]+$/', $profile_app_id ) ) {
			if ( '' === self::$status['blocker'] ) self::$status['blocker'] = 'site_profile_app_mapping_missing';
			return;
		}

		if ( defined( 'MAD4B_OPENAI_PLUGIN_APP_ID' ) ) {
			$value = trim( (string) constant( 'MAD4B_OPENAI_PLUGIN_APP_ID' ) );
			self::$status['app_mapping_source'] = 'explicit';
			self::$status['app_mapping_configured'] = (bool) preg_match( '/^plugin_asdk_app_[A-Za-z0-9]+$/', $value );
			$matches = self::$status['app_mapping_configured'] && hash_equals( $profile_app_id, $value );
			self::$status['app_mapping_matches_profile'] = $matches;
			self::$status['app_mapping_matches_staging'] = $matches;
			if ( ! self::$status['app_mapping_configured'] && '' === self::$status['blocker'] ) self::$status['blocker'] = 'explicit_app_mapping_invalid';
			elseif ( ! $matches && '' === self::$status['blocker'] ) self::$status['blocker'] = 'explicit_app_mapping_profile_mismatch';
			return;
		}

		define( 'MAD4B_OPENAI_PLUGIN_APP_ID', $profile_app_id );
		self::$status['app_mapping_configured'] = true;
		self::$status['app_mapping_source'] = 'site_profile';
		self::$status['app_mapping_matches_profile'] = true;
		self::$status['app_mapping_matches_staging'] = true;
	}

	private static function current_host() {
		if ( ! function_exists( 'home_url' ) ) return '';
		$url = home_url( '/' );
		$host = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url, PHP_URL_HOST ) : parse_url( $url, PHP_URL_HOST );
		$host = is_string( $host ) ? strtolower( trim( $host ) ) : '';
		return preg_match( '/^[a-z0-9.-]+$/', $host ) ? $host : '';
	}
}
