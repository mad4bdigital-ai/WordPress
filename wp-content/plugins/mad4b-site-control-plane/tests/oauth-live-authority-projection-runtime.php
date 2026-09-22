<?php
define( 'ABSPATH', __DIR__ );

final class MAD4B_SCP_Staging_Write_Authority {
	public static function reconciliation_plan() {
		return array(
			'eligible' => true,
			'current_ready' => false,
			'environment' => 'staging',
			'write_tool_count' => 2,
			'exact_grants_missing_count' => 0,
			'exact_grants_missing' => array(),
			'stale_allow_grants_count' => 0,
			'stale_allow_grants' => array(),
			'wildcard_grants' => 0,
			'candidate_binding' => array(
				'required' => true,
				'match' => false,
				'stored_source_commit_sha' => str_repeat( 'a', 40 ),
				'current_source_commit_sha' => str_repeat( 'b', 40 ),
				'stored_build_fingerprint' => str_repeat( 'c', 64 ),
				'current_build_fingerprint' => str_repeat( 'd', 64 ),
			),
			'rows' => array(
				array( 'ability' => 'core/update-a', 'provider' => 'core', 'mounted' => true, 'exact_grant_present' => true ),
				array( 'ability' => 'media/update-b', 'provider' => 'media', 'mounted' => true, 'exact_grant_present' => true ),
			),
		);
	}
}

final class MAD4B_SCP_Servers {
	public static function external_write_tools() {
		return array( 'core/update-a', 'media/update-b', 'elementor/gated-c', 'jetengine/gated-d' );
	}
	public static function write_tools() {
		return array( 'core/update-a', 'media/update-b' );
	}
	public static function blocked_write_tools() {
		return array(
			array( 'ability' => 'elementor/gated-c', 'provider' => 'elementor', 'reason' => 'provider_capability_not_write_eligible' ),
			array( 'ability' => 'jetengine/gated-d', 'provider' => 'jetengine', 'reason' => 'provider_runtime_contract_not_certified' ),
		);
	}
}

function get_userdata( $user_id ) {
	return (object) array(
		'ID' => (int) $user_id,
		'display_name' => 'Dream Desert Tours',
		'user_login' => 'dream-desert-tours',
	);
}
function __( $value ) { return $value; }

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-local-oauth-server.php';

function mad4b_projection_assert( $condition, $message, $data = null ) {
	if ( $condition ) return;
	fwrite( STDERR, 'FAIL: ' . $message . ( null !== $data ? ' ' . json_encode( $data ) : '' ) . PHP_EOL );
	exit( 1 );
}

$p = MAD4B_SCP_Local_OAuth_Server::consent_grant_projection();
mad4b_projection_assert( 'mad4b.oauth-consent-grant-projection.v2' === $p['contract'], 'projection contract drifted', $p );
mad4b_projection_assert( 4 === (int) $p['catalog_write_tool_count'], 'catalog count must remain independent of runtime eligibility', $p );
mad4b_projection_assert( 2 === (int) $p['runtime_eligible_write_tool_count'], 'runtime-eligible count drifted', $p );
mad4b_projection_assert( 2 === (int) $p['exact_grants_existing'], 'exact grant count drifted', $p );
mad4b_projection_assert( 2 === (int) $p['provider_gated_write_tool_count'], 'provider-gated count drifted', $p );
mad4b_projection_assert( 0 === (int) $p['exact_grants_missing_count'], 'provider gating must not masquerade as missing exact grants', $p );
mad4b_projection_assert( empty( $p['ready'] ) && 'authority_blocked' === $p['state'], 'candidate mismatch must keep execution fail-closed', $p );
mad4b_projection_assert( 1 === count( $p['blocking_conditions'] ), 'candidate mismatch should be the only governance blocker in this fixture', $p );
mad4b_projection_assert( 'candidate_binding_mismatch' === $p['blocking_conditions'][0]['code'], 'binding mismatch blocker was not identified', $p );
mad4b_projection_assert( str_repeat( 'a', 40 ) === $p['blocking_conditions'][0]['binding']['stored_source_commit_sha'], 'stored candidate identity missing', $p );
mad4b_projection_assert( str_repeat( 'b', 40 ) === $p['blocking_conditions'][0]['binding']['current_source_commit_sha'], 'current candidate identity missing', $p );
mad4b_projection_assert( 2 === count( $p['blocked_catalog_abilities'] ), 'blocked catalog abilities were not preserved', $p );
mad4b_projection_assert( ! empty( $p['read_only'] ) && empty( $p['mutation_performed'] ) && empty( $p['oauth_scope_changed'] ) && empty( $p['write_authority_granted_by_consent'] ), 'projection must remain observation-only', $p );

$user = MAD4B_SCP_Local_OAuth_Server::consent_user_identity( 1 );
mad4b_projection_assert( 'Dream Desert Tours' === $user['display_label'], 'primary consent identity should use WordPress display name', $user );
mad4b_projection_assert( empty( $user['id_exposed_in_primary_ui'] ), 'numeric WordPress user ID must not be the primary UI identity', $user );

echo "mad4b.oauth-live-authority-projection.runtime.v1: PASS\n";
