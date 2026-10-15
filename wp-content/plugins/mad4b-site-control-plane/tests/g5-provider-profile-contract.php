<?php
define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-g5-provider-profiles.php';

function g5_profile_assert( $condition, $message ) {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: " . $message . PHP_EOL ); exit( 1 ); }
}

$catalog = MAD4B_SCP_G5_Provider_Profiles::catalog();
g5_profile_assert( 6 === count( $catalog ), 'G5 must expose six required reference profiles' );
foreach ( array( 'ga4', 'google-search-console', 'semrush', 'se-ranking', 'ahrefs', 'serp-mesh' ) as $id ) {
	g5_profile_assert( isset( $catalog[ $id ] ), 'missing required reference profile: ' . $id );
	g5_profile_assert( false === $catalog[ $id ]['authorizing'], 'reference profile cannot authorize: ' . $id );
}
$coverage = MAD4B_SCP_G5_Provider_Profiles::coverage(
	array( array( 'provider_id' => 'ga4' ), array( 'provider_id' => 'future-reviewed-provider' ) ),
	array( array( 'provider_id' => 'serpapi' ) )
);
g5_profile_assert( false === $coverage['closed_allowlist'], 'reference profiles must not become a closed provider allowlist' );
g5_profile_assert( true === $coverage['additional_reviewed_server_code_adapters_allowed'], 'future reviewed server-code adapters must remain extensible' );
g5_profile_assert( false === $coverage['client_defined_provider_identity_allowed'], 'client descriptors cannot invent provider identity' );
g5_profile_assert( false === $coverage['generic_outbound_http_allowed'], 'reference profiles cannot enable generic HTTP' );
$rows = array_column( $coverage['profiles'], null, 'profile_id' );
g5_profile_assert( 'registered_server_adapter_observed' === $rows['ga4']['state'], 'GA4 registered adapter must be observed' );
g5_profile_assert( 'adapter_required' === $rows['semrush']['state'], 'missing research adapter cannot claim readiness' );
g5_profile_assert( 'existing_search_mesh_observed' === $rows['serp-mesh']['state'], 'existing SERP mesh must be reused' );
echo "mad4b.feature007-g5-provider-reference-profiles.v1: PASS\n";
