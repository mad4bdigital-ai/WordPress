<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Zero-touch Staging bootstrap for MAD4B Skills.
 *
 * Staging authoring is configured automatically. The ETG Staging OpenAI App
 * mapping is auto-bound only on the exact governed Staging origin so this
 * package cannot silently bind another WordPress Staging site to the ETG App.
 * Explicit operator configuration always wins, Production is never auto-enabled,
 * and scripts remain separately gated.
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
			'expected_staging_host' => self::STAGING_OPENAI_APP_HOST,
			'observed_host' => $host,
		);

		if ( 'staging' !== $environment ) {
			self::$status['blocker'] = 'environment_not_staging';
			return self::$status;
		}
		self::$status['eligible'] = true;

		// Explicit operator configuration always wins. An explicit false is a
		// deliberate kill-switch and must not be overridden by Staging defaults.
		if ( defined( 'MAD4B_SKILLS_EDITOR_ENABLED' ) ) {
			if ( true !== constant( 'MAD4B_SKILLS_EDITOR_ENABLED' ) ) {
				self::$status['blocker'] = 'explicit_editor_disabled';
				self::$status['configuration_source'] = 'explicit_disable';
				self::configure_app_mapping();
				return self::$status;
			}
			self::$status['configured'] = true;
			self::$status['configuration_source'] = 'explicit_enable';
		} else {
			define( 'MAD4B_SKILLS_EDITOR_ENABLED', true );
			self::$status['configured'] = true;
			self::$status['configuration_source'] = 'staging_auto';
		}

		self::configure_app_mapping();
		return self::$status;
	}

	public static function status() {
		return self::$bootstrapped ? self::$status : self::bootstrap();
	}

	public static function staging_app_id() {
		return self::STAGING_OPENAI_APP_ID;
	}

	public static function staging_app_host() {
		return self::STAGING_OPENAI_APP_HOST;
	}

	private static function configure_app_mapping() {
		$host_matches = '' !== self::$status['observed_host'] && hash_equals( self::STAGING_OPENAI_APP_HOST, self::$status['observed_host'] );
		self::$status['app_mapping_origin_bound'] = $host_matches;

		if ( defined( 'MAD4B_OPENAI_PLUGIN_APP_ID' ) ) {
			$value = trim( (string) constant( 'MAD4B_OPENAI_PLUGIN_APP_ID' ) );
			self::$status['app_mapping_source'] = 'explicit';
			self::$status['app_mapping_configured'] = (bool) preg_match( '/^plugin_asdk_app_[A-Za-z0-9]+$/', $value );
			self::$status['app_mapping_matches_staging'] = self::$status['app_mapping_configured'] && hash_equals( self::STAGING_OPENAI_APP_ID, $value );
			if ( ! self::$status['app_mapping_configured'] && '' === self::$status['blocker'] ) self::$status['blocker'] = 'explicit_app_mapping_invalid';
			return;
		}

		if ( ! $host_matches ) {
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
