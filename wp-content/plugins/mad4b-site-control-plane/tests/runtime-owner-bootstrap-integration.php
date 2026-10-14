<?php
/** Runs within a REAL WordPress + MCP Adapter under WP-CLI.
 * Negative permission integration only. Never provisions grants/owner tickets.
 */
if ( ! defined( 'ABSPATH' ) || ! function_exists( 'wp_get_ability' ) )
    throw new RuntimeException( 'WordPress Abilities API is required.' );
if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Owner_Intent' ) )
    throw new RuntimeException( 'Owner-intent service is missing.' );

$target = 'mad4b/staging-write-authority-convergence-apply';
$parent = 'mad4b/enrollment-execute';
if ( ! wp_has_ability( $target ) || ! wp_has_ability( $parent ) )
    throw new RuntimeException( 'Owner target or dispatcher is not registered in WordPress.' );
$ability = wp_get_ability( $target );
$dispatcher = wp_get_ability( $parent );
if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_input_schema' )
    || ! is_object( $dispatcher ) || ! method_exists( $dispatcher, 'execute' ) )
    throw new RuntimeException( 'Expected WordPress Ability contract unavailable.' );

$input_schema = $ability->get_input_schema();
$props = is_array( $input_schema ) && isset( $input_schema['properties'] ) ? $input_schema['properties'] : array();
if ( ! isset( $props['owner_intent_sha256'] ) || ! in_array( 'owner_intent_sha256', $input_schema['required'] ?? array(), true ) )
    throw new RuntimeException( 'Exact owner-intent receipt missing from live write Ability schema.' );
$metadata = $ability->get_meta();
$mcp = is_array( $metadata['mcp'] ?? null ) ? $metadata['mcp'] : array();
if ( empty( $mcp['exact_chatgpt_client_required'] ) || ! empty( $mcp['production_mutation_allowed'] )
    || ! empty( $mcp['generic_remote_admin'] ) )
    throw new RuntimeException( 'Staging owner Ability metadata is unsafe.' );

// This fresh local WP-CLI site is not an enrolled remote Staging Site Profile.
// Any attempt to execute must be denied without producing owner intent or grants.
$profile_before = get_option( MAD4B_SCP_Site_Profile::OPTION, null );
$intent_before = get_option( MAD4B_SCP_Staging_Write_Owner_Intent::OPTION, null );
$denied = $dispatcher->execute( array(
    'operation_id' => 'staging_write_authority_convergence',
    'expected_registration_digest' => str_repeat( 'a', 64 ),
    'expected_dispatch_policy_digest' => str_repeat( 'b', 64 ),
    'expected_input_schema_sha256' => str_repeat( 'c', 64 ),
    'input' => array(),
) );
if ( ! is_wp_error( $denied ) )
    throw new RuntimeException( 'Unenrolled WordPress dispatcher unexpectedly allowed owner bootstrap.' );
if ( get_option( MAD4B_SCP_Site_Profile::OPTION, null ) !== $profile_before
    || get_option( MAD4B_SCP_Staging_Write_Owner_Intent::OPTION, null ) !== $intent_before )
    throw new RuntimeException( 'Denied owner bootstrap unexpectedly mutated stored profile or intent.' );
echo 'WORDPRESS_OWNER_BOOTSTRAP_NEGATIVE_INTEGRATION: PASS'.PHP_EOL;
