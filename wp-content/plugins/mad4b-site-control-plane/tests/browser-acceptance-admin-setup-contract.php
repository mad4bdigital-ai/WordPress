<?php
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error { private $code; public function __construct( $code ) { $this->code=$code; } public function get_error_code() { return $this->code; } }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
$GLOBALS['browser_setting'] = array();
function get_option( $name, $default = false ) { return isset( $GLOBALS['browser_setting'][$name] ) ? $GLOBALS['browser_setting'][$name] : $default; }
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-browser-acceptance-admin-ui.php';
function check_browser( $ok, $message ) { if ( ! $ok ) { fwrite(STDERR,"FAIL $message\n"); exit(1); } }
$c='MAD4B_SCP_Browser_Acceptance_Admin_UI';
$defaults=$c::public_selection();
check_browser( $defaults['preference_valid'] === true && $defaults['preference_source'] === 'default_observed' &&
  preg_match( '/^[a-f0-9]{32}$/D', $defaults['configuration_revision'] ), 'pristine site uses read-only observed default without manual preference save' );
check_browser( count($c::managed_executors()) === 4, 'catalog' );
foreach (array('auto','cloudflare','browserbase','browserless','steel') as $p) check_browser( !is_wp_error($c::normalize(array('executor'=>$p,'profile_id'=>'s-1'))), 'valid executor' );
foreach (array(
array('executor'=>'bad','profile_id'=>''),
array('executor'=>array('auto'),'profile_id'=>''),
array('executor'=>'auto','profile_id'=>'https://bad.test'),
array('executor'=>'auto','profile_id'=>'','api_key'=>'secret'),
array('executor'=>'auto','profile_id'=>'','javascript'=>'alert(1)'),
array('executor'=>'auto','profile_id'=>'','site_provider_id'=>'https://unsafe.test'),
array('executor'=>'auto','profile_id'=>str_repeat('x',65))
) as $bad) check_browser( is_wp_error($c::normalize($bad)), 'denied unsafe input' );
$GLOBALS['browser_setting'][$c::OPTION]=array('executor'=>'steel','profile_id'=>'site-1','secret'=>'never-expose');
$pref=$c::public_selection();
check_browser( $pref['executor']==='steel' && !isset($pref['secret']), 'credential redaction' );
foreach (array('credential_verified','external_runner_connected','site_provider_registered_by_preference','authorizing') as $k) check_browser( $pref[$k] === false, 'no false authority' );
$GLOBALS['browser_setting'][$c::OPTION]=array('executor'=>'auto','profile_id'=>'','site_provider_id'=>'etg-dfsb');
check_browser( $c::public_selection()['site_provider_id'] === 'etg-dfsb', 'site provider preference projected' );
check_browser( '' === $c::public_selection()['configuration_revision'], 'legacy preference has no execution revision' );
$revision='abcdef0123456789abcdef0123456789';
$GLOBALS['browser_setting'][$c::OPTION] = array('executor'=>'auto','site_provider_id'=>'etg-dfsb','profile_id'=>'tours','configuration_revision'=>$revision);
check_browser( $c::public_selection()['configuration_revision'] === $revision, 'revision projected exactly' );
check_browser( is_wp_error( $c::normalize( array('configuration_revision'=>'not-a-revision') ) ), 'invalid revision rejected' );
check_browser( is_wp_error( $c::normalize( array('configuration_revision'=>array('bad')) ) ), 'array revision rejected' );
$GLOBALS['browser_setting'][$c::OPTION]=array('executor'=>'auto','site_provider_id'=>'site-a','profile_id'=>'royal');
check_browser( $c::runtime_target_guard('site-a','royal') === true, 'exact configured target permitted' );
check_browser( is_wp_error($c::runtime_target_guard('site-b','royal')), 'mismatched selected provider denied' );
check_browser( is_wp_error($c::runtime_target_guard('site-a','other')), 'mismatched profile denied' );
$GLOBALS['browser_setting'][$c::OPTION]=array('executor'=>'not-configured','profile_id'=>'');
check_browser( is_wp_error($c::runtime_target_guard('site-a','royal')), 'corrupt preference cannot run browser acceptance' );
check_browser( $c::public_selection()['preference_valid'] === false, 'invalid stored preference must be visible to external runner' );
check_browser( $c::revision_guard( '', array() ) === true, 'new form accepts empty revision' );
check_browser( $c::revision_guard( $revision, array( 'executor'=>'auto', 'configuration_revision'=>$revision ) ) === true, 'current exact revision accepted' );
check_browser( is_wp_error( $c::revision_guard( '', array( 'executor'=>'auto', 'configuration_revision'=>$revision ) ) ), 'stale open tab denied' );
check_browser( is_wp_error( $c::revision_guard( array( 'nested'=>'bad' ), array() ) ), 'nested revision denied' );
check_browser( is_wp_error( $c::revision_guard( 'bogus', array() ) ), 'malformed revision denied' );
check_browser( $c::revision_guard( '', array( 'executor'=>'unknown', 'profile_id'=>'' ) ) === true, 'corrupt saved preference repairable from an empty revision' );
$browser_source=file_get_contents(dirname(__DIR__).'/includes/class-mad4b-scp-browser-acceptance-admin-ui.php');
check_browser( strpos( $browser_source, "MAD4B_SCP_Admin_Route_Registry::register( self::PAGE_SLUG, 'manage_options' )" ) !== false, 'Browser Acceptance must register in shared navigation' );
check_browser( strpos( $browser_source, "MAD4B_SCP_Admin_Experience::notice_verified( self::PAGE_SLUG, 'preference_saved'" ) !== false, 'success requires signed persisted-view receipt' );
check_browser( strpos( $browser_source, "add_query_arg( 'saved', '1'" ) === false, 'forged URL flags cannot report save success' );
check_browser( strpos( $browser_source, 'expected_configuration_revision' ) !== false, 'form must bind exact revision' );
check_browser( strpos( $browser_source, 'AND BINARY option_value = BINARY %s' ) !== false, 'compare-and-swap checks exact persisted bytes regardless of collation' );
$GLOBALS['browser_setting'][$c::OPTION]=array('executor'=>'unknown','profile_id'=>'');
check_browser( $c::selection()['executor']==='auto', 'invalid option fail-closed' );
$main=file_get_contents(dirname(__DIR__).'/mad4b-site-control-plane.php');
$core=file_get_contents(dirname(__DIR__).'/includes/class-mad4b-scp-browser-acceptance-core.php');
check_browser( substr_count($main,"require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-browser-acceptance-admin-ui.php';")===1,'one include');
check_browser( substr_count($main,'MAD4B_SCP_Browser_Acceptance_Admin_UI::boot();')===1,'one boot');
check_browser( strpos($core,'MAD4B_SCP_Browser_Acceptance_Admin_UI::public_selection()')!==false,'read projection');
$remote=file_get_contents(dirname(__DIR__).'/includes/class-mad4b-scp-remote-operation-parity.php');
$staging=file_get_contents(dirname(__DIR__).'/includes/class-mad4b-scp-staging-certification.php');
check_browser( strpos($remote, 'MAD4B_SCP_Browser_Acceptance_Admin_UI::runtime_target_guard( $provider_id, $profile_id )') !== false, 'remote queue must check operator selection' );
check_browser( strpos($remote, "preg_match( '/^[a-z0-9][a-z0-9._-]{0,63}$/D', \$provider_id )") !== false, 'remote queue must preserve dot-containing valid provider IDs' );
check_browser( strpos($remote, "preg_match( '/^[a-z0-9][a-z0-9._-]{0,63}$/D', \$profile_id )") !== false, 'remote queue must preserve dot-containing valid profile IDs' );
check_browser( strpos($remote, "sanitize_key( isset( \$input['provider_id'] )") === false, 'queue must never silently strip valid provider characters' );

