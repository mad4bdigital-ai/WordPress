<?php
/** Disposable runtime proof for Core, themes, MU plugins and drop-ins. */
if ( ! defined( 'ABSPATH' ) ) throw new RuntimeException( 'WordPress is not loaded.' );

$check = static function ( $condition, $message ) { if ( ! $condition ) throw new RuntimeException( $message ); };
$check( class_exists( 'MAD4B_SCP_Runtime_Component_Catalog' ), 'Runtime component catalog is unavailable.' );
$check( class_exists( 'MAD4B_SCP_Runtime_Components_Admin_UI' ), 'Runtime Components Admin UI is unavailable.' );

$registry = MAD4B_SCP_Adapter_Registry::instance();
$adapter_ids = array( 'wordpress-core', 'mu-plugins', 'drop-ins', 'themes', 'astra-theme', 'astra-child-theme', 'runtime-components' );
foreach ( $adapter_ids as $adapter_id ) {
	$adapter = $registry->get( $adapter_id );
	$check( is_object( $adapter ), 'Runtime component adapter was not registered: ' . $adapter_id );
	$map = $adapter->ability_names();
	$check( ! empty( $map['read'] ), 'Runtime component adapter has no read ability: ' . $adapter_id );
	$check( empty( $map['content'] ) && empty( $map['admin'] ), 'Runtime component adapter opened mutation/admin surface: ' . $adapter_id );
	$status = $adapter->status();
	$check( empty( $status['mutation_exposed'] ), 'Runtime component adapter exposed mutation: ' . $adapter_id );
	$check( 'read_only_non_authorizing' === (string) $status['authority_mode'], 'Runtime component adapter authority mode drift: ' . $adapter_id );
}

$ability_names = array(
	'wordpress-core/status',
	'mu-plugins/inventory',
	'drop-ins/inventory',
	'themes/inventory',
	'themes/get-theme',
	'astra-theme/status',
	'astra-child-theme/inventory',
	'runtime-components/inventory',
);
foreach ( $ability_names as $ability_name ) {
	$check( wp_has_ability( $ability_name ), 'Missing runtime component ability: ' . $ability_name );
	$ability = wp_get_ability( $ability_name );
	$meta = $ability->get_meta();
	$check( empty( $meta['public'] ) && empty( $meta['mcp']['public'] ), 'Runtime component ability leaked public: ' . $ability_name );
	$check( ! empty( $meta['annotations']['readonly'] ), 'Runtime component ability is not readonly: ' . $ability_name );
	$check( empty( $meta['annotations']['destructive'] ), 'Runtime component ability is destructive: ' . $ability_name );
	$check( 'read' === (string) ( isset( $meta['mcp']['surface'] ) ? $meta['mcp']['surface'] : '' ), 'Runtime component ability escaped read surface: ' . $ability_name );
	$check( MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-read', $ability_name ), 'Runtime component ability is not mounted on mad4b-read: ' . $ability_name );
	foreach ( array( 'mad4b-content', 'mad4b-write', 'mad4b-admin', 'mad4b-breakglass' ) as $server_id ) {
		$check( ! MAD4B_SCP_Servers::ability_is_mounted( $server_id, $ability_name ), 'Runtime component ability leaked to ' . $server_id . ': ' . $ability_name );
	}
}

$core = wp_get_ability( 'wordpress-core/status' )->execute();
$check( ! is_wp_error( $core ) && 'mad4b.wordpress-core-runtime.v1' === $core['contract'], 'WordPress Core runtime contract failed.' );
$check( get_bloginfo( 'version' ) === $core['version'], 'WordPress Core version readback mismatch.' );
$check( empty( $core['filesystem_integrity']['verified'] ) && 'not_performed' === $core['filesystem_integrity']['mode'], 'Core adapter implied unperformed checksum verification.' );
$check( empty( $core['authorizing'] ) && empty( $core['mutation_exposed'] ), 'Core adapter became authorizing.' );

$previous_stylesheet = get_stylesheet();
$theme_root = get_theme_root();
$astra_dir = trailingslashit( $theme_root ) . 'astra';
$child_dir = trailingslashit( $theme_root ) . 'astra-child-ci';
$mu_dir = WPMU_PLUGIN_DIR;
$mu_file = trailingslashit( $mu_dir ) . 'mad4b-ci-mu.php';
$dropin_file = trailingslashit( WP_CONTENT_DIR ) . 'install.php';
$created_astra = ! is_dir( $astra_dir );
$created_child = ! is_dir( $child_dir );
$created_mu_dir = ! is_dir( $mu_dir );
$existing_dropin = is_file( $dropin_file ) ? file_get_contents( $dropin_file ) : null;

$remove_tree = static function ( $dir ) use ( &$remove_tree ) {
	if ( ! is_dir( $dir ) ) return;
	$items = scandir( $dir );
	if ( ! is_array( $items ) ) return;
	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) continue;
		$path = $dir . DIRECTORY_SEPARATOR . $item;
		if ( is_dir( $path ) && ! is_link( $path ) ) $remove_tree( $path ); else @unlink( $path );
	}
	@rmdir( $dir );
};

