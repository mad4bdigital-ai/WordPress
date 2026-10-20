<?php
namespace Elementor { class Plugin { public static $instance; } }
namespace ETG\DynamicFilterSEOBridge\Config { class Configuration {} }
namespace {
define( 'ABSPATH', __DIR__ );
class WP_Error { public function __construct( $code, $message = '' ) {} }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function apply_filters( $name, $allowed, ...$args ) { return $GLOBALS['tag_policy'] ?? $allowed; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
class MAD4B_SCP_Adapter_Base {
    public $registered_names = array();
    protected function add_ability( ...$args ) { $this->registered_names[] = $args[0]; }
    protected function schema( ...$args ) { return array(); }
}
class Fixture_Tag {
    private $name;
    public function __construct( $name ) { $this->name = $name; }
    public function get_name() { return $this->name; }
}
class Fixture_Manager {
    public $registered = array( 'product-price', 'course-title', 'service-location', 'post-title' );
    public function create_tag( $id, $name, $settings ) {
        return in_array( $name, $this->registered, true ) ? new Fixture_Tag( $name ) : false;
    }
    public function tag_data_to_tag_text( $id, $name, $settings ) {
        return json_encode( array( 'id' => $id, 'name' => $name, 'settings' => $settings ) );
    }
    public function tag_text_to_tag_data( $tag ) { return json_decode( $tag, true ); }
}
\Elementor\Plugin::$instance = (object) array( 'dynamic_tags' => new Fixture_Manager() );
require dirname( __DIR__ ) . '/includes/adapters/class-mad4b-scp-elementor-adapter.php';
$a = new MAD4B_SCP_Elementor_Adapter();
$a->register_abilities();
if ( in_array( 'elementor/set-etg-dynamic-tag', $a->registered_names, true )
    || in_array( 'elementor/set-etg-dynamic-tag', $a->ability_names()['content'], true ) )
    throw new \RuntimeException( 'Absent optional provider leaked its writer into the generic catalog' );
$method = new \ReflectionMethod( $a, 'allowed_dynamic_tag_name' );
$method->setAccessible( true );
function neutral_check( $ok, $why ) { if ( ! $ok ) throw new \RuntimeException( $why ); }
$manager = \Elementor\Plugin::$instance->dynamic_tags;
foreach ( $manager->registered as $name ) {
    $tag = $manager->tag_data_to_tag_text( 'source123', $name, array( 'key' => 'existing_value' ) );
    neutral_check( $name === $method->invoke( $a, $tag ), 'Registered non-tourism source rejected: ' . $name );
}
foreach ( array( 'unknown', 'etg-filter-title', 'bad/name' ) as $name ) {
    $tag = $manager->tag_data_to_tag_text( 'source123', $name, array() );
    neutral_check( '' === $method->invoke( $a, $tag ), 'Unregistered source accepted' );
}
$tag = $manager->tag_data_to_tag_text( 'source123', 'post-title', array() );
neutral_check( '' === $method->invoke( $a, $tag . $tag ), 'Concatenated bindings accepted' );
neutral_check( '' === $method->invoke( $a, str_repeat( 'x', 4097 ) ), 'Oversized source accepted' );
$spoof = $manager->tag_data_to_tag_text( 'source123', 'unknown', array( 'description' => 'post-title' ) );
neutral_check( '' === $method->invoke( $a, $spoof ), 'A name in settings bypassed registration' );
$GLOBALS['tag_policy'] = false;
neutral_check( '' === $method->invoke( $a, $tag ), 'Site denial ignored' );
$GLOBALS['tag_policy'] = true;
unset( \Elementor\Plugin::$instance->dynamic_tags );
neutral_check( '' === $method->invoke( $a, $tag ), 'Missing runtime accepted' );
neutral_check( ! MAD4B_SCP_Elementor_Bridge_Compatibility::available(), 'Optional integration required by default' );
neutral_check( is_wp_error( MAD4B_SCP_Elementor_Bridge_Compatibility::settings( 'etg-filter-title', array() ) ), 'Unavailable optional builder accepted' );
// Installed provider identity and exact registered tag enable compatibility;
// neither is a dependency of the generic source-copy operation.
define( 'ETG_DFSB_VERSION', 'fixture-only' );
\Elementor\Plugin::$instance->dynamic_tags = $manager;
$manager->registered[] = 'etg-filter-title';
neutral_check( MAD4B_SCP_Elementor_Bridge_Compatibility::available(), 'Installed optional integration not detected' );
$a->registered_names = array();
$a->register_abilities();
neutral_check( in_array( 'elementor/set-etg-dynamic-tag', $a->registered_names, true )
    && in_array( 'elementor/set-etg-dynamic-tag', $a->ability_names()['content'], true ), 'Installed compatibility writer missing' );
$settings = MAD4B_SCP_Elementor_Bridge_Compatibility::settings( 'etg-filter-title', array() );
neutral_check( is_array( $settings ) && 'yes' === $settings['live_update'], 'Optional provider settings drifted' );
$build = new \ReflectionMethod( $a, 'build_elementor_dynamic_tag' );
$build->setAccessible( true );
$tag = $build->invoke( $a, 7, 'element1', 'title', 'etg-filter-title', $settings );
neutral_check( is_string( $tag ) && 'etg-filter-title' === $method->invoke( $a, $tag ), 'Optional registered tag roundtrip failed' );
echo "ELEMENTOR_SITE_NEUTRAL: PASS (retail, education, services, registered exact source and site denial)\n";
}
