<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Certifies the effective MCP class set, not merely the Adapter entrypoint.
 *
 * Jetpack Autoloader can resolve WP\MCP / WP\McpSchema classes from another
 * package before the official Adapter's own copies. Version/header checks on
 * mcp-adapter.php therefore do not prove that the builder, validator and DTO
 * classes executing this request belong to the certified runtime.
 */
final class MAD4B_SCP_MCP_Class_Provenance {
	const CONTRACT = 'mad4b.mcp-class-provenance.v1';
	const PROVIDER = 'mcp_adapter';
	const BLOCKER = 'mcp_adapter_class_provenance_mismatch';

	private static $cache = array();

	public static function critical_classes() {
		return array(
			'adapter' => array( 'class' => 'WP\\MCP\\Core\\McpAdapter', 'file' => 'includes/Core/McpAdapter.php' ),
			'ability_builder' => array( 'class' => 'WP\\MCP\\Domain\\Tools\\RegisterAbilityAsMcpTool', 'file' => 'includes/Domain/Tools/RegisterAbilityAsMcpTool.php' ),
			'tool_validator' => array( 'class' => 'WP\\MCP\\Domain\\Tools\\McpToolValidator', 'file' => 'includes/Domain/Tools/McpToolValidator.php' ),
			'schema_transformer' => array( 'class' => 'WP\\MCP\\Domain\\Utils\\SchemaTransformer', 'file' => 'includes/Domain/Utils/SchemaTransformer.php' ),
			'annotation_mapper' => array( 'class' => 'WP\\MCP\\Domain\\Utils\\McpAnnotationMapper', 'file' => 'includes/Domain/Utils/McpAnnotationMapper.php' ),
			'validator_utils' => array( 'class' => 'WP\\MCP\\Domain\\Utils\\McpValidator', 'file' => 'includes/Domain/Utils/McpValidator.php' ),
			'tool_dto' => array( 'class' => 'WP\\McpSchema\\Server\\Tools\\DTO\\Tool', 'file' => 'vendor/wordpress/php-mcp-schema/src/Server/Tools/DTO/Tool.php' ),
			'tool_input_schema_dto' => array( 'class' => 'WP\\McpSchema\\Server\\Tools\\DTO\\ToolInputSchema', 'file' => 'vendor/wordpress/php-mcp-schema/src/Server/Tools/DTO/ToolInputSchema.php' ),
			'tool_output_schema_dto' => array( 'class' => 'WP\\McpSchema\\Server\\Tools\\DTO\\ToolOutputSchema', 'file' => 'vendor/wordpress/php-mcp-schema/src/Server/Tools/DTO/ToolOutputSchema.php' ),
			'tool_annotations_dto' => array( 'class' => 'WP\\McpSchema\\Server\\Tools\\DTO\\ToolAnnotations', 'file' => 'vendor/wordpress/php-mcp-schema/src/Server/Tools/DTO/ToolAnnotations.php' ),
			'tool_execution_dto' => array( 'class' => 'WP\\McpSchema\\Server\\Tools\\DTO\\ToolExecution', 'file' => 'vendor/wordpress/php-mcp-schema/src/Server/Tools/DTO/ToolExecution.php' ),
		);
	}

	public static function status( $force = false, $autoload = true ) {
		$cache_key = $autoload ? 'full' : 'loaded_only';
		if ( ! $force && isset( self::$cache[ $cache_key ] ) ) return self::$cache[ $cache_key ];
		$out = array(
			'contract' => self::CONTRACT,
			'enforced' => false,
			'ready' => false,
			'state' => 'unavailable',
			'blocker' => '',
			'provider' => self::PROVIDER,
			'certified_version' => '',
			'class_count' => count( self::critical_classes() ),
			'verified_count' => 0,
			'failure_count' => 0,
			'unobserved_count' => 0,
			'complete' => false,
			'mixed_runtime' => false,
			'repair_action' => '',
			'repair_provider_id' => self::PROVIDER,
			'explicit_admin_repair_required' => false,
			'classes' => array(),
			'failures' => array(),
		);

		if ( ! defined( 'WP_PLUGIN_DIR' ) || ! class_exists( 'MAD4B_SCP_Provider_Contracts', false ) ) {
			self::$cache[ $cache_key ] = $out;
			return self::$cache[ $cache_key ];
		}
		$contract = MAD4B_SCP_Provider_Contracts::get( self::PROVIDER );
		if ( empty( $contract ) || ! is_array( $contract ) ) {
			$out['enforced'] = true;
			$out['state'] = 'baseline_unavailable';
			$out['blocker'] = self::BLOCKER;
			self::$cache[ $cache_key ] = $out;
			return self::$cache[ $cache_key ];
		}
		$runtime_root = self::active_mcp_adapter_root();
		if ( '' === $runtime_root ) {
			$out['enforced'] = true;
			$out['state'] = 'plugin_identity_unavailable';
			$out['blocker'] = self::BLOCKER;
			self::$cache[ $cache_key ] = $out;
			return self::$cache[ $cache_key ];
		}
		$out = self::inspect_contract( $contract, $runtime_root, $out, $autoload );
		self::$cache[ $cache_key ] = $out;
		return self::$cache[ $cache_key ];
	}

