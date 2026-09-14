<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Skills bootstrap with tenant-neutral App binding.
 *
 * Explicit operator configuration always wins. Enrolled generic sites read the
 * OpenAI App ID from MAD4B_SCP_Site_Profile. The historical ETG Staging App
 * remains a compatibility fallback only while no Site Profile is enrolled.
 */
final class MAD4B_SCP_Skill_Autoconfig {
	const CONTRACT = 'mad4b.skill-autoconfig.v1';
	const STAGING_OPENAI_APP_ID = 'plugin_asdk_app_6aa05fa2f97481919c24b99855fadba2';
	const STAGING_OPENAI_APP_HOST = 'staging.egypttourgates.com';

	private static $bootstrapped = false;
	private static $status = array();

	public static function bootstrap() {
		if ( self::$bootstrapped ) return self::$status;
		self::$bootstrapped = true;

		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
		$host = self::current_host();
		$profile_enrolled = class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::configured();
		$profile = $profile_enrolled ? MAD4B_SCP_Site_Profile::status() : array();
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
			'app_mapping_matches_staging' => false,
			'app_mapping_origin_bound' => false,
			'profile_enrolled' => $profile_enrolled,
			'profile_revision' => ! empty( $profile['revision'] ) ? (int) $profile['revision'] : 0,
			'profile_digest' => ! empty( $profile['profile_digest'] ) ? (string) $profile['profile_digest'] : '',
			'expected_staging_host' => self::STAGING_OPENAI_APP_HOST,
			'observed_host' => $host,
		);

		if ( 'staging' !== $environment ) {
			self::$status['blocker'] = 'environment_not_staging';
			return self::$status;
		}
		if ( $profile_enrolled && ( empty( $profile['origin_match'] ) || empty( $profile['environment_match'] ) ) ) {
			self::$status['blocker'] = ! empty( $profile['blockers'] ) ? (string) reset( $profile['blockers'] ) : 'site_profile_not_ready';
			return self::$status;
		}
		self::$status['eligible'] = true;

		if ( defined( 'MAD4B_SKILLS_EDITOR_ENABLED' ) ) {
			if ( true !== constant( 'MAD4B_SKILLS_EDITOR_ENABLED' ) ) {
				self::$status['blocker'] = 'explicit_editor_disabled';
				self::$status['configuration_source'] = 'explicit_disable';
				self::configure_app_mapping( $profile );
				return self::$status;
			}
			self::$status['configured'] = true;
			self::$status['configuration_source'] = 'explicit_enable';
		} else {
			if ( $profile_enrolled && empty( $profile['skills_enabled'] ) ) {
				self::$status['blocker'] = 'site_profile_skills_disabled';
				self::$status['configuration_source'] = 'site_profile_disable';
				return self::$status;
			}
			define( 'MAD4B_SKILLS_EDITOR_ENABLED', true );
			self::$status['configured'] = true;
			self::$status['configuration_source'] = $profile_enrolled ? 'site_profile_staging_auto' : 'staging_auto';
		}

		self::configure_app_mapping( $profile );
		return self::$status;
	}

	public static function status() {
		return self::$bootstrapped ? self::$status : self::bootstrap();
	}

	public static function staging_app_id() {
		if ( class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::configured() ) {
			$app_id = MAD4B_SCP_Site_Profile::chatgpt_app_id();
			if ( '' !== $app_id ) return $app_id;
		}
		return self::STAGING_OPENAI_APP_ID;
	}

	public static function staging_app_host() {
		if ( class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::configured() ) {
			$profile = MAD4B_SCP_Site_Profile::status();
			if ( ! empty( $profile['canonical_origin'] ) ) {
				$parts = wp_parse_url( $profile['canonical_origin'] );
				if ( is_array( $parts ) && ! empty( $parts['host'] ) ) return strtolower( rtrim( (string) $parts['host'], '.' ) );
			}
		}
		return self::STAGING_OPENAI_APP_HOST;
	}

	private static function configure_app_mapping( array $profile = array() ) {
		$profile_enrolled = ! empty( self::$status['profile_enrolled'] );
		$profile_app_id = $profile_enrolled && class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::chatgpt_app_id() : '';
		$profile_origin_bound = $profile_enrolled && ! empty( $profile['origin_match'] ) && ! empty( $profile['environment_match'] );
		$legacy_host_matches = '' !== self::$status['observed_host'] && hash_equals( self::STAGING_OPENAI_APP_HOST, self::$status['observed_host'] );
		self::$status['app_mapping_origin_bound'] = $profile_enrolled ? $profile_origin_bound : $legacy_host_matches;

		if ( defined( 'MAD4B_OPENAI_PLUGIN_APP_ID' ) ) {
			$value = trim( (string) constant( 'MAD4B_OPENAI_PLUGIN_APP_ID' ) );
			self::$status['app_mapping_source'] = 'explicit';
			self::$status['app_mapping_configured'] = (bool) preg_match( '/^plugin_asdk_app_[A-Za-z0-9]+$/', $value );
			self::$status['app_mapping_matches_staging'] = self::$status['app_mapping_configured'] && ( $profile_enrolled ? ( '' !== $profile_app_id && hash_equals( $profile_app_id, $value ) ) : hash_equals( self::STAGING_OPENAI_APP_ID, $value ) );
			if ( ! self::$status['app_mapping_configured'] && '' === self::$status['blocker'] ) self::$status['blocker'] = 'explicit_app_mapping_invalid';
			return;
		}

		if ( $profile_enrolled ) {
			if ( ! $profile_origin_bound ) {
				if ( '' === self::$status['blocker'] ) self::$status['blocker'] = 'site_profile_origin_mismatch';
				return;
			}
			if ( '' === $profile_app_id ) {
				if ( '' === self::$status['blocker'] ) self::$status['blocker'] = 'site_profile_app_mapping_missing';
				return;
			}
			define( 'MAD4B_OPENAI_PLUGIN_APP_ID', $profile_app_id );
			self::$status['app_mapping_configured'] = true;
			self::$status['app_mapping_source'] = 'site_profile';
			self::$status['app_mapping_matches_staging'] = true;
			return;
		}

		if ( ! $legacy_host_matches ) {
			if ( '' === self::$status['blocker'] ) self::$status['blocker'] = 'staging_app_origin_mismatch';
			return;
		}

		define( 'MAD4B_OPENAI_PLUGIN_APP_ID', self::STAGING_OPENAI_APP_ID );
		self::$status['app_mapping_configured'] = true;
		self::$status['app_mapping_source'] = 'staging_auto';
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
