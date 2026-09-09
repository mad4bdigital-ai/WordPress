<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Zero-touch Staging bootstrap for MAD4B Skills.
 *
 * Staging authoring and the known Staging OpenAI App mapping are configured
 * automatically. Explicit operator configuration always wins, Production is
 * never auto-enabled, and scripts remain separately gated.
 */
final class MAD4B_SCP_Skill_Autoconfig {
	const CONTRACT = 'mad4b.skill-autoconfig.v1';
	const STAGING_OPENAI_APP_ID = 'plugin_asdk_app_6aa05fa2f97481919c24b99855fadba2';

	private static $bootstrapped = false;
	private static $status = array();

	public static function bootstrap() {
		if ( self::$bootstrapped ) return self::$status;
		self::$bootstrapped = true;

		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
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

	private static function configure_app_mapping() {
		if ( defined( 'MAD4B_OPENAI_PLUGIN_APP_ID' ) ) {
			$value = trim( (string) constant( 'MAD4B_OPENAI_PLUGIN_APP_ID' ) );
			self::$status['app_mapping_source'] = 'explicit';
			self::$status['app_mapping_configured'] = (bool) preg_match( '/^plugin_asdk_app_[A-Za-z0-9]+$/', $value );
			self::$status['app_mapping_matches_staging'] = self::$status['app_mapping_configured'] && hash_equals( self::STAGING_OPENAI_APP_ID, $value );
			if ( ! self::$status['app_mapping_configured'] && '' === self::$status['blocker'] ) self::$status['blocker'] = 'explicit_app_mapping_invalid';
			return;
		}

		define( 'MAD4B_OPENAI_PLUGIN_APP_ID', self::STAGING_OPENAI_APP_ID );
		self::$status['app_mapping_configured'] = true;
		self::$status['app_mapping_source'] = 'staging_auto';
		self::$status['app_mapping_matches_staging'] = true;
	}
}