check_browser( strpos($staging, 'MAD4B_SCP_Browser_Acceptance_Admin_UI::runtime_target_guard( $provider_id, $profile_id )') !== false, 'staging certification must check operator selection' );
check_browser( strpos($main, 'MAD4B_SCP_Browser_Acceptance_Admin_UI::boot();') !== false, 'setup available on admin' );


// Model the WordPress options CAS independently of a browser, with exact bytes.
function maybe_serialize( $value ) { return serialize( $value ); }
function wp_cache_delete( $key, $group = '' ) { return true; }
function add_option( $key, $value, $unused = '', $autoload = false ) {
    if ( array_key_exists( $key, $GLOBALS['browser_setting'] ) ) return false;
    $GLOBALS['browser_setting'][$key] = $value;
    return true;
}
class MAD4B_Test_Browser_Option_DB {
    public $options = 'wp_options';
    private $args = array();
    public $queries = 0;
    public function prepare( $sql, ...$args ) { $this->args = $args; return $sql; }
    public function query( $sql ) {
        ++$this->queries;
        if ( false === strpos( $sql, 'BINARY option_value = BINARY %s' ) ) return false;
        list( $updated, $option, $expected ) = $this->args;
        if ( ! array_key_exists( $option, $GLOBALS['browser_setting'] ) ||
            ! hash_equals( serialize( $GLOBALS['browser_setting'][$option] ), $expected ) ) return 0;
        $GLOBALS['browser_setting'][$option] = unserialize( $updated );
        return 1;
    }
}
$GLOBALS['wpdb'] = new MAD4B_Test_Browser_Option_DB();
$cas = new ReflectionMethod( $c, 'persist_if_unchanged' );
$cas->setAccessible( true );
unset( $GLOBALS['browser_setting'][$c::OPTION] );
$first = array( 'executor' => 'auto', 'profile_id' => 'royal', 'site_provider_id' => '', 'configuration_revision' => str_repeat('a',32) );
check_browser( true === $cas->invoke( null, false, $first ), 'pristine preferences created with exact readback' );
check_browser( $GLOBALS['browser_setting'][$c::OPTION] === $first, 'first persisted state matches exact initial value' );
$second = $first;
$second['executor'] = 'steel';
$second['configuration_revision'] = str_repeat('b',32);
check_browser( true === $cas->invoke( null, $first, $second ), 'one exact persisted revision can be updated' );
check_browser( $GLOBALS['browser_setting'][$c::OPTION] === $second, 'updated state read back exactly' );
$third = $second; $third['executor'] = 'browserbase'; $third['configuration_revision'] = str_repeat('c',32);
$stale = $cas->invoke( null, $first, $third );
check_browser( is_wp_error( $stale ), 'concurrent tab that saw old revision must fail' );
check_browser( $GLOBALS['browser_setting'][$c::OPTION] === $second, 'concurrent write cannot overwrite newer preference' );
check_browser( false !== strpos( $browser_source, 'BINARY option_value = BINARY %s' ), 'comparator must be byte-exact on case-insensitive SQL stores' );
check_browser( $GLOBALS['wpdb']->queries === 2, 'two update attempts were bounded and non-recursive' );

echo "MAD4B_BROWSER_ACCEPTANCE_ADMIN_SETUP: PASS\n";
