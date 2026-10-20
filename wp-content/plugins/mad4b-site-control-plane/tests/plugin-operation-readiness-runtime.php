<?php
/** Hermetic runtime projection and original-scope regression. No site mutations. */
define( 'ABSPATH', __DIR__ . '/' );
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $v ) ); }
function sanitize_text_field( $v ) { return (string) $v; }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function add_action( $hook, $callback, $priority = 10 ) {}
require __DIR__ . '/../includes/class-mad4b-scp-plugin-discovery.php';
require __DIR__ . '/../includes/class-mad4b-scp-operator-doctor.php';
function readiness_check( $v, $why ) { if ( ! $v ) throw new RuntimeException( $why ); }
$c = 'MAD4B_SCP_Plugin_Operation_Readiness';
$status = array( 'capability_certification' => array( 'capabilities' => array(
 'meta.read' => array( 'abilities' => array( 'vendor/read' ), 'read_eligible' => true ),
 'meta.write' => array( 'abilities' => array( 'vendor/write' ), 'write_eligible' => false,
  'artifact_authority_required' => true, 'artifact_authority_bound' => false,
  'capability_contract_digest' => str_repeat( 'a', 64 ),
  'behavioral_evidence' => array( 'artifact_fingerprint' => str_repeat( 'b', 64 ) ) ),
) ) );
$plugin = array( 'plugin_file' => 'vendor/main.php', 'version' => '1.0', 'active' => true,
 'risk' => 'high', 'coverage_state' => 'adapter_present_certification_required',
 'adapter_registered' => true, 'adapter_runtime_available' => true,
 'provider_certification_ok' => false,
 'adapter_read_abilities' => array( 'vendor/read' ), 'adapter_write_abilities' => array( 'vendor/write' ),
 'adapter_reversible_contracts' => array( 'vendor/write' => 'mad4b.rollback.vendor-meta.v1' ),
 'operation_capabilities' => $c::evidence( $status ),
);
$registry = array( 'vendor/read' => true, 'vendor/write' => true );
$model = $c::project( $plugin, $registry, true );
readiness_check( 'read_contract_available' === $model['operations'][0]['state'] && 'blocked' === $model['operations'][1]['state'], 'read contract inherited aggregate write/risk blocker' );
readiness_check( in_array( 'exact_artifact_evidence_required', $model['operations'][1]['blockers'], true ), 'missing exact artifact gate lost' );
readiness_check( false === $model['operations'][1]['verification']['exact_reader_binding_verified'], 'candidate reader became an exact verified reader' );
readiness_check( $model['non_authorizing'] && ! $model['mutation_performed'] && ! $model['operations'][0]['execution_allowed_by_discovery'], 'discovery became execution authority' );
$bool_status = $status; $bool_status['capability_certification']['capabilities']['meta.read']['read_eligible'] = 'true';
$bool_plugin = $plugin; $bool_plugin['operation_capabilities'] = $c::evidence( $bool_status );
readiness_check( 'blocked' === $c::project( $bool_plugin, $registry, true )['operations'][0]['state'], 'truthy string certified a read' );
foreach ( array( 'active', 'adapter_registered', 'adapter_runtime_available' ) as $field ) {
 $changed = $plugin; $changed[$field] = false;
 readiness_check( 2 === $c::project( $changed, $registry, true )['counts']['blocked'], 'inactive/missing adapter became ready: ' . $field );
}
readiness_check( 2 === $c::project( $plugin, $registry, false )['counts']['blocked'], 'partial registration scan produced ready operations' );
readiness_check( 2 === $c::project( $plugin, $registry, true, true )['counts']['blocked'], 'missing active dependency ignored' );
$changed = $plugin; $changed['side_channel_blocker'] = 'unreviewed';
readiness_check( 2 === $c::project( $changed, $registry, true )['counts']['blocked'], 'parallel transport ignored' );
$changed = $plugin; $changed['coverage_state'] = 'excluded_high_risk';
readiness_check( 2 === $c::project( $changed, $registry, true )['counts']['blocked'], 'excluded provider promoted' );
$changed = $plugin; $changed['operation_capabilities'] = array();
readiness_check( 1 === $c::project( $changed, $registry, true )['counts']['verification_required'] && 1 === $c::project( $changed, $registry, true )['counts']['blocked'], 'missing read evidence treated as passed or proven permission denial' );
$missing = $c::project( array( 'active' => true ), $registry, true );
readiness_check( 'review_and_implement_missing_adapter' === $missing['integration_action'] && ! $missing['operations'], 'unknown plugin received generated executable operations' );
$registered_changed = $registry; unset( $registered_changed['vendor/read'] );
readiness_check( 'blocked' === $c::project( $plugin, $registered_changed, true )['operations'][0]['state'], 'removed ability reused stale readiness' );
$certified = $status;
$certified['capability_certification']['capabilities']['meta.write']['write_eligible'] = true;
$certified['capability_certification']['capabilities']['meta.write']['artifact_authority_bound'] = true;
$ready = $plugin; $ready['operation_capabilities'] = $c::evidence( $certified );
$ready_model = $c::project( $ready, $registry, true );
readiness_check( 'write_contract_available' === $ready_model['operations'][1]['state'] && ! $ready_model['operations'][1]['execution_allowed_by_discovery'], 'certified contract bypassed execution authorization' );
$drift = $ready; $drift['version'] = '1.1';
readiness_check( $c::project( $drift, $registry, true )['evidence_sha256'] !== $ready_model['evidence_sha256'], 'plugin version change failed to refresh evidence' );
$drift = $ready; $drift['operation_capabilities']['vendor/write']['artifact_fingerprint'] = str_repeat( 'c', 64 );
readiness_check( $c::project( $drift, $registry, true )['evidence_sha256'] !== $ready_model['evidence_sha256'], 'artifact drift failed to refresh evidence' );
$conflict = $status; $conflict['capability_certification']['capabilities']['other.read'] = array( 'abilities' => array( 'vendor/read' ), 'read_eligible' => true );
$drift = $plugin; $drift['operation_capabilities'] = $c::evidence( $conflict );
readiness_check( 'blocked' === $c::project( $drift, $registry, true )['operations'][0]['state'], 'ambiguous capability assignment became ready' );
$graph = MAD4B_SCP_Plugin_Discovery::build_capability_graph( array( 'plugins' => array( $plugin ) ), array(), array_keys( $registry ) );
readiness_check( $model === $graph['items'][0]['operation_readiness'], 'actual capability graph omitted operation matrix' );
$large = $plugin; $large['adapter_read_abilities'] = array();
for ( $i = 0; $i < 257; ++$i ) $large['adapter_read_abilities'][] = 'vendor/read-' . $i;
readiness_check( ! $c::project( $large, $registry, true )['complete'], 'truncated operations marked complete' );

