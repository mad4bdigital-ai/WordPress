<?php
// Standalone provider-policy fixture. Only disposable /tmp test configuration is written.
define( 'ABSPATH', __DIR__ );
$root = tempnam( sys_get_temp_dir(), 'skill-provider-' );
if ( false === $root ) { fwrite( STDERR, "Unable to reserve isolated config path\n" ); exit(1); }
unlink( $root );
if ( ! mkdir( $root . '/config', 0700, true ) ) exit(1);
register_shutdown_function( static function () use ( $root ) {
    @unlink( $root . '/config/external-skill-source-providers.json' );
    @rmdir( $root . '/config' ); @rmdir( $root );
} );
define( 'MAD4B_SCP_DIR', $root . '/' );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $obj ) { return $obj instanceof WP_Error; }
require_once dirname( __DIR__ ) . '/includes/class-external-skill-sources.php';
$config = json_decode( file_get_contents( dirname(__DIR__) . '/config/external-skill-source-providers.json' ), true );
if ( ! is_array( $config ) ) exit(1);
$method = new ReflectionMethod( 'SCP_External_Skill_Sources', 'github_origin' );
$method->setAccessible( true );
$cases = array(
    'approved' => array( 'field' => '', 'value' => null, 'allowed' => true ),
    'rogue_https_host' => array( 'field' => 'origin', 'value' => 'https://rogue.example', 'allowed' => false ),
    'subdomain_spoofing' => array( 'field' => 'origin', 'value' => 'https://api.github.com.rogue.example', 'allowed' => false ),
    'http_downgrade' => array( 'field' => 'origin', 'value' => 'http://api.github.com', 'allowed' => false ),
    'nondefault_port' => array( 'field' => 'origin', 'value' => 'https://api.github.com:443', 'allowed' => false ),
    'path_origin' => array( 'field' => 'origin', 'value' => 'https://api.github.com/v3', 'allowed' => false ),
    'private_visibility' => array( 'field' => 'visibility', 'value' => 'private', 'allowed' => false ),
    'write_mode' => array( 'field' => 'mode', 'value' => 'read_write', 'allowed' => false ),
    'wrong_api_version' => array( 'field' => 'git_tree_api', 'value' => 'v4', 'allowed' => false ),
);
foreach ( $cases as $name => $case ) {
    $edited = $config;
    if ( '' !== $case['field'] ) $edited['providers']['github'][ $case['field'] ] = $case['value'];
    file_put_contents( $root . '/config/external-skill-source-providers.json', json_encode( $edited ) );
    $res = $method->invoke( null );
    if ( $case['allowed'] ) {
        $ok = ( 'https://api.github.com' === $res );
    } else {
        $ok = is_wp_error( $res ) && 'skill_source_provider_origin_invalid' === $res->get_error_code();
    }
    if ( ! $ok ) {
        fwrite( STDERR, 'FAIL provider guard ' . $name . ': ' .
            ( is_wp_error( $res ) ? $res->get_error_code() : (string) $res ) . PHP_EOL );
        exit(1);
    }
}
echo "External Skill Source Provider policy: PASS\n";
