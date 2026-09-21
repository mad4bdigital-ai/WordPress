<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only runtime component discovery for WordPress Core, themes, MU plugins
 * and bootstrap drop-ins. No ability in this file grants mutation authority.
 */
final class MAD4B_SCP_Runtime_Component_Catalog {
	const CONTRACT = 'mad4b.runtime-components.v1';
	const REPOSITORY_CONTRACT = 'mad4b.repository-runtime-components.v1';
	private static $repository_manifest = null;

	public static function repository_manifest() {
		if ( null !== self::$repository_manifest ) return self::$repository_manifest;
		$path = MAD4B_SCP_DIR . 'config/repository-runtime-components.json';
		if ( ! is_readable( $path ) ) return self::$repository_manifest = array();
		$raw = file_get_contents( $path );
		$data = false === $raw ? null : json_decode( $raw, true );
		if ( ! is_array( $data ) || self::REPOSITORY_CONTRACT !== (string) ( isset( $data['contract'] ) ? $data['contract'] : '' ) ) return self::$repository_manifest = array();
		return self::$repository_manifest = $data;
	}

	private static function ensure_plugin_functions() {
		if ( ! function_exists( 'get_mu_plugins' ) || ! function_exists( 'get_dropins' ) ) require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	private static function bool_constant( $name, $default = false ) {
		return defined( $name ) ? (bool) constant( $name ) : (bool) $default;
	}

	private static function cached_core_update_summary() {
		$state = get_site_transient( 'update_core' );
		$summary = array(
			'cache_present' => is_object( $state ),
			'last_checked' => is_object( $state ) && isset( $state->last_checked ) ? (int) $state->last_checked : 0,
			'checked_version' => is_object( $state ) && isset( $state->version_checked ) ? sanitize_text_field( (string) $state->version_checked ) : '',
			'offer_count' => 0,
			'offered_versions' => array(),
		);
		if ( ! is_object( $state ) || empty( $state->updates ) || ! is_array( $state->updates ) ) return $summary;
		$versions = array();
		foreach ( $state->updates as $offer ) {
			if ( is_object( $offer ) && isset( $offer->response ) && 'upgrade' !== (string) $offer->response ) continue;
			if ( is_object( $offer ) && isset( $offer->current ) ) $versions[] = sanitize_text_field( (string) $offer->current );
		}
		$summary['offer_count'] = count( $versions );
		$summary['offered_versions'] = array_values( array_unique( array_filter( $versions ) ) );
		return $summary;
	}

	public static function core_status() {
		global $wp_db_version;
		return array(
			'contract' => 'mad4b.wordpress-core-runtime.v1',
			'component_type' => 'wordpress_core',
			'available' => true,
			'version' => sanitize_text_field( (string) get_bloginfo( 'version' ) ),
			'php_version' => PHP_VERSION,
			'database_schema_version' => isset( $wp_db_version ) ? (string) $wp_db_version : '',
			'environment_type' => function_exists( 'wp_get_environment_type' ) ? sanitize_key( wp_get_environment_type() ) : '',
			'multisite' => is_multisite(),
			'home_host' => (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ),
			'site_host' => (string) wp_parse_url( site_url( '/' ), PHP_URL_HOST ),
			'policy' => array(
				'debug' => self::bool_constant( 'WP_DEBUG' ),
				'cache' => self::bool_constant( 'WP_CACHE' ),
				'disable_wp_cron' => self::bool_constant( 'DISABLE_WP_CRON' ),
				'alternate_wp_cron' => self::bool_constant( 'ALTERNATE_WP_CRON' ),
				'disallow_file_edit' => self::bool_constant( 'DISALLOW_FILE_EDIT' ),
				'disallow_file_mods' => self::bool_constant( 'DISALLOW_FILE_MODS' ),
				'automatic_updater_disabled' => self::bool_constant( 'AUTOMATIC_UPDATER_DISABLED' ),
			),
			'cached_update_state' => self::cached_core_update_summary(),
			'filesystem_integrity' => array(
				'verified' => false,
				'mode' => 'not_performed',
				'reason' => 'read_adapter_does_not_fetch_remote_checksums_or_mutate_core',
			),
			'authority_mode' => 'read_only_non_authorizing',
			'authorizing' => false,
			'mutation_exposed' => false,
		);
	}

	public static function mu_plugins() {
		self::ensure_plugin_functions();
		$plugins = get_mu_plugins();
		if ( ! is_array( $plugins ) ) $plugins = array();
		$items = array();
		foreach ( $plugins as $plugin_file => $headers ) {
			$items[] = array(
				'component_type' => 'mu_plugin',
				'plugin_file' => str_replace( '\\', '/', (string) $plugin_file ),
				'name' => sanitize_text_field( (string) ( isset( $headers['Name'] ) ? $headers['Name'] : '' ) ),
				'version' => sanitize_text_field( (string) ( isset( $headers['Version'] ) ? $headers['Version'] : '' ) ),
				'author' => sanitize_text_field( (string) ( isset( $headers['AuthorName'] ) ? $headers['AuthorName'] : ( isset( $headers['Author'] ) ? wp_strip_all_tags( $headers['Author'] ) : '' ) ) ),
				'activation_model' => 'must_use_always_loaded_no_activation_toggle',
				'mutation_scope' => 'none_bootstrap_critical',
				'authorizing' => false,
			);
		}
		usort( $items, static function ( $a, $b ) { return strcmp( (string) $a['plugin_file'], (string) $b['plugin_file'] ); } );
		$manifest = self::repository_manifest();
		$repo = isset( $manifest['mu_plugins'] ) && is_array( $manifest['mu_plugins'] ) ? $manifest['mu_plugins'] : array();
		return array(
			'contract' => 'mad4b.mu-plugin-inventory.v1',
			'component_type' => 'mu_plugin',
			'count' => count( $items ),
			'items' => $items,
			'repository_artifacts' => array_values( (array) ( isset( $repo['repository_artifacts'] ) ? $repo['repository_artifacts'] : array() ) ),
			'activation_model' => 'must_use_always_loaded_no_activation_toggle',
			'authority_mode' => 'read_only_non_authorizing',
			'authorizing' => false,
			'mutation_exposed' => false,
		);
	}

	private static function dropin_runtime_signal( $file ) {
		if ( 'advanced-cache.php' === $file ) return self::bool_constant( 'WP_CACHE' );
		if ( 'object-cache.php' === $file && function_exists( 'wp_using_ext_object_cache' ) ) return (bool) wp_using_ext_object_cache();
		if ( 'sunrise.php' === $file ) return self::bool_constant( 'SUNRISE' );
		return null;
	}

	public static function drop_ins() {
		self::ensure_plugin_functions();
		$dropins = get_dropins();
		if ( ! is_array( $dropins ) ) $dropins = array();
		$items = array();
		foreach ( $dropins as $file => $headers ) {
			$items[] = array(
				'component_type' => 'drop_in',
				'file' => (string) $file,
				'name' => sanitize_text_field( (string) ( isset( $headers['Name'] ) ? $headers['Name'] : $file ) ),
				'version' => sanitize_text_field( (string) ( isset( $headers['Version'] ) ? $headers['Version'] : '' ) ),
				'runtime_signal' => self::dropin_runtime_signal( (string) $file ),
				'lifecycle' => 'wordpress_bootstrap_dropin',
				'mutation_scope' => 'none_bootstrap_critical',
				'authorizing' => false,
			);
		}
		usort( $items, static function ( $a, $b ) { return strcmp( (string) $a['file'], (string) $b['file'] ); } );
		return array(
			'contract' => 'mad4b.wordpress-dropin-inventory.v1',
			'component_type' => 'drop_in',
			'count' => count( $items ),
			'items' => $items,
			'authority_mode' => 'read_only_non_authorizing',
			'authorizing' => false,
			'mutation_exposed' => false,
		);
	}

	private static function theme_error_codes( $theme ) {
		if ( ! is_object( $theme ) || ! method_exists( $theme, 'errors' ) ) return array();
		$errors = $theme->errors();
		return is_wp_error( $errors ) ? array_values( array_unique( $errors->get_error_codes() ) ) : array();
	}

	private static function repository_theme_ids() {
		$manifest = self::repository_manifest();
		$themes = isset( $manifest['themes'] ) && is_array( $manifest['themes'] ) ? $manifest['themes'] : array();
		return array_map( 'sanitize_key', array_values( (array) ( isset( $themes['repository_themes'] ) ? $themes['repository_themes'] : array() ) ) );
	}

	public static function themes() {
		$themes = wp_get_themes();
		if ( ! is_array( $themes ) ) $themes = array();
		$active_stylesheet = sanitize_key( (string) get_stylesheet() );
		$active_template = sanitize_key( (string) get_template() );
		$repository = self::repository_theme_ids();
		$items = array();
		foreach ( $themes as $stylesheet => $theme ) {
			if ( ! is_object( $theme ) ) continue;
			$stylesheet = sanitize_key( (string) $stylesheet );
			$template = sanitize_key( (string) $theme->get_template() );
			$is_child = '' !== $template && $template !== $stylesheet;
			$family = ( 'astra' === $stylesheet || 'astra' === $template ) ? 'astra' : 'theme';
			$items[] = array(
				'component_type' => $is_child ? 'child_theme' : 'theme',
				'stylesheet' => $stylesheet,
				'template' => $template,
				'name' => sanitize_text_field( (string) $theme->get( 'Name' ) ),
				'version' => sanitize_text_field( (string) $theme->get( 'Version' ) ),
				'family' => $family,
				'is_child_theme' => $is_child,
				'active_stylesheet' => $stylesheet === $active_stylesheet,
				'active_template' => $template === $active_template,
				'repository_tracked' => in_array( $stylesheet, $repository, true ),
				'error_codes' => self::theme_error_codes( $theme ),
				'authorizing' => false,
			);
		}
		usort( $items, static function ( $a, $b ) { return strcmp( (string) $a['stylesheet'], (string) $b['stylesheet'] ); } );
		return array(
			'contract' => 'mad4b.theme-runtime-inventory.v1',
			'component_type' => 'theme',
			'active_stylesheet' => $active_stylesheet,
			'active_template' => $active_template,
			'count' => count( $items ),
			'items' => $items,
			'repository_theme_count' => count( $repository ),
			'repository_themes' => $repository,
			'authority_mode' => 'read_only_non_authorizing',
			'authorizing' => false,
			'mutation_exposed' => false,
		);
	}

	public static function theme( $stylesheet ) {
		$stylesheet = sanitize_key( (string) $stylesheet );
		if ( '' === $stylesheet ) return new WP_Error( 'mad4b_theme_id_required', 'A theme stylesheet identifier is required.' );
		$theme = wp_get_theme( $stylesheet );
		if ( ! is_object( $theme ) || ! $theme->exists() ) return new WP_Error( 'mad4b_theme_not_found', 'The requested theme is not installed.' );
		$inventory = self::themes();
		foreach ( $inventory['items'] as $item ) if ( $stylesheet === $item['stylesheet'] ) return $item;
		return new WP_Error( 'mad4b_theme_inventory_mismatch', 'The requested theme could not be resolved from the runtime inventory.' );
	}

	private static function theme_mod_summary( $stylesheet ) {
		$stylesheet = sanitize_key( (string) $stylesheet );
		$mods = get_option( 'theme_mods_' . $stylesheet, array() );
		if ( ! is_array( $mods ) ) $mods = array();
		$keys = array_map( 'sanitize_key', array_keys( $mods ) );
		sort( $keys, SORT_STRING );
		return array(
			'key_count' => count( $keys ),
			'keys' => $keys,
			'fingerprint' => hash( 'sha256', wp_json_encode( $mods ) ),
			'values_exposed' => false,
		);
	}

	private static function bounded_child_files( $child_theme, $limit = 300 ) {
		$entry_budget = 1200;
		$max_depth = 16;
		$result = array(
			'scanned_entry_count' => 0,
			'scanned_file_count' => 0,
			'returned_file_count' => 0,
			'pruned_directory_count' => 0,
			'skipped_symlink_count' => 0,
			'scan_entry_budget' => $entry_budget,
			'max_depth' => $max_depth,
			'truncated' => false,
			'files' => array(),
			'override_files' => array(),
		);
		if ( ! is_object( $child_theme ) || ! $child_theme->exists() || ! method_exists( $child_theme, 'get_stylesheet_directory' ) || ! method_exists( $child_theme, 'get_template_directory' ) ) return $result;
		$child_root = $child_theme->get_stylesheet_directory();
		$parent_root = $child_theme->get_template_directory();
		$child_real = realpath( $child_root );
		$parent_real = realpath( $parent_root );
		if ( false === $child_real || false === $parent_real || ! is_dir( $child_real ) || ! is_dir( $parent_real ) || $child_real === $parent_real ) return $result;

		// Use an explicit directory stack instead of RecursiveDirectoryIterator.
		// This lets the scan stop at a hard entry budget and prunes vendor,
		// node_modules and .git *before* traversal rather than merely hiding their
		// returned files after an unbounded walk. Symlinks are never followed.
		$stack = array( array( 'path' => $child_real, 'relative' => '', 'depth' => 0 ) );
		try {
			while ( ! empty( $stack ) ) {
				$frame = array_pop( $stack );
				$directory = new DirectoryIterator( $frame['path'] );
				foreach ( $directory as $entry ) {
					if ( $entry->isDot() ) continue;
					if ( $result['scanned_entry_count'] >= $entry_budget ) {
						$result['truncated'] = true;
						break 2;
					}
					++$result['scanned_entry_count'];
					$name = $entry->getFilename();
					$relative = '' === $frame['relative'] ? $name : $frame['relative'] . '/' . $name;

					if ( $entry->isLink() ) {
						++$result['skipped_symlink_count'];
						continue;
					}
					if ( $entry->isDir() ) {
						if ( in_array( $name, array( 'vendor', 'node_modules', '.git' ), true ) ) {
							++$result['pruned_directory_count'];
							continue;
						}
						if ( (int) $frame['depth'] >= $max_depth ) {
							$result['truncated'] = true;
							continue;
						}
						$stack[] = array( 'path' => $entry->getPathname(), 'relative' => $relative, 'depth' => (int) $frame['depth'] + 1 );
						continue;
					}
					if ( ! $entry->isFile() ) continue;
					++$result['scanned_file_count'];
					if ( count( $result['files'] ) >= $limit ) {
						$result['truncated'] = true;
						break 2;
					}
					$result['files'][] = str_replace( '\\', '/', $relative );
					$parent_candidate = $parent_real . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
					if ( is_file( $parent_candidate ) ) $result['override_files'][] = str_replace( '\\', '/', $relative );
				}
			}
		} catch ( Throwable $e ) {
			$result['scan_error'] = 'filesystem_scan_unavailable';
		}
		$result['returned_file_count'] = count( $result['files'] );
		sort( $result['files'], SORT_STRING );
		sort( $result['override_files'], SORT_STRING );
		return $result;
	}

	public static function astra_status() {
		$theme = wp_get_theme( 'astra' );
		$available = is_object( $theme ) && $theme->exists();
		$children = array();
		$themes = self::themes();
		foreach ( $themes['items'] as $item ) if ( ! empty( $item['is_child_theme'] ) && 'astra' === $item['template'] ) $children[] = $item['stylesheet'];
		return array(
			'contract' => 'mad4b.astra-theme-runtime.v1',
			'component_type' => 'theme',
			'available' => $available,
			'stylesheet' => 'astra',
			'version' => $available ? sanitize_text_field( (string) $theme->get( 'Version' ) ) : '',
			'active_template' => 'astra' === sanitize_key( (string) get_template() ),
			'active_stylesheet' => 'astra' === sanitize_key( (string) get_stylesheet() ),
			'child_themes' => array_values( array_unique( $children ) ),
			'child_theme_count' => count( array_unique( $children ) ),
			'theme_mods' => $available ? self::theme_mod_summary( 'astra' ) : array( 'key_count' => 0, 'keys' => array(), 'fingerprint' => '', 'values_exposed' => false ),
			'astra_addon_runtime_detected' => defined( 'ASTRA_EXT_VER' ),
			'authority_mode' => 'read_only_non_authorizing',
			'authorizing' => false,
			'mutation_exposed' => false,
		);
	}

	public static function astra_children() {
		$themes = self::themes();
		$children = array();
		foreach ( $themes['items'] as $item ) {
			if ( empty( $item['is_child_theme'] ) || 'astra' !== $item['template'] ) continue;
			$theme = wp_get_theme( $item['stylesheet'] );
			$item['theme_mods'] = self::theme_mod_summary( $item['stylesheet'] );
			$item['filesystem'] = self::bounded_child_files( $theme );
			$children[] = $item;
		}
		return array(
			'contract' => 'mad4b.astra-child-theme-runtime.v1',
			'component_type' => 'child_theme',
			'parent_template' => 'astra',
			'count' => count( $children ),
			'items' => $children,
			'authority_mode' => 'read_only_non_authorizing',
			'authorizing' => false,
			'mutation_exposed' => false,
		);
	}

	public static function inventory() {
		$plugin_coverage = class_exists( 'MAD4B_SCP_Plugin_Discovery' ) ? MAD4B_SCP_Plugin_Discovery::coverage() : array();
		$plugin_counts = isset( $plugin_coverage['counts'] ) && is_array( $plugin_coverage['counts'] ) ? $plugin_coverage['counts'] : array();
		$mu = self::mu_plugins();
		$dropins = self::drop_ins();
		$themes = self::themes();
		$astra = self::astra_status();
		$astra_children = self::astra_children();
		return array(
			'contract' => self::CONTRACT,
			'authority_mode' => 'read_only_non_authorizing',
			'authorizing' => false,
			'mutation_exposed' => false,
			'component_types' => array( 'wordpress_core', 'regular_plugin', 'mu_plugin', 'drop_in', 'theme', 'child_theme' ),
			'counts' => array(
				'regular_plugins' => isset( $plugin_counts['installed'] ) ? (int) $plugin_counts['installed'] : 0,
				'mu_plugins' => (int) $mu['count'],
				'drop_ins' => (int) $dropins['count'],
				'themes' => (int) $themes['count'],
				'astra_children' => (int) $astra_children['count'],
			),
			'wordpress_core' => self::core_status(),
			'regular_plugins' => array(
				'contract' => isset( $plugin_coverage['contract'] ) ? $plugin_coverage['contract'] : '',
				'counts' => $plugin_counts,
				'runtime_write_default' => 'deny',
			),
			'mu_plugins' => $mu,
			'drop_ins' => $dropins,
			'themes' => $themes,
			'astra' => $astra,
			'astra_children' => $astra_children,
			'repository_manifest' => self::repository_manifest(),
		);
	}
}

abstract class MAD4B_SCP_Read_Only_Runtime_Component_Adapter extends MAD4B_SCP_Adapter_Base {
	protected function mutation_requires_certification() { return false; }
	protected function provider_certification( $available ) { return null; }
	protected function component_status( $available, $contract, array $extra = array() ) {
		return array_merge( array(
			'id' => $this->id(),
			'label' => $this->label(),
			'available' => (bool) $available,
			'version' => '',
			'abilities' => $this->ability_names(),
			'contract' => $contract,
			'authority_mode' => 'read_only_non_authorizing',
			'mutation_master_enabled' => false,
			'mutation_requires_certification' => false,
			'mutation_exposed' => false,
			'reversible_contracts' => array(),
		), $extra );
	}
}

final class MAD4B_SCP_WordPress_Core_Adapter extends MAD4B_SCP_Read_Only_Runtime_Component_Adapter {
	public function id() { return 'wordpress-core'; }
	public function label() { return 'WordPress Core'; }
	public function is_available() { return true; }
	public function ability_names() { return array( 'read' => array( 'wordpress-core/status' ), 'content' => array(), 'admin' => array() ); }
	public function register_abilities() { $this->add_ability( 'wordpress-core/status', 'Read WordPress Core Runtime Status', 'read_status', array( 'MAD4B_SCP_Policy', 'can_read' ) ); }
	public function read_status() { return MAD4B_SCP_Runtime_Component_Catalog::core_status(); }
	public function status() { $core = $this->read_status(); return $this->component_status( true, 'mad4b.wordpress-core-adapter.v1', array( 'version' => isset( $core['version'] ) ? $core['version'] : '' ) ); }
}

final class MAD4B_SCP_MU_Plugins_Adapter extends MAD4B_SCP_Read_Only_Runtime_Component_Adapter {
	public function id() { return 'mu-plugins'; }
	public function label() { return 'Must-Use Plugins'; }
	public function is_available() { return true; }
	public function ability_names() { return array( 'read' => array( 'mu-plugins/inventory' ), 'content' => array(), 'admin' => array() ); }
	public function register_abilities() { $this->add_ability( 'mu-plugins/inventory', 'Read Must-Use Plugin Inventory', 'inventory', array( 'MAD4B_SCP_Policy', 'can_read' ) ); }
	public function inventory() { return MAD4B_SCP_Runtime_Component_Catalog::mu_plugins(); }
	public function status() { $inventory = $this->inventory(); return $this->component_status( true, 'mad4b.mu-plugin-adapter.v1', array( 'runtime_component_count' => (int) $inventory['count'], 'activation_model' => 'must_use_always_loaded_no_activation_toggle' ) ); }
}

final class MAD4B_SCP_Drop_Ins_Adapter extends MAD4B_SCP_Read_Only_Runtime_Component_Adapter {
	public function id() { return 'drop-ins'; }
	public function label() { return 'WordPress Drop-ins'; }
	public function is_available() { return true; }
	public function ability_names() { return array( 'read' => array( 'drop-ins/inventory' ), 'content' => array(), 'admin' => array() ); }
	public function register_abilities() { $this->add_ability( 'drop-ins/inventory', 'Read WordPress Drop-in Inventory', 'inventory', array( 'MAD4B_SCP_Policy', 'can_read' ) ); }
	public function inventory() { return MAD4B_SCP_Runtime_Component_Catalog::drop_ins(); }
	public function status() { $inventory = $this->inventory(); return $this->component_status( true, 'mad4b.wordpress-dropin-adapter.v1', array( 'runtime_component_count' => (int) $inventory['count'], 'lifecycle' => 'wordpress_bootstrap_dropin' ) ); }
}

final class MAD4B_SCP_Themes_Adapter extends MAD4B_SCP_Read_Only_Runtime_Component_Adapter {
	public function id() { return 'themes'; }
	public function label() { return 'WordPress Themes'; }
	public function is_available() { return function_exists( 'wp_get_themes' ); }
	public function ability_names() { return array( 'read' => array( 'themes/inventory', 'themes/get-theme' ), 'content' => array(), 'admin' => array() ); }
	public function register_abilities() {
		$read = array( 'MAD4B_SCP_Policy', 'can_read' );
		$this->add_ability( 'themes/inventory', 'Read WordPress Theme Inventory', 'inventory', $read );
		$this->add_ability( 'themes/get-theme', 'Read WordPress Theme Runtime Status', 'get_theme', $read, $this->schema( array( 'stylesheet' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 120 ) ), array( 'stylesheet' ) ) );
	}
	public function inventory() { return MAD4B_SCP_Runtime_Component_Catalog::themes(); }
	public function get_theme( $input ) { $input = is_array( $input ) ? $input : array(); return MAD4B_SCP_Runtime_Component_Catalog::theme( isset( $input['stylesheet'] ) ? $input['stylesheet'] : '' ); }
	public function status() { $inventory = $this->inventory(); return $this->component_status( true, 'mad4b.theme-runtime-adapter.v1', array( 'runtime_component_count' => (int) $inventory['count'], 'active_stylesheet' => $inventory['active_stylesheet'], 'active_template' => $inventory['active_template'] ) ); }
}

