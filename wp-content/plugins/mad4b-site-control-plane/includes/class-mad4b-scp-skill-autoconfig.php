<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Zero-touch Staging bootstrap for the MAD4B Skill editor.
 *
 * Staging authoring is enabled automatically when WordPress reports the
 * `staging` environment. Explicit operator configuration always wins,
 * Production is never auto-enabled, and scripts remain separately gated.
 */
final class MAD4B_SCP_Skill_Autoconfig {
	const CONTRACT = 'mad4b.skill-autoconfig.v1';

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
				return self::$status;
			}
			self::$status['configured'] = true;
			self::$status['configuration_source'] = 'explicit_enable';
			return self::$status;
		}

		define( 'MAD4B_SKILLS_EDITOR_ENABLED', true );
		self::$status['configured'] = true;
		self::$status['configuration_source'] = 'staging_auto';
		return self::$status;
	}

	public static function status() {
		return self::$bootstrapped ? self::$status : self::bootstrap();
	}
}