try {
	wp_mkdir_p( $astra_dir );
	wp_mkdir_p( $child_dir );
	wp_mkdir_p( $mu_dir );
	file_put_contents( $astra_dir . '/style.css', "/*\nTheme Name: Astra CI Parent\nVersion: 9.9.9\n*/\n" );
	file_put_contents( $astra_dir . '/index.php', "<?php // Astra CI parent.\n" );
	file_put_contents( $astra_dir . '/header.php', "<?php // Astra CI parent header.\n" );
	file_put_contents( $child_dir . '/style.css', "/*\nTheme Name: Astra Child CI\nTemplate: astra\nVersion: 9.9.9\n*/\n" );
	file_put_contents( $child_dir . '/index.php', "<?php // Astra CI child.\n" );
	file_put_contents( $child_dir . '/functions.php', "<?php // Astra CI child functions.\n" );
	file_put_contents( $child_dir . '/header.php', "<?php // Astra CI child header override.\n" );

	// Excluded dependency/VCS trees deliberately contain enough files that a
	// post-traversal filter would inflate the scan. The adapter must prune these
	// directories before descent while still seeing the real child override.
	foreach ( array( 'vendor/deep', 'node_modules/pkg', '.git/objects' ) as $excluded_dir ) {
		$full = $child_dir . '/' . $excluded_dir;
		wp_mkdir_p( $full );
		for ( $i = 0; $i < 40; ++$i ) file_put_contents( $full . '/fixture-' . $i . '.php', "<?php // excluded fixture.\n" );
	}

	file_put_contents( $mu_file, "<?php\n/*\nPlugin Name: MAD4B CI Must-Use Plugin\nVersion: 9.9.9\n*/\n" );
	file_put_contents( $dropin_file, "<?php\n/*\nPlugin Name: MAD4B CI Install Drop-in\nVersion: 9.9.9\n*/\n" );
	if ( function_exists( 'wp_clean_themes_cache' ) ) wp_clean_themes_cache( true );
	if ( function_exists( 'wp_clean_plugins_cache' ) ) wp_clean_plugins_cache( true );

	switch_theme( 'astra-child-ci' );
	if ( function_exists( 'wp_clean_themes_cache' ) ) wp_clean_themes_cache( true );

	$mu = wp_get_ability( 'mu-plugins/inventory' )->execute();
	$check( ! is_wp_error( $mu ) && 'mad4b.mu-plugin-inventory.v1' === $mu['contract'], 'MU plugin inventory contract failed.' );
	$mu_files = wp_list_pluck( $mu['items'], 'plugin_file' );
	$check( in_array( 'mad4b-ci-mu.php', $mu_files, true ), 'Runtime MU plugin fixture was not discovered.' );
	$check( 'must_use_always_loaded_no_activation_toggle' === $mu['activation_model'], 'MU plugin lifecycle semantics drifted.' );

	$dropins = wp_get_ability( 'drop-ins/inventory' )->execute();
	$check( ! is_wp_error( $dropins ) && 'mad4b.wordpress-dropin-inventory.v1' === $dropins['contract'], 'Drop-in inventory contract failed.' );
	$dropin_files = wp_list_pluck( $dropins['items'], 'file' );
	$check( in_array( 'install.php', $dropin_files, true ), 'Runtime drop-in fixture was not discovered.' );

	$themes = wp_get_ability( 'themes/inventory' )->execute();
	$check( ! is_wp_error( $themes ) && 'mad4b.theme-runtime-inventory.v1' === $themes['contract'], 'Theme runtime inventory contract failed.' );
	$check( 'astra-child-ci' === $themes['active_stylesheet'] && 'astra' === $themes['active_template'], 'Parent/child active theme topology was not resolved.' );

	$astra = wp_get_ability( 'astra-theme/status' )->execute();
	$check( ! is_wp_error( $astra ) && ! empty( $astra['available'] ), 'Astra runtime adapter did not detect the parent theme.' );
	$check( '9.9.9' === $astra['version'] && ! empty( $astra['active_template'] ), 'Astra runtime status mismatch.' );
	$check( in_array( 'astra-child-ci', $astra['child_themes'], true ), 'Astra child relation was not detected.' );
	$check( empty( $astra['theme_mods']['values_exposed'] ), 'Astra theme mod values were exposed.' );

	$children = wp_get_ability( 'astra-child-theme/inventory' )->execute();
	$check( ! is_wp_error( $children ) && 1 <= (int) $children['count'], 'Astra Child runtime adapter did not detect a child theme.' );
	$child = null;
	foreach ( $children['items'] as $item ) if ( 'astra-child-ci' === $item['stylesheet'] ) $child = $item;
	$check( is_array( $child ), 'Astra Child CI fixture missing from specialized inventory.' );
	$fs = $child['filesystem'];
	$check( in_array( 'header.php', $fs['override_files'], true ), 'Astra Child override inventory did not resolve a parent override.' );
	$check( $fs['returned_file_count'] <= 300, 'Astra Child filesystem scan escaped its returned-file bound.' );
	$check( $fs['scanned_entry_count'] <= $fs['scan_entry_budget'], 'Astra Child filesystem scan escaped its entry budget.' );
	$check( 3 <= (int) $fs['pruned_directory_count'], 'Astra Child scan did not prune dependency/VCS directories before traversal.' );
	$check( (int) $fs['scanned_entry_count'] < 60, 'Astra Child scan traversed excluded dependency/VCS trees.' );
	foreach ( $fs['files'] as $relative ) {
		$check( ! preg_match( '#(^|/)(?:vendor|node_modules|\.git)(?:/|$)#', $relative ), 'Excluded dependency/VCS path leaked from Astra Child inventory: ' . $relative );
	}

	$theme = wp_get_ability( 'themes/get-theme' )->execute( array( 'stylesheet' => 'astra-child-ci' ) );
	$check( ! is_wp_error( $theme ) && 'child_theme' === $theme['component_type'] && 'astra' === $theme['template'], 'Theme lookup did not preserve child topology.' );

	$all = wp_get_ability( 'runtime-components/inventory' )->execute();
	$check( ! is_wp_error( $all ) && 'mad4b.runtime-components.v1' === $all['contract'], 'Aggregate runtime component inventory contract failed.' );
	$check( in_array( 'mu_plugin', $all['component_types'], true ) && in_array( 'drop_in', $all['component_types'], true ) && in_array( 'child_theme', $all['component_types'], true ), 'Aggregate component type coverage is incomplete.' );
	$check( 1 <= (int) $all['counts']['mu_plugins'] && 1 <= (int) $all['counts']['drop_ins'] && 1 <= (int) $all['counts']['astra_children'], 'Aggregate runtime counts did not include CI fixtures.' );
	$check( empty( $all['authorizing'] ) && empty( $all['mutation_exposed'] ), 'Aggregate runtime inventory became authorizing.' );

	$ui = MAD4B_SCP_Runtime_Components_Admin_UI::snapshot();
	$check( ! is_wp_error( $ui ) && 'mad4b.runtime-components.v1' === $ui['contract'], 'Runtime Components Admin snapshot failed.' );
} finally {
	if ( get_stylesheet() !== $previous_stylesheet ) switch_theme( $previous_stylesheet );
	if ( null === $existing_dropin ) @unlink( $dropin_file ); else file_put_contents( $dropin_file, $existing_dropin );
	@unlink( $mu_file );
	if ( $created_mu_dir ) @rmdir( $mu_dir );
	if ( $created_child ) $remove_tree( $child_dir );
	if ( $created_astra ) $remove_tree( $astra_dir );
	if ( function_exists( 'wp_clean_themes_cache' ) ) wp_clean_themes_cache( true );
	if ( function_exists( 'wp_clean_plugins_cache' ) ) wp_clean_plugins_cache( true );
}

echo "mad4b.site-control-plane.runtime-component-adapters.v2: PASS\n";