final class MAD4B_SCP_Astra_Theme_Adapter extends MAD4B_SCP_Read_Only_Runtime_Component_Adapter {
	public function id() { return 'astra-theme'; }
	public function label() { return 'Astra Theme'; }
	public function is_available() { $status = MAD4B_SCP_Runtime_Component_Catalog::astra_status(); return ! empty( $status['available'] ); }
	public function ability_names() { return array( 'read' => array( 'astra-theme/status' ), 'content' => array(), 'admin' => array() ); }
	public function register_abilities() { $this->add_ability( 'astra-theme/status', 'Read Astra Theme Runtime Status', 'read_status', array( 'MAD4B_SCP_Policy', 'can_read' ) ); }
	public function read_status() { return MAD4B_SCP_Runtime_Component_Catalog::astra_status(); }
	public function status() { $runtime = $this->read_status(); return $this->component_status( ! empty( $runtime['available'] ), 'mad4b.astra-theme-adapter.v1', array( 'version' => isset( $runtime['version'] ) ? $runtime['version'] : '', 'child_theme_count' => isset( $runtime['child_theme_count'] ) ? (int) $runtime['child_theme_count'] : 0 ) ); }
}

final class MAD4B_SCP_Astra_Child_Theme_Adapter extends MAD4B_SCP_Read_Only_Runtime_Component_Adapter {
	public function id() { return 'astra-child-theme'; }
	public function label() { return 'Astra Child Theme'; }
	public function is_available() { $status = MAD4B_SCP_Runtime_Component_Catalog::astra_children(); return ! empty( $status['count'] ); }
	public function ability_names() { return array( 'read' => array( 'astra-child-theme/inventory' ), 'content' => array(), 'admin' => array() ); }
	public function register_abilities() { $this->add_ability( 'astra-child-theme/inventory', 'Read Astra Child Theme Inventory and Overrides', 'inventory', array( 'MAD4B_SCP_Policy', 'can_read' ) ); }
	public function inventory() { return MAD4B_SCP_Runtime_Component_Catalog::astra_children(); }
	public function status() { $runtime = $this->inventory(); return $this->component_status( ! empty( $runtime['count'] ), 'mad4b.astra-child-theme-adapter.v1', array( 'runtime_component_count' => isset( $runtime['count'] ) ? (int) $runtime['count'] : 0, 'parent_template' => 'astra' ) ); }
}

