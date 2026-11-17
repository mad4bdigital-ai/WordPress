<?php
/** Isolated structural scope check: no WordPress, grant or real server required. */
define( 'ABSPATH', '/test/' );
class WP_Error {
	private $code;
	public function __construct( $code ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class MAD4B_SCP_ChatGPT_Tool_Projection {
	public static $selected = false;
	public static function is_projected( $name ) { return self::$selected && 'mad4b/database-raw-query' === $name; }
}
class MAD4B_SCP_Servers {
	public static function core_tools( $server ) {
		return 'mad4b-developer-breakglass' === $server
			? array( 'mad4b/developer-breakglass-shell', 'mad4b/developer-breakglass-wp-eval' )
			: array();
	}
}
class MAD4B_SCP_Capability_Descriptor_Registry {
	const CONSUMER_BINDING_CONTRACT = 'mad4b.capability-descriptor-consumer-binding.v1';
	const CONTRACT = 'mad4b.capability-descriptor.v2';
	const GENERATION_CONTRACT = 'mad4b.capability-generation-roots.v1';
}
require_once __DIR__ . '/../includes/class-mad4b-scp-server-registration-evidence.php';

$check = static function ( $value, $reason ) {
	if ( ! $value ) throw new RuntimeException( $reason );
};
$binding = static function ( $lane, $name ) {
	return array(
		'contract' => MAD4B_SCP_Capability_Descriptor_Registry::CONSUMER_BINDING_CONTRACT,
		'descriptor_contract' => MAD4B_SCP_Capability_Descriptor_Registry::CONTRACT,
		'generation_contract' => MAD4B_SCP_Capability_Descriptor_Registry::GENERATION_CONTRACT,
		'consumer' => 'servers',
		'ability_name' => $name,
		'execution_lane' => $lane,
		'input_schema_sha256' => str_repeat( 'a', 64 ),
		'classification_sha256' => str_repeat( 'b', 64 ),
		'descriptor_sha256' => str_repeat( 'c', 64 ),
		'generation_roots' => array( 'contract_root' => str_repeat( 'd', 64 ), 'site_root' => str_repeat( 'e', 64 ) ),
		'authorizing' => false,
		'authority_snapshot_is_grant' => false,
		'authority_revalidation_required' => true,
	);
};
$method = new ReflectionMethod( 'MAD4B_SCP_Server_Registration_Evidence', 'descriptor_binding_verified' );
$method->setAccessible( true );
$verify = static function ( $lane, $ability, $server ) use ( $method, $binding ) {
	return $method->invoke( null, $binding( $lane, $ability ), $ability, $server );
};
$check( $verify( 'read', 'mad4b/read-snapshot-header', 'mad4b-read' ), 'valid read lane rejected' );
$check( ! $verify( 'enrollment', 'mad4b/site-profile-write-enable', 'mad4b-read' ), 'nonread leaked onto read server' );
$check( ! $verify( 'none', 'mad4b/admin-query-performance-apply', 'mad4b-enrollment' ), 'unverified execution boundary became a tool' );
$check( $verify( 'enrollment', 'mad4b/site-profile-write-enable', 'mad4b-enrollment' ), 'valid enrollment rejected' );
$check( $verify( 'breakglass', 'mad4b/database-raw-query', 'mad4b-breakglass' ), 'exact Breakglass lane rejected' );
$check( ! $verify( 'breakglass', 'mad4b/database-raw-query', 'mad4b-read' ), 'Breakglass escaped into read server' );
$check( ! $verify( 'breakglass', 'mad4b/database-raw-query', 'mad4b-write' ), 'Breakglass escaped into write server' );
$check( ! $verify( 'breakglass', 'mad4b/other-query', 'mad4b-breakglass' ), 'unknown Breakglass tool accepted' );
$check( ! $verify( 'breakglass', 'mad4b/database-raw-query', 'mad4b-chatgpt' ), 'unselected Breakglass leaked to ChatGPT' );
MAD4B_SCP_ChatGPT_Tool_Projection::$selected = true;
$check( $verify( 'breakglass', 'mad4b/database-raw-query', 'mad4b-chatgpt' ), 'exact selected Breakglass descriptor rejected' );
$check( ! $verify( 'breakglass', 'mad4b/other-query', 'mad4b-chatgpt' ), 'unselected ability leaked to ChatGPT' );
$check( $verify( 'developer-breakglass', 'mad4b/developer-breakglass-shell', 'mad4b-developer-breakglass' ), 'exact Developer Breakglass rejected' );
$check( ! $verify( 'developer-breakglass', 'mad4b/developer-breakglass-shell', 'mad4b-chatgpt' ), 'Developer Breakglass leaked to ChatGPT' );
$check( ! $verify( 'developer-breakglass', 'mad4b/developer-breakglass-other', 'mad4b-developer-breakglass' ), 'unknown Developer Breakglass accepted' );
$evidence = array( 'bindings' => array( 'mad4b/verified' => array() ), 'blockers' => array( 'mad4b/unknown:descriptor_binding_unverified' ) );
$subset = MAD4B_SCP_Server_Registration_Evidence::verified_subset( 'mad4b-enrollment', array( 'mad4b/unknown', 'mad4b/verified' ), $evidence );
$check( true === $subset['admitted'] && array( 'mad4b/verified' ) === $subset['tools'], 'enrollment subset incorrectly materialized blocked Ability' );
$check( true === $subset['evidence']['degraded'] && array( 'mad4b/unknown' ) === $subset['evidence']['quarantined_abilities'], 'partial enrollment registration omitted quarantine evidence' );
$denied = MAD4B_SCP_Server_Registration_Evidence::verified_subset( 'mad4b-write', array( 'mad4b/unknown', 'mad4b/verified' ), $evidence );
$check( false === $denied['admitted'], 'unreviewed write transport degraded outside explicit presentation surfaces' );
$empty = MAD4B_SCP_Server_Registration_Evidence::verified_subset( 'mad4b-enrollment', array( 'mad4b/unknown' ), $evidence );
$check( false === $empty['admitted'], 'empty verified enrollment subset was registered' );
$chatgpt = MAD4B_SCP_Server_Registration_Evidence::verified_subset( 'mad4b-chatgpt', array( 'mad4b/unknown' ), $evidence );
$check( true === $chatgpt['admitted'] && array() === $chatgpt['tools'], 'chatgpt fail-closed route-only diagnostics are unavailable' );
echo "SERVER_CAPABILITY_LANE_ISOLATION_PASS 19/19\n";
echo "PASS: exact MCP descriptor routing and quarantine 19 positive-negative checks\n";
