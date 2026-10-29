<?php
/** Read-only hermetic regression; no WordPress database or site runtime. */
declare(strict_types=1);
define( 'ABSPATH', '/tmp/etg-rest-meta-contract/' );
$GLOBALS['wp_post_types'] = array( 'attachment' => (object) array( 'public' => true ) );
$GLOBALS['wp_taxonomies'] = array();
$GLOBALS['hooks'] = array();
$GLOBALS['post_meta_registered'] = array();
function add_action( $name, $cb, $priority = 10 ) { $GLOBALS['hooks'][$name][$priority][] = $cb; }
function register_post_meta( $pt, $key, $args ) { $GLOBALS['post_meta_registered'][] = array( $pt, $key, $args ); }
function register_term_meta( $taxonomy, $key, $args ) { throw new Exception( 'unexpected term registration' ); }
function assert_condition( $bool, $reason ) { if ( ! $bool ) throw new Exception( 'FAIL: ' . $reason ); }
class ETG_Test_WPDB {
    public $postmeta = 'wp_postmeta';
    public $posts = 'wp_posts';
    public $termmeta = 'wp_termmeta';
    public $term_taxonomy = 'wp_term_taxonomy';
    public $queries = array();
    public function prepare( $query, ...$args ) { return str_replace( '%s', "'" . $args[0] . "'", $query ); }
    public function get_col( $query ) { $this->queries[] = $query; return array(); }
}
$GLOBALS['wpdb'] = new ETG_Test_WPDB();
require __DIR__ . '/snippet24-rest-meta-exposure-v1.2.1.php';
function run_rest_init_fixture() {
    ksort( $GLOBALS['hooks']['rest_api_init'] );
    foreach ( $GLOBALS['hooks']['rest_api_init'] as $group ) foreach ( $group as $cb ) $cb();
}
run_rest_init_fixture();
assert_condition( count( $GLOBALS['wpdb']->queries ) === 0, 'attachments perform zero SQL scans' );
assert_condition( count( $GLOBALS['post_meta_registered'] ) === 6, 'Elementor explicit registrations preserved' );
$GLOBALS['wp_post_types']['tours-and-activities'] = (object) array( 'public' => true );
$GLOBALS['wpdb']->queries = array();
run_rest_init_fixture();
assert_condition( count( $GLOBALS['wpdb']->queries ) === 1, 'other public CPT scanning unchanged in bounded hotfix' );
assert_condition( strpos( $GLOBALS['wpdb']->queries[0], 'tours-and-activities' ) !== false, 'query belongs to CPT, not attachment' );
assert_condition( strpos( $GLOBALS['wpdb']->queries[0], "'attachment'" ) === false, 'no attachment SQL' );
assert_condition( count( $GLOBALS['post_meta_registered'] ) === 12, 'Elementor registration continuity' );
echo "ETG_SNIPPET24_ATTACHMENT_NO_SCAN: PASS 6 assertions\n";
