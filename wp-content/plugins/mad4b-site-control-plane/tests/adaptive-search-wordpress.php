<?php
/** Run with WP-CLI in a disposable database, with the exact Control Plane activated. */
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'MAD4B_SCP_Search_WordPress_Store' ) ) { fwrite( STDERR, "Disposable WordPress and exact plugin are required.\n" ); exit( 1 ); }
$assertions = 0;
$check = static function ( $condition, $message ) use ( &$assertions ) { ++$assertions; if ( ! $condition ) throw new RuntimeException( $message ); };
$backend = new MAD4B_SCP_Search_WordPress_Store(); $key = 'mad4b_asi_ci_' . bin2hex( random_bytes( 12 ) );
$check( $backend->compare_exchange( $key, null, array( 'revision' => 1, 'case' => 'Original' ) ), 'atomic create' );
$check( ! $backend->compare_exchange( $key, null, array( 'revision' => 9 ) ), 'duplicate create denied' );
get_option( $key ); // Warm the real WordPress option cache before a database CAS.
$check( ! $backend->compare_exchange( $key, array( 'revision' => 1, 'case' => 'original' ), array( 'revision' => 3 ) ), 'case-insensitive database collation cannot weaken exact comparison' );
$check( $backend->compare_exchange( $key, array( 'revision' => 1, 'case' => 'Original' ), array( 'revision' => 2 ) ), 'exact CAS' );
$check( 2 === get_option( $key )['revision'] && 2 === $backend->read( $key )['revision'], 'warm option cache cannot resurrect old state' );

$check( function_exists( 'pcntl_fork' ), 'multi-process test required' ); global $wpdb; $wpdb->close(); $children = array();
for ( $worker = 0; $worker < 8; ++$worker ) {
	$pid = pcntl_fork();
	if ( 0 === $pid ) { $wpdb = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ); $wpdb->set_prefix( $GLOBALS['table_prefix'] ); $winner = $backend->compare_exchange( $key, array( 'revision' => 2 ), array( 'revision' => 3, 'worker' => $worker ) ); exit( $winner ? 3 : 0 ); }
	$check( $pid > 0, 'worker created' ); $children[] = $pid;
}
$winners = 0; foreach ( $children as $pid ) { pcntl_waitpid( $pid, $status ); $check( pcntl_wifexited( $status ), 'worker exited' ); if ( 3 === pcntl_wexitstatus( $status ) ) ++$winners; else $check( 0 === pcntl_wexitstatus( $status ), 'known CAS denial' ); }
$wpdb = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ); $wpdb->set_prefix( $GLOBALS['table_prefix'] );
$check( 1 === $winners && 3 === $backend->read( $key )['revision'], 'one database CAS winner from eight real WordPress processes' );
delete_option( $key );

register_post_type( 'asi_new_cpt', array( 'public' => true, 'has_archive' => true, 'rewrite' => array( 'slug' => 'asi-items' ) ) );
register_taxonomy( 'asi_new_tax', 'asi_new_cpt', array( 'public' => true, 'rewrite' => array( 'slug' => 'asi-groups' ) ) );
$post_id = wp_insert_post( array( 'post_type' => 'asi_new_cpt', 'post_title' => 'Search fixture', 'post_content' => 'Disposable content', 'post_status' => 'publish' ) );
$term = wp_insert_term( 'Search group', 'asi_new_tax' ); $check( ! is_wp_error( $term ) && $post_id > 0, 'public CPT/taxonomy fixture' );
$discovery = new MAD4B_SCP_Search_WordPress_Discovery(); $page = $discovery->surfaces( 0, 100 ); $types = array_column( $page['items'], 'surface_type' );
foreach ( array( 'CONTENT_OBJECT', 'TERM', 'TERM_ARCHIVE', 'POST_TYPE_ARCHIVE', 'HOME' ) as $type ) $check( in_array( $type, $types, true ), 'real inventory ' . $type );
$archive = null; $content = null;
foreach ( $page['items'] as $surface ) {
	if ( 'POST_TYPE_ARCHIVE' === $surface['surface_type'] && 'asi_new_cpt' === $surface['object_ref']['id'] ) $archive = $surface;
	if ( 'CONTENT_OBJECT' === $surface['surface_type'] && $post_id === $surface['object_ref']['id'] ) $content = $surface;
}
$check( is_array( $archive ) && is_array( $content ), 'exact CPT surfaces discovered' );
$surface_policy = MAD4B_SCP_Search_Context::policy()['defaults']['surface_policy'];
get_post_type_object( 'asi_new_cpt' )->has_archive = false;
$check( is_wp_error( MAD4B_SCP_Search_Surfaces::refresh( $archive, $surface_policy ) ), 'disabled live archive cannot reuse inventory eligibility' );
get_post_type_object( 'asi_new_cpt' )->has_archive = true; get_post_type_object( 'asi_new_cpt' )->publicly_queryable = false;
$check( is_wp_error( MAD4B_SCP_Search_Surfaces::refresh( $content, $surface_policy ) ), 'CPT visibility change is enforced before capture' );
get_post_type_object( 'asi_new_cpt' )->publicly_queryable = true;
wp_delete_post( $post_id, true ); wp_delete_term( $term['term_id'], 'asi_new_tax' );