	/**
	 * Public for deterministic offline certification fixtures. Never mutates disk.
	 * When $autoload is false it reflects only already-loaded classes and therefore
	 * cannot claim autoloader ownership. Full preflight uses $autoload=true because
	 * the same classes are immediately required for MCP DTO construction anyway.
	 */
	public static function inspect_contract( array $contract, $runtime_root, array $seed = array(), $autoload = true ) {
		$out = array_merge( array(
			'contract' => self::CONTRACT,
			'enforced' => true,
			'ready' => false,
			'state' => 'inspection',
			'blocker' => '',
			'provider' => self::PROVIDER,
			'certified_version' => isset( $contract['version'] ) ? sanitize_text_field( (string) $contract['version'] ) : '',
			'class_count' => count( self::critical_classes() ),
			'verified_count' => 0,
			'failure_count' => 0,
			'unobserved_count' => 0,
			'complete' => false,
			'mixed_runtime' => false,
			'repair_action' => '',
			'repair_provider_id' => self::PROVIDER,
			'explicit_admin_repair_required' => false,
			'classes' => array(),
			'failures' => array(),
		), $seed );
		$out['enforced'] = true;
		$out['certified_version'] = isset( $contract['version'] ) ? sanitize_text_field( (string) $contract['version'] ) : '';

		$manifest = isset( $contract['critical_files'] ) && is_array( $contract['critical_files'] ) ? $contract['critical_files'] : array();
		$root = realpath( (string) $runtime_root );
		$root_normalized = $root ? rtrim( self::normalize_path( $root ), '/' ) : '';
		$plugin_root = defined( 'WP_PLUGIN_DIR' ) ? realpath( WP_PLUGIN_DIR ) : false;
		$plugin_root_normalized = $plugin_root ? rtrim( self::normalize_path( $plugin_root ), '/' ) : '';
		$runtime_root_relative = '';
		if ( '' !== $root_normalized && '' !== $plugin_root_normalized && 0 === strpos( $root_normalized, $plugin_root_normalized . '/' ) ) {
			$runtime_root_relative = ltrim( substr( $root_normalized, strlen( $plugin_root_normalized ) ), '/' );
		}

		foreach ( self::critical_classes() as $alias => $spec ) {
			$class = $spec['class'];
			$relative = ltrim( self::normalize_path( $spec['file'] ), '/' );
			$expected_sha = isset( $manifest[ $relative ] ) ? strtolower( trim( (string) $manifest[ $relative ] ) ) : '';
			$row = array(
				'alias' => sanitize_key( (string) $alias ),
				'class' => $class,
				'expected_source' => ( '' !== $runtime_root_relative ? $runtime_root_relative . '/' : '' ) . $relative,
				'observed_source' => 'not_loaded',
				'expected_sha256' => preg_match( '/^[a-f0-9]{64}$/D', $expected_sha ) ? $expected_sha : '',
				'actual_sha256' => '',
				'path_match' => false,
				'sha256_match' => false,
				'ready' => false,
				'reason' => '',
			);

			if ( '' === $row['expected_sha256'] ) {
				$row['reason'] = 'certified_class_hash_missing';
			} elseif ( ! class_exists( $class, (bool) $autoload ) ) {
				if ( ! $autoload && ! class_exists( $class, false ) ) {
					$row['reason'] = 'runtime_class_not_loaded';
					$out['classes'][] = $row;
					$out['unobserved_count']++;
					continue;
				}
				$row['reason'] = 'runtime_class_unavailable';
			} else {
				try {
					$reflection = new ReflectionClass( $class );
					$file = $reflection->getFileName();
					$resolved = $file ? realpath( $file ) : false;
					if ( ! $resolved || ! is_file( $resolved ) ) {
						$row['reason'] = 'runtime_class_source_unavailable';
					} else {
						$normalized = self::normalize_path( $resolved );
						$expected_file = $root_normalized ? $root_normalized . '/' . $relative : '';
						if ( $plugin_root_normalized && ( $normalized === $plugin_root_normalized || 0 === strpos( $normalized, $plugin_root_normalized . '/' ) ) ) {
							$row['observed_source'] = ltrim( substr( $normalized, strlen( $plugin_root_normalized ) ), '/' );
						} else {
							$row['observed_source'] = 'outside-wp-plugin-dir';
						}
						$row['path_match'] = '' !== $expected_file && hash_equals( $expected_file, $normalized );
						$actual_sha = is_readable( $resolved ) ? hash_file( 'sha256', $resolved ) : false;
						$row['actual_sha256'] = is_string( $actual_sha ) && preg_match( '/^[a-f0-9]{64}$/D', strtolower( $actual_sha ) ) ? strtolower( $actual_sha ) : '';
						$row['sha256_match'] = '' !== $row['actual_sha256'] && hash_equals( $row['expected_sha256'], $row['actual_sha256'] );
						$row['ready'] = $row['path_match'] && $row['sha256_match'];
						if ( ! $row['path_match'] ) $row['reason'] = 'runtime_class_source_mismatch';
						elseif ( ! $row['sha256_match'] ) $row['reason'] = 'runtime_class_sha256_mismatch';
					}
				} catch ( Throwable $error ) {
					$row['reason'] = 'runtime_class_reflection_failed';
				}
			}

			$out['classes'][] = $row;
			if ( $row['ready'] ) {
				$out['verified_count']++;
			} else {
				$out['failures'][] = array(
					'alias' => $row['alias'],
					'class' => $row['class'],
					'expected_source' => $row['expected_source'],
					'observed_source' => $row['observed_source'],
					'expected_sha256' => $row['expected_sha256'],
					'actual_sha256' => $row['actual_sha256'],
					'reason' => sanitize_key( (string) $row['reason'] ),
				);
			}
		}

		$out['failure_count'] = count( $out['failures'] );
		$out['complete'] = 0 === $out['unobserved_count'];
		$out['mixed_runtime'] = $out['failure_count'] > 0 && $out['verified_count'] > 0;
		$out['ready'] = $out['complete'] && 0 === $out['failure_count'] && $out['verified_count'] === $out['class_count'];
		if ( $out['ready'] ) $out['state'] = 'certified_class_set';
		elseif ( $out['mixed_runtime'] ) $out['state'] = 'mixed_runtime';
		elseif ( $out['failure_count'] > 0 ) $out['state'] = 'class_set_mismatch';
		else $out['state'] = 'partial_certified_class_set';
		$out['blocker'] = $out['failure_count'] > 0 ? self::BLOCKER : '';

		$official_disk_mismatch = false;
		$foreign_source_mismatch = false;
		foreach ( $out['failures'] as $failure ) {
			if ( ! is_array( $failure ) ) continue;
			$reason = isset( $failure['reason'] ) ? (string) $failure['reason'] : '';
			$observed = isset( $failure['observed_source'] ) ? (string) $failure['observed_source'] : '';
			if ( 'runtime_class_sha256_mismatch' === $reason ) {
				// This reason is assigned only after the exact expected path matched;
				// directory names therefore do not participate in official ownership.
				$official_disk_mismatch = true;
			}
			if ( 'runtime_class_source_mismatch' === $reason ) {
				$foreign_source_mismatch = true;
			}
		}
		if ( $official_disk_mismatch ) {
			$out['repair_action'] = 'repair_certified_mcp_adapter_package';
			$out['explicit_admin_repair_required'] = true;
		} elseif ( $foreign_source_mismatch ) {
			$out['repair_action'] = 'arm_managed_mu_bootstrap_on_lifecycle';
		} elseif ( $out['failure_count'] > 0 ) {
			$out['repair_action'] = 'inspect_certified_mcp_runtime';
		}
		return $out;
	}