$scope = array( 'site_uuid' => 'site-a', 'brand_ref' => 'brand-a', 'tenant_ref' => 'tenant-a' );
$rows = array(
 array( 'job_id' => 'current', 'brand_id' => 'brand-a', 'tenant_id' => 'tenant-a' ),
 array( 'job_id' => 'foreign-brand', 'brand_id' => 'brand-b', 'tenant_id' => 'tenant-a' ),
 array( 'job_id' => 'foreign-tenant', 'brand_id' => 'brand-a', 'tenant_id' => 'tenant-b' ),
 array( 'job_id' => 'legacy', 'brand_id' => 'brand-a', 'tenant_id' => '' ),
);
$scoped = MAD4B_SCP_Operator_Doctor::scope_job_rows( $rows, $scope, 'site-a' );
readiness_check( array_column( $scoped, 'scope_relation' ) === array( 'current_scope_match', 'original_scope_review_required', 'original_scope_review_required', 'legacy_scope_binding_incomplete' ), 'Doctor confused site diagnostics with tenant/brand detail scope' );
readiness_check( count( $rows ) === count( $scoped ) && 'current' === ( $scoped[0]['job_id'] ?? '' ), 'site incident count or authorized row was lost during redaction' );
foreach ( array( 1, 2, 3 ) as $index ) {
 $redacted = $scoped[ $index ];
 readiness_check( true === $redacted['cross_scope_details_redacted'], 'Doctor failed to redact a foreign or legacy scope' );
 foreach ( array( 'job_id', 'work_id', 'outbox_id', 'provider_event_id', 'brand_id', 'tenant_id' ) as $secret_key ) {
  readiness_check( ! array_key_exists( $secret_key, $redacted ), 'Doctor exposed foreign job or provider identifier: ' . $secret_key );
 }
}
foreach ( $scoped as $row ) readiness_check( ! array_key_exists( 'brand_id', $row ) && ! array_key_exists( 'tenant_id', $row ) && ! $row['automatic_repair_allowed'] && ! $row['detail_read_authorized_by_doctor'], 'Doctor leaked scope identity or authorized repair' );
foreach ( array( array(), array( 'site_uuid' => 'site-b', 'brand_ref' => 'brand-a', 'tenant_ref' => 'tenant-a' ) ) as $unknown ) {
 readiness_check( 'current_scope_unresolved' === MAD4B_SCP_Operator_Doctor::scope_job_rows( $rows, $unknown, 'site-a' )[0]['scope_relation'], 'unresolved/cross-site scope matched' );
}
echo "PLUGIN_OPERATION_READINESS: PASS\n";

// Provider certification remains independent even after candidate/grant repair.
$uncertified = $status;
$uncertified['capability_certification']['capabilities']['meta.write']['certification_level'] = 'DISCOVERED';
$uncertified['capability_certification']['capabilities']['meta.write']['activation_stage'] = 'shadow';
$uncertified['capability_certification']['capabilities']['meta.write']['release_ring'] = 'R0_DISPOSABLE';
$projected = $plugin; $projected['operation_capabilities'] = $c::evidence( $uncertified );
$write = $c::project( $projected, $registry, true )['operations'][1];
readiness_check( 'blocked' === $write['state'] && 'shadow' === $write['provider_certification']['activation_stage'] && 'R0_DISPOSABLE' === $write['provider_certification']['release_ring'], 'provider stage/ring lost in discovery' );
readiness_check( ! $write['provider_certification']['write_grants_or_candidate_rebind_satisfy_certification'] && ! $write['provider_certification']['artifact_authority_bound'], 'candidate repair certified the provider' );
$uncertified['capability_certification']['capabilities']['meta.write']['activation_stage'] = '<secret payload>';
readiness_check( '' === $c::evidence( $uncertified )['vendor/write']['activation_stage'], 'unbounded provider label leaked' );
