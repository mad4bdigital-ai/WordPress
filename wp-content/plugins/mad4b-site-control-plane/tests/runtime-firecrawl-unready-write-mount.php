<?php
/**
 * Real WordPress fixture for dynamic Firecrawl write-mount readiness.
 * Execute with wp eval-file in disposable WordPress, not on live sites.
 * Read-only inspection: no capture, vendor HTTP, grant or DB mutation.
 */
if ( ! defined( 'ABSPATH' ) ) throw new RuntimeException( 'WordPress is required.' );
$check = static function ( $condition, $reason ) {
 if ( ! $condition ) throw new RuntimeException( $reason );
};
$check( class_exists( 'MAD4B_SCP_Adapter_Registry' )
 && class_exists( 'MAD4B_SCP_Servers' ), 'Original adapter and server owners not loaded.' );
$adapter = MAD4B_SCP_Adapter_Registry::instance()->get( 'firecrawl-research' );
$check( is_object( $adapter ), 'Original Firecrawl research adapter missing.' );
$guard_origin = new ReflectionMethod( $adapter, 'mutation_ability_runtime_eligibility' );
$check( 'MAD4B_SCP_Adapter_Base' === $guard_origin->getDeclaringClass()->getName(),
 'Firecrawl must inherit the central Adapter Base write-mount guard, not fork it.' );
$ability = 'mad4b/firecrawl-research-capture';
$registered = function_exists( 'wp_get_ability' ) ? wp_get_ability( $ability ) : null;
$check( is_object( $registered ), 'Original WordPress write Ability not registered.' );
$metadata = $registered->get_meta();
$check( 'write' === (string)( $metadata['mcp']['surface'] ?? '' )
 && false === ( $metadata['annotations']['readonly'] ?? null ),
 'Registered Firecrawl capture must remain explicitly write-only.' );
$available = $adapter->is_available();
if ( ! $available ) {
 $guard = $adapter->mutation_ability_runtime_eligibility( $ability );
 $check( is_wp_error( $guard ) && 'mad4b_adapter_unavailable' === $guard->get_error_code(),
  'Unready Firecrawl adapter must return an exact native mount blocker.' );
 $check( ! MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-write', $ability )
  && ! MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-read', $ability ),
  'Dormant ability must not be mounted as executable write or mislabeled read.' );
 $check( ! in_array( $ability, MAD4B_SCP_Servers::write_tools(), true ),
  'Write tools must filter missing Bridge/Provider via original runtime eligibility.' );
}
echo wp_json_encode( array(
 'contract' => 'mad4b.firecrawl-native-unready-mount-regression.v1',
 'state' => $available ? 'ELIGIBILITY_PRESENT_NOT_EXECUTED' : 'DORMANT_WRITE_NOT_MOUNTED',
 'ability_registered' => true, 'adapter_available' => (bool)$available,
 'write_mounted' => MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-write', $ability ),
 'authorizing' => false, 'mutation_performed' => false,
 'external_provider_called' => false,
 ) ) . "\n";
