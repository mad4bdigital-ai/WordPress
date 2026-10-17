<?php
/**
 * Disposable API-boundary test for security provider federation.
 * No installed WordPress, external HTTP, shell, SQL, plugin mutation or secrets.
 */
define( 'ABSPATH', sys_get_temp_dir() . '/' );
$GLOBALS['installed'] = array();
$GLOBALS['active'] = array();
$GLOBALS['abilities'] = array();
$GLOBALS['invocations'] = array();
$GLOBALS['exposure'] = false;
$GLOBALS['routes'] = array();
$GLOBALS['responses'] = array();

class WP_Error {
    private $code;
    public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $v ) ); }
function get_plugins() { return $GLOBALS['installed']; }
function is_plugin_active( $file ) { return ! empty( $GLOBALS['active'][ $file ] ); }
function is_plugin_active_for_network( $file ) { return false; }
function wp_has_ability( $name ) { return isset( $GLOBALS['abilities'][ $name ] ); }
function wp_get_ability( $name ) { return $GLOBALS['abilities'][ $name ]; }
class AAL_Abilities {
    public static function is_agent_exposure_enabled() { return $GLOBALS['exposure']; }
}
class StubAbility {
    private $value;
    public function __construct( $value ) { $this->value = $value; }
    public function execute( $input = array() ) {
        $GLOBALS['invocations'][] = $input;
        return $this->value;
    }
}
class WP_REST_Request {
    public $method;
    public $route;
    public function __construct( $method, $route ) { $this->method = $method; $this->route = $route; }
}
class StubRestServer {
    public function get_routes() { return $GLOBALS['routes']; }
}
function rest_get_server() { return new StubRestServer(); }
class StubRestResponse {
    private $status;
    private $data;
    public function __construct( $status, $data ) { $this->status = $status; $this->data = $data; }
    public function get_status() { return $this->status; }
    public function get_data() { return $this->data; }
}
function rest_do_request( $request ) {
    $GLOBALS['invocations'][] = array( 'method' => $request->method, 'route' => $request->route );
    return $GLOBALS['responses'][ $request->route ] ?? new WP_Error( 'denied' );
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-official-security-packages.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-security-provider-evidence.php';
$checks = 0;
$expect = static function( $condition, $label ) use ( &$checks ) {
    ++$checks;
    if ( ! $condition ) throw new RuntimeException( 'Check ' . $checks . ': ' . $label );
};
$get = static function( $name ) { return MAD4B_SCP_Security_Provider_Evidence::collect( $name ); };
$wordfence = 'security-wordfence';
$activity = 'security-activity-log';
$vuln = 'security-wpvulnerability';

$missing = $get( $wordfence );
$expect( $missing['state'] === 'unavailable' && $missing['reason'] === 'plugin_not_installed', 'missing provider' );
$expect( !$missing['site_clean_certified'] && !$missing['complete'], 'missing cannot be clean' );
$GLOBALS['installed'] = array(
    'wordfence/wordfence.php' => array( 'Version' => '8.0.1' ),
    'aryo-activity-log/aryo-activity-log.php' => array( 'Version' => '2.15.0' ),
    'wpvulnerability/wpvulnerability.php' => array( 'Version' => '4.3.1' ),
);
$expect( $get( $activity )['reason'] === 'plugin_not_active', 'inactive provider blocked' );
$GLOBALS['active'] = array_fill_keys( array_keys( $GLOBALS['installed'] ), true );

$expect( $get( $activity )['reason'] === 'activity_log_agent_exposure_disabled', 'agent exposure opt-in honored' );
$GLOBALS['exposure'] = true;
$expect( $get( $activity )['reason'] === 'native_read_abilities_unavailable', 'native Abilities required' );
$GLOBALS['abilities']['activity-log/summarize-activity'] = new StubAbility( array(
    'total' => 12, 'by_channel' => array( 'rest' => 7, 'abilities' => 3 ),
    'top_users' => array( array( 'email' => 'private@example.test' ) ),
) );
$GLOBALS['abilities']['activity-log/detect-suspicious-events'] = new StubAbility( array(
    'findings' => array( array(
        'rule' => 'new_admin', 'severity' => 'high', 'count' => 1,
        'actor' => 'sensitive-user', 'ip' => '192.0.2.10', 'raw_html' => '<script>alert(1)</script>',
    ) ),
    'truncated' => false,
) );
$report = $get( $activity );
$expect( $report['state'] === 'observed' && $report['events_total'] === 12, 'activity summary' );
$expect( $report['findings'][0]['rule'] === 'new_admin', 'suspicious rules' );
$expect( $report['channels']['rest'] === 7, 'allowed channel aggregation' );
$expect( !str_contains( json_encode( $report ), 'sensitive-user' )
    && !str_contains( json_encode( $report ), 'private@example.test' ), 'sensitive log data redacted' );
$expect( !$report['site_clean_certified'] && !$report['complete'], 'no false clean claim' );
$denied = MAD4B_SCP_Security_Provider_Evidence::collect( $activity, '7d' );
$expect( $denied['period'] === '7d', 'bounded date window' );
$expect( MAD4B_SCP_Security_Provider_Evidence::collect( $activity, '90d' )['state'] === 'unavailable', 'unbounded period rejected' );
$GLOBALS['abilities']['activity-log/detect-suspicious-events'] = new StubAbility( new WP_Error( 'forbidden' ) );
$expect( $get( $activity )['reason'] === 'native_suspicious_read_denied_or_invalid', 'ability denial observed' );

$expect( $get( $vuln )['reason'] === 'provider_route_not_registered', 'route absence blocked' );
$GLOBALS['routes']['/wpvulnerability/v1/plugins'] = array( 'GET' );
$GLOBALS['responses']['/wpvulnerability/v1/plugins'] = new StubRestResponse( 403, array() );
$expect( $get( $vuln )['reason'] === 'provider_rest_denied_or_failed', 'REST permission denial' );
$GLOBALS['responses']['/wpvulnerability/v1/plugins'] = new StubRestResponse( 200, array(
    array( 'slug' => 'secret-plugin', 'vulnerabilities' => array( array( 'title' => 'some cve' ), array( 'title' => 'other cve' ) ) ),
    array( 'slug' => 'other-plugin', 'vulnerabilities' => array( array( 'title' => 'third cve' ) ) ),
) );
$report = $get( $vuln );
$expect( $report['state'] === 'observed' && $report['vulnerabilities_reported'] === 3, 'official vulnerable component aggregation' );
$expect( $report['affected_components'] === 2, 'affected components' );
$expect( !str_contains( json_encode( $report ), 'secret-plugin' ), 'plugin identity and details redacted' );
$expect( !$report['site_clean_certified'] && $report['underlying_provider_network_possible'], 'not a clean attestation or direct fetch' );
$last_call = end( $GLOBALS['invocations'] );
$expect( $last_call['route'] === '/wpvulnerability/v1/plugins' && $last_call['method'] === 'GET', 'fixed local REST request only' );
$GLOBALS['responses']['/wpvulnerability/v1/plugins'] = new StubRestResponse( 200, array( 'untrusted' => 'not a list' ) );
$expect( $get( $vuln )['reason'] === 'provider_payload_unrecognized', 'schema drift not accepted' );

$expect( $get( $wordfence )['reason'] === 'unreviewed_or_missing_optional_companion', 'unverified core Wordfence API not assumed' );
$GLOBALS['abilities']['wpcli-for-wordfence/issues-count'] = new StubAbility( array( 'new' => 2, 'total' => 4 ) );
$GLOBALS['abilities']['wpcli-for-wordfence/scan-status'] = new StubAbility( array(
    'running' => false, 'last_scan_timestamp' => 1720000000, 'internal_path' => '/private/config',
) );
$GLOBALS['abilities']['wpcli-for-wordfence/firewall-status'] = new StubAbility( array( 'enabled' => true, 'secret' => 'private-key' ) );
$report = $get( $wordfence );
$expect( $report['state'] === 'observed_unattested' && $report['issues_new'] === 2, 'companion issues count' );
$expect( !$report['companion_behavior_certified'] && !$report['enforcement_verified'], 'third party untrusted until independently certified' );
$expect( $report['firewall_reports_enabled'] === true && $report['scan_running'] === false, 'provider reports reflected not verified' );
$expect( !str_contains( json_encode( $report ), '/private/config' ) && !str_contains( json_encode( $report ), 'private-key' ), 'companion secrets redacted' );
$GLOBALS['abilities']['wpcli-for-wordfence/issues-count'] = new StubAbility( new WP_Error( 'unauthorized' ) );
$expect( $get( $wordfence )['reason'] === 'optional_companion_read_failed', 'companion permission denied' );
$expect( empty( array_filter( $GLOBALS['invocations'], static function( $x ) {
    return is_array( $x ) && isset( $x['method'] ) && $x['method'] !== 'GET';
} ) ), 'never POST/execute arbitrary REST' );
echo "SECURITY_PROVIDER_EVIDENCE_RUNTIME: PASS ($checks checks)\n";
