<?php
/** Isolated regression: Business Activity planning Abilities have distinct identities. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../includes/trait-mad4b-scp-content-experience-ability-definitions.php';

final class MAD4B_Ability_Identity_Test_Fixture {
    use MAD4B_SCP_Content_Experience_Ability_Definitions;
    const BOOTSTRAP_PLAN_ABILITY = 'mad4b/content-experience-bootstrap-plan';
    const MEDIA_BINDING_PLAN_ABILITY = 'mad4b/content-experience-media-binding-plan';
    const PROFILE_APPLY_ABILITY = 'mad4b/content-experience-profile-apply';
    const PROFILE_CLONE_APPLY_ABILITY = 'mad4b/content-experience-profile-clone-apply';
    const PROFILE_DELETE_APPLY_ABILITY = 'mad4b/content-experience-profile-delete-apply';
    const MAX_MEDIA_GALLERY_ITEMS = 100;
    private static function schema( array $properties, array $required = array() ) {
        $value = array( 'type' => 'object', 'properties' => $properties, 'additionalProperties' => false );
        if ( $required ) $value['required'] = $required;
        return $value;
    }
    private static function sha_schema() {
        return array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64 );
    }
    private static function json_schema() {
        return array( 'type' => 'object', 'additionalProperties' => true );
    }
    private static function stored_profiles() { return array(); }
}

function mad4b_ability_identity_assert( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, 'Business activity Ability identity FAIL: ' . $message . "\n" );
        exit( 1 );
    }
}

$definitions = MAD4B_Ability_Identity_Test_Fixture::ability_definitions();
$registered = array();
foreach ( $definitions as $definition ) {
    $name = isset( $definition['name'] ) ? $definition['name'] : '';
    mad4b_ability_identity_assert(
        is_string( $name ) && preg_match( '/^mad4b\/[a-z0-9-]+$/D', $name ),
        'invalid Ability name'
    );
    mad4b_ability_identity_assert( ! isset( $registered[ $name ] ), 'duplicate Ability registration: ' . $name );
    $registered[ $name ] = $definition;
}
$legacy = 'mad4b/business-activity-sync-plan';
$contract = 'mad4b/business-activity-contract-sync-plan';
mad4b_ability_identity_assert(
    isset( $registered[ $legacy ], $registered[ $contract ] ),
    'one of the two distinct read contracts is missing'
);
mad4b_ability_identity_assert(
    $registered[ $legacy ]['callback'] === array( 'MAD4B_SCP_Activity_Sync_Runtime', 'plan' ),
    'legacy mult-source callback changed'
);
mad4b_ability_identity_assert(
    $registered[ $contract ]['callback'] === array( 'MAD4B_SCP_Business_Activity_Contracts', 'sync_plan' ),
    'contract-target callback changed'
);
foreach ( array(
    $legacy => array( 'profile_slug', 'entity_id' ),
    $contract => array( 'profile_slug', 'target_id', 'operation' ),
) as $ability => $required ) {
    $row = $registered[ $ability ];
    mad4b_ability_identity_assert(
        $row['surface'] === 'read' && $row['readonly'] === true,
        'read-only semantics drifted: ' . $ability
    );
    mad4b_ability_identity_assert(
        $row['schema']['required'] === $required,
        'input schema changed: ' . $ability
    );
}
mad4b_ability_identity_assert(
    $registered[ $legacy ]['permission'] === array( 'MAD4B_Ability_Identity_Test_Fixture', 'can_manage_profiles' ),
    'legacy permission callback drifted'
);
mad4b_ability_identity_assert(
    $registered[ $contract ]['permission'] === array( 'MAD4B_SCP_Policy', 'can_read' ),
    'contract permission callback drifted'
);

$source = file_get_contents( __DIR__ . '/../includes/class-mad4b-scp-content-experience-profiles.php' );
mad4b_ability_identity_assert( is_string( $source ), 'profile inventory source unreadable' );
$offset = strpos( $source, 'public static function ability_names( $surface )' );
mad4b_ability_identity_assert( false !== $offset, 'profile Ability inventory missing' );
$section = substr( $source, $offset );
mad4b_ability_identity_assert(
    preg_match( '/\$read = array\((.*?)\);\s*\$content = array\(/s', $section, $matches ) === 1,
    'read inventory not found'
);
$inventory = $matches[1];
mad4b_ability_identity_assert(
    substr_count( $inventory, "'" . $legacy . "'" ) === 1,
    'legacy read projection must appear exactly once'
);
mad4b_ability_identity_assert(
    substr_count( $inventory, "'" . $contract . "'" ) === 1,
    'contract read projection must appear exactly once'
);

echo 'PASS: ' . count( $registered ) .
    " unique Ability identities; callbacks, schemas, permissions and read inventory verified\n";
