<?php

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

$fail = static function ( $message ) {
	fwrite( STDERR, '[MAD4B Dynamic Skills Production smoke] ' . $message . PHP_EOL );
	exit( 1 );
};

if ( 'production' !== wp_get_environment_type() ) $fail( 'WordPress environment is not production.' );

$autoconfig = MAD4B_SCP_Skill_Autoconfig::status();
if ( ! empty( $autoconfig['eligible'] ) ) $fail( 'Production must not be eligible for automatic Skill authoring.' );
if ( ! empty( $autoconfig['configured'] ) ) $fail( 'Production Skill editor was auto-configured.' );
if ( ! empty( $autoconfig['app_mapping_configured'] ) ) $fail( 'Production was auto-bound to the Staging OpenAI App.' );
if ( 'none' !== $autoconfig['app_mapping_source'] ) $fail( 'Production App mapping source must remain none.' );
if ( 'environment_not_staging' !== $autoconfig['blocker'] ) $fail( 'Production autoconfig blocker is unexpected.' );

if ( defined( 'MAD4B_SKILLS_EDITOR_ENABLED' ) ) $fail( 'Production autoconfig defined MAD4B_SKILLS_EDITOR_ENABLED.' );
if ( defined( 'MAD4B_OPENAI_PLUGIN_APP_ID' ) ) $fail( 'Production autoconfig defined MAD4B_OPENAI_PLUGIN_APP_ID.' );

$registry = MAD4B_SCP_Skill_Registry::status();
if ( ! empty( $registry['editor_enabled'] ) ) $fail( 'Production Skill editor is enabled.' );
if ( ! empty( $registry['scripts_editor_enabled'] ) ) $fail( 'Production scripts editor is enabled.' );
if ( ! empty( $registry['portable_app_id_configured'] ) ) $fail( 'Production registry inherited an automatic Staging App mapping.' );
if ( ! empty( $registry['skill_count'] ) ) $fail( 'Production received automatically provisioned Skills.' );

foreach ( array( 'mad4b/skill-create', 'mad4b/skill-update', 'mad4b/skill-delete', 'mad4b/skill-write' ) as $ability ) {
	if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $ability ) ) $fail( 'Forbidden Skill write ability is registered: ' . $ability );
}

$cert = MAD4B_SCP_Skill_Runtime_Certification::observe();
if ( ! empty( $cert['ready'] ) ) $fail( 'Production must not receive Staging Skill runtime certification.' );
if ( ! in_array( 'environment_not_staging', isset( $cert['blockers'] ) ? $cert['blockers'] : array(), true ) ) $fail( 'Production certification did not fail on environment boundary.' );
if ( empty( $cert['local_runtime_only'] ) ) $fail( 'Certification trust boundary is not declared local-runtime-only.' );
if ( ! empty( $cert['external_client_snapshot_verified'] ) ) $fail( 'WordPress must not claim remote client snapshot verification.' );

echo 'mad4b.runtime-dynamic-skills-production.v1: PASS' . PHP_EOL;