final class MAD4B_SCP_Runtime_Components_Adapter extends MAD4B_SCP_Read_Only_Runtime_Component_Adapter {
	public function id() { return 'runtime-components'; }
	public function label() { return 'Runtime Components'; }
	public function is_available() { return true; }
	public function ability_names() { return array( 'read' => array( 'runtime-components/inventory' ), 'content' => array(), 'admin' => array() ); }
	public function register_abilities() { $this->add_ability( 'runtime-components/inventory', 'Read Complete WordPress Runtime Component Inventory', 'inventory', array( 'MAD4B_SCP_Policy', 'can_read' ) ); }
	public function inventory() { return MAD4B_SCP_Runtime_Component_Catalog::inventory(); }
	public function status() { $inventory = $this->inventory(); return $this->component_status( true, 'mad4b.runtime-components-adapter.v1', array( 'component_types' => $inventory['component_types'], 'counts' => $inventory['counts'] ) ); }
}

add_action( 'mad4b_scp_register_adapters', static function ( $registry ) {
	if ( ! is_object( $registry ) || ! method_exists( $registry, 'register' ) || ! method_exists( $registry, 'get' ) ) return;
	$classes = array(
		'MAD4B_SCP_WordPress_Core_Adapter',
		'MAD4B_SCP_MU_Plugins_Adapter',
		'MAD4B_SCP_Drop_Ins_Adapter',
		'MAD4B_SCP_Themes_Adapter',
		'MAD4B_SCP_Astra_Theme_Adapter',
		'MAD4B_SCP_Astra_Child_Theme_Adapter',
		'MAD4B_SCP_Runtime_Components_Adapter',
	);
	foreach ( $classes as $class ) {
		if ( ! class_exists( $class ) ) continue;
		$adapter = new $class();
		if ( ! $registry->get( $adapter->id() ) ) $registry->register( $adapter );
	}
}, 25 );