	private static function active_mcp_adapter_root() {
		if ( ! defined( 'WP_PLUGIN_DIR' ) || ! function_exists( 'get_option' ) ) return '';
		$active = get_option( 'active_plugins', array() );
		if ( ! is_array( $active ) ) return '';
		$matches = array();
		foreach ( $active as $plugin_file ) {
			$plugin_file = self::normalize_path( (string) $plugin_file );
			$plugin_file = ltrim( trim( $plugin_file ), '/' );
			if ( '' === $plugin_file || false !== strpos( $plugin_file, '../' ) || in_array( '..', explode( '/', $plugin_file ), true ) ) continue;
			if ( 'mcp-adapter.php' === basename( $plugin_file ) ) $matches[] = $plugin_file;
		}
		$matches = array_values( array_unique( $matches ) );
		if ( 1 !== count( $matches ) ) return '';
		$root = realpath( trailingslashit( WP_PLUGIN_DIR ) . dirname( $matches[0] ) );
		return $root && is_dir( $root ) ? rtrim( self::normalize_path( $root ), '/' ) : '';
	}

	public static function reset_cache() { self::$cache = array(); }

	private static function normalize_path( $path ) {
		$path = str_replace( '\\', '/', (string) $path );
		return function_exists( 'wp_normalize_path' ) ? wp_normalize_path( $path ) : $path;
	}
}