$check( function_exists( 'wp_get_ability' ), 'real Abilities API loaded' );
$check( is_object( wp_get_ability( 'mad4b/search-status' ) ), 'search registration lifecycle completed' );
$check( count( MAD4B_SCP_Adaptive_Search_Intelligence::ability_names( 'write' ) ) >= 8 && count( MAD4B_SCP_Adaptive_Search_Intelligence::ability_names( 'read' ) ) >= 10, 'nonempty governed ability inventory' );
foreach ( MAD4B_SCP_Adaptive_Search_Intelligence::ability_names( 'read' ) as $name ) $check( in_array( $name, MAD4B_SCP_Servers::core_tools( 'mad4b-read' ), true ), 'read ability mounted: ' . $name );
foreach ( MAD4B_SCP_Adaptive_Search_Intelligence::ability_names( 'write' ) as $name ) {
	$ability = wp_get_ability( $name ); $check( is_object( $ability ), 'write ability registered' );
	$check( false === $ability->get_meta()['annotations']['readonly'], 'spend/config operation never read-only' );
	$check( MAD4B_SCP_Authorization::execution_boundary_verified( $ability ), 'existing execution wrapper applied' );
	$check( in_array( $name, MAD4B_SCP_Servers::core_tools( 'mad4b-write' ), true ), 'write ability mounted through governed catalog' );
}
$_GET = array( 'page' => 'mad4b-search-intelligence' ); ob_start(); MAD4B_SCP_Search_Experience::render(); $html = ob_get_clean();
$check( false !== strpos( $html, 'Search Intelligence' ) && false !== strpos( $html, 'UNCONFIGURED' ), 'unconfigured operator page renders on real WordPress' );
$check( false !== strpos( $html, '<nav aria-label=' ) && false !== strpos( $html, '<th scope=' ), 'navigation and table have accessible semantics' );
$check( false !== strpos( $html, 'Search providers' ) && false !== strpos( $html, 'SerpApi' ) && false !== strpos( $html, 'DataForSEO' ), 'provider enrollment discoverable before a profile exists' );
$check( ! preg_match( '/name="credentials\[[^"]+\]" value="[^"]+"/', $html ), 'saved credentials are never populated in real WordPress forms' );
$check( false === strpos( $html, 'asi.local.' ), 'opaque secret handles are not rendered' );
$check( has_action( 'admin_post_mad4b_search_provider_connection', array( 'MAD4B_SCP_Search_Provider_Connections', 'post' ) ) !== false, 'local nonce-bound enrollment handler registered through plugin boot' );
echo wp_json_encode( array( 'contract' => 'mad4b.adaptive-search-wordpress-evidence.v1', 'status' => 'PASS', 'evidence_class' => 'disposable_wordpress_mysql', 'assertions' => $assertions, 'cas_workers' => 8, 'cas_winners' => $winners, 'authorizing' => false ), JSON_UNESCAPED_SLASHES ) . "\n";
