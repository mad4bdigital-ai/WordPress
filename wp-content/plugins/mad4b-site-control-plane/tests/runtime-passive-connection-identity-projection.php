<?php
/** Runtime proof that exact Site Profile OAuth identity projects request-locally without persistence. */

if ( ! defined( 'ABSPATH' ) ) throw new RuntimeException( 'WordPress is not loaded.' );
$check = static function ( $condition, $message ) {
	if ( ! $condition ) throw new RuntimeException( $message );
};

$check( current_user_can( 'manage_options' ), 'Passive identity projection proof requires an administrator.' );
$check( class_exists( 'MAD4B_SCP_Site_Profile' ), 'Site Profile class unavailable.' );
$check( class_exists( 'MAD4B_SCP_Connection_Identity_Resolver' ), 'Connection identity resolver unavailable.' );
$check( class_exists( 'MAD4B_SCP_Staging_OAuth_Autoconfig' ), 'OAuth autoconfig class unavailable.' );

$current_revision = MAD4B_SCP_Site_Profile::revision();
$result = MAD4B_SCP_Site_Profile::save_current_site( array(
	'expected_revision' => $current_revision,
	'display_name' => 'MAD4B Connection Governance',
	'oauth_user_ids' => array( get_current_user_id() ),
	'oauth_enabled' => true,
	'skills_enabled' => false,
	'write_enabled' => false,
	'production_write_confirmed' => false,
	'provider_isolation_enabled' => false,
	'managed_runtime_enabled' => true,
	'acceptance_enabled' => false,
) );
if ( is_wp_error( $result ) ) throw new RuntimeException( $result->get_error_code() . ':' . $result->get_error_message() );

$check( MAD4B_SCP_Site_Profile::origin_enrolled(), 'Exact Site Profile enrollment was not preserved.' );
$check( MAD4B_SCP_Site_Profile::oauth_enabled(), 'OAuth was not enabled on the exact Site Profile fixture.' );

$before = get_option( MAD4B_SCP_Staging_OAuth_Autoconfig::OPTION, false );
MAD4B_SCP_Connection_Identity_Resolver::reset_request_cache();
$projection = MAD4B_SCP_Connection_Identity_Resolver::project_runtime_identity();
$after = get_option( MAD4B_SCP_Staging_OAuth_Autoconfig::OPTION, false );

$check( is_array( $projection ), 'Identity projection did not return a status array.' );
$check( 'mad4b.connection-identity-projection.v1' === ( isset( $projection['contract'] ) ? $projection['contract'] : '' ), 'Identity projection contract drifted.' );
$check( 'exact_site_profile' === ( isset( $projection['source'] ) ? $projection['source'] : '' ), 'Exact Site Profile was not selected as the identity source.' );
$check( ! empty( $projection['projected'] ) && ! empty( $projection['effective'] ), 'Exact Site Profile identity was not projected.' );
$check( empty( $projection['blocker'] ), 'Exact Site Profile projection returned a blocker: ' . ( isset( $projection['blocker'] ) ? $projection['blocker'] : '' ) );
$check( ! empty( $projection['request_local_only'] ), 'Identity projection did not declare request-local scope.' );
$check( empty( $projection['durable_mutation_performed'] ), 'Identity projection claimed a durable mutation.' );
$check( serialize( $before ) === serialize( $after ), 'Passive identity projection persisted OAuth autoconfig state.' );

$check( defined( 'MAD4B_MCP_OAUTH_ENABLED' ) && true === constant( 'MAD4B_MCP_OAUTH_ENABLED' ), 'OAuth resource bridge was not projected.' );
$check( defined( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED' ) && true === constant( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED' ), 'Local OAuth was not projected.' );
$check( defined( 'MAD4B_MCP_OAUTH_MODE' ) && 'local' === constant( 'MAD4B_MCP_OAUTH_MODE' ), 'Local OAuth mode was not projected.' );
$check( defined( 'MAD4B_MCP_OAUTH_WP_USER_ID' ) && get_current_user_id() === absint( constant( 'MAD4B_MCP_OAUTH_WP_USER_ID' ) ), 'Projected primary OAuth user drifted.' );

$kernel = MAD4B_SCP_Connection_Identity_Resolver::kernel();
$check( 'exact_site_profile' === ( isset( $kernel['source'] ) ? $kernel['source'] : '' ), 'Canonical kernel source drifted after projection.' );
$check( ! empty( $kernel['effective'] ), 'Canonical kernel became ineffective after request-local projection.' );
$check( empty( $kernel['root_blocker'] ), 'Canonical kernel retained a root blocker after exact projection.' );
$check( isset( $kernel['site']['profile_authority_inherited'] ) && false === $kernel['site']['profile_authority_inherited'], 'Identity kernel unexpectedly inherited profile authority into another source.' );

echo "mad4b.passive-connection-identity-projection.v1: PASS\n";
