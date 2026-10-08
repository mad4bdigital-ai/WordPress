<?php
// Hermetic, disposable G8 Phase 31-33 regression fixtures.
require __DIR__ . '/g8-automation-slo-runtime.php';
$GLOBALS['g8_environment'] = 'staging';
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-g8-supply-provenance.php';
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-g8-schema-migration.php';
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-g8-compatibility-fuzz.php';

// Stub the existing verified-certification-pack contract. This proves G8
// consumption/denial semantics; actual signature verification belongs to the
// existing cryptographic Certification Pack CI.
class MAD4B_SCP_Certification_Pack_Registry {
	public static function verify_pack( array $pack ) {
		if ( ! empty( $pack['revoked'] ) ) return new WP_Error( 'mad4b_certification_pack_revoked' );
		if ( ! empty( $pack['unsigned'] ) ) return new WP_Error( 'mad4b_certification_pack_signature_invalid' );
		if ( ! empty( $pack['foreign'] ) ) return new WP_Error( 'mad4b_certification_pack_foreign_binding' );
		if ( ! empty( $pack['stale'] ) ) return new WP_Error( 'mad4b_certification_pack_stale_artifact' );
		return $pack;
	}
}
$dep = str_repeat( 'd', 64 );
$source_sha = str_repeat( 'e', 64 );
$generation = str_repeat( 'f', 64 );
$pack = array( 'pack_type' => 'provider_evidence', 'provider_id' => 'analytics',
	'capability_id' => 'read', 'pack_sha256' => str_repeat( 'c', 64 ),
	'runtime_generation' => array( 'generation_sha256' => $generation ),
	'payload' => array( 'supply_provenance' => array( 'source_channel' => 'vendor_official',
		'source_sha256' => $source_sha, 'sequence' => 7, 'dependencies' => array( 'core' => $dep ),
		'mirror_sha256' => array( $source_sha ) ) ) );
$candidate = array( 'provider_id' => 'analytics', 'capability_id' => 'read',
	'runtime_generation_sha256' => $generation, 'source_channel' => 'vendor_official',
	'source_sha256' => $source_sha, 'sequence' => 7,
	'dependencies' => array( 'core' => $dep ), 'mirror_sha256' => array( $source_sha ) );
$ok = MAD4B_SCP_G8_Supply_Provenance::inspect( $candidate, $pack );
g8_check( 'PROVENANCE_MATCH' === $ok['state'] && false === $ok['authorizing']
	&& false === $ok['candidate_promotion_allowed'], 'signed match is non-authorizing' );
foreach ( array( 'revoked' => 'mad4b_certification_pack_revoked',
	'unsigned' => 'mad4b_certification_pack_signature_invalid',
	'foreign' => 'mad4b_certification_pack_foreign_binding',
	'stale' => 'mad4b_certification_pack_stale_artifact' ) as $flag => $error ) {
	$bad = $pack; $bad[ $flag ] = true;
	$r = MAD4B_SCP_G8_Supply_Provenance::inspect( $candidate, $bad );
	g8_check( 'QUARANTINED' === $r['state'] && $error === $r['reason']
		&& 'analytics:read' === $r['quarantine_scope']
		&& false === $r['provider_wide_quarantine'], 'signed pack denial: ' . $flag );
}
$mutations = array(
	array( 'source_channel', 'unknown_mirror', 'unexpected_source_channel' ),
	array( 'source_sha256', str_repeat( '0', 64 ), 'source_digest_mismatch' ),
	array( 'sequence', 6, 'candidate_downgrade' ),
	array( 'sequence', 8, 'unattested_candidate_sequence' ),
	array( 'dependencies', array( 'core' => str_repeat( '0', 64 ) ), 'dependency_substitution' ),
	array( 'mirror_sha256', array( str_repeat( '0', 64 ) ), 'mirror_digest_mismatch' ),
	array( 'runtime_generation_sha256', str_repeat( '0', 64 ), 'candidate_generation_mismatch' ),
	array( 'provider_id', 'foreign', 'foreign_candidate_scope' ),
);
foreach ( $mutations as $case ) {
	$bad = $candidate; $bad[ $case[0] ] = $case[1];
	$r = MAD4B_SCP_G8_Supply_Provenance::inspect( $bad, $pack );
	g8_check( $case[2] === $r['reason'] && 'QUARANTINED' === $r['state']
		&& 'analytics:read' === $r['quarantine_scope'], 'supply substitution: ' . $case[0] );
}
$omitted_mirror = $candidate; $omitted_mirror['mirror_sha256'] = array();
$omitted = MAD4B_SCP_G8_Supply_Provenance::inspect( $omitted_mirror, $pack );
g8_check( 'QUARANTINED' === $omitted['state'] && 'mirror_pinset_mismatch' === $omitted['reason'],
	'omitting signed mirrors cannot evade provenance' );
$extra_mirror = $candidate;
$extra_mirror['mirror_sha256'][] = $source_sha;
$extra = MAD4B_SCP_G8_Supply_Provenance::inspect( $extra_mirror, $pack );
g8_check( 'QUARANTINED' === $extra['state'] && 'mirror_pinset_mismatch' === $extra['reason'],
	'candidate cannot silently add sources absent from trusted mirror inventory' );
$runtime_truth = MAD4B_SCP_G8_Supply_Provenance::runtime_status();
g8_check( 'LOCAL_IDENTITY_ONLY' === $runtime_truth['state']
	&& false === $runtime_truth['signer_verified'], 'local runtime hash cannot impersonate signer' );

// Additive, exact-revision migration with reversible prestate and stale-worker denial.
$r = MAD4B_SCP_G8_Schema_Migration::register_observation( 'registry', array( 'legacy_unknown_field' => 'keep_me' ) );
g8_check( true === $r, 'create bounded owned observation snapshot' );
$plan = MAD4B_SCP_G8_Schema_Migration::preview( 'registry', 3 );
g8_check( is_array( $plan ) && 'DRY_RUN' === $plan['state']
	&& 2 === count( $plan['path'] ) && 2 === count( $plan['added_fields'])
	&& 'keep_me' === $plan['next_document']['data']['legacy_unknown_field'], 'migration dry-run DAG and unknown-field retention' );
g8_check( g8_is_error( MAD4B_SCP_G8_Schema_Migration::apply( 'registry', 3, 1, str_repeat( '0', 64 ) ),
	'mad4b_g8_migration_plan_stale' ), 'stale migration plan denied' );
$receipt = MAD4B_SCP_G8_Schema_Migration::apply( 'registry', 3, 1, $plan['plan_sha256'] );
g8_check( 'COMMITTED' === ( $receipt['state'] ?? '' ) && 2 === $receipt['revision'], 'exact migration CAS applied' );
g8_check( g8_is_error( MAD4B_SCP_G8_Schema_Migration::apply( 'registry', 3, 1, $plan['plan_sha256'] ),
	'mad4b_g8_migration_target_invalid' ), 'already migrated generation refused' );
g8_check( g8_is_error( MAD4B_SCP_G8_Schema_Migration::view( 'registry', 1 ),
	'mad4b_g8_migration_reader_incompatible' ), 'N-2 stale reader cannot reinterpret version 3 evidence' );
$reader = MAD4B_SCP_G8_Schema_Migration::view( 'registry', 2 );
g8_check( true !== $reader['reader_current'] && false === $reader['mutations_allowed']
	&& 2 === $reader['document']['min_reader_version']
	&& 'keep_me' === $reader['document']['data']['legacy_unknown_field'], 'declared N-1 reader is non-authorizing' );
g8_check( g8_is_error( MAD4B_SCP_G8_Schema_Migration::register_observation( 'policy', array( 'safe' => true ), 2 ),
	'mad4b_g8_migration_domain_denied' ), 'policy mutation requires independent review' );
// Even a re-sealed forged receipt cannot substitute a different pre-migration snapshot.
$migration_option = MAD4B_SCP_G8_Schema_Migration::OPTION;
$saved_migration = $GLOBALS['g8_options'][ $migration_option ];
$forged = $saved_migration;
$forged['receipts'][0]['before']['data']['legacy_unknown_field'] = 'forged';
$forged['seal'] = MAD4B_SCP_G8_Record::seal( $forged );
$GLOBALS['g8_options'][ $migration_option ] = $forged;
g8_check( 'RECONCILIATION_REQUIRED' === MAD4B_SCP_G8_Schema_Migration::status()['state'],
	'forged signed prestate must be rejected' );
$GLOBALS['g8_options'][ $migration_option ] = $saved_migration;
$rollback = MAD4B_SCP_G8_Schema_Migration::rollback_last( 2, $plan['plan_sha256'] );
g8_check( 'ROLLED_BACK' === ( $rollback['state'] ?? '' ) && 3 === $rollback['revision'], 'atomic rollback restored version 1' );
$reader = MAD4B_SCP_G8_Schema_Migration::view( 'registry', 1 );
g8_check( 1 === $reader['document']['version'] && ! isset( $reader['document']['data']['compatibility_state'] ),
	'rollback restored exact prior document' );
g8_check( g8_is_error( MAD4B_SCP_G8_Schema_Migration::rollback_last( 3, $plan['plan_sha256'] ),
	'mad4b_g8_migration_rollback_stale' ), 'rollback replay denied' );
$secret = MAD4B_SCP_G8_Schema_Migration::register_observation( 'manifest', array( 'api_token' => 'forbidden' ), 3 );
g8_check( g8_is_error( $secret, 'mad4b_g8_migration_document_invalid' ), 'secret fields never enter owned migration store' );
$value_secret = MAD4B_SCP_G8_Schema_Migration::register_observation( 'manifest', array(
	'innocuous_note' => 'Bearer ' . str_repeat( 'x', 44 ),
), 3 );
g8_check( g8_is_error( $value_secret, 'mad4b_g8_migration_document_invalid' ),
	'secret-looking observation value denied despite harmless field name' );
$GLOBALS['g8_epoch'] = 2;
g8_check( 'RECONCILIATION_REQUIRED' === MAD4B_SCP_G8_Schema_Migration::status()['state'], 'restore epoch drift quarantines migration record' );
$GLOBALS['g8_epoch'] = 1;

// Property/fault invariants run entirely on data-only isolated disposable fixtures.
$context = array( 'mode' => 'disposable', 'isolated' => true, 'shared_objects' => false,
	'paid_effects' => false, 'irreversible_effects' => false );
$certified = array( 'provider' => 'analytics', 'capability' => 'read', 'method' => 'GET',
	'readonly' => true, 'reversible' => false, 'effect' => 'none',
	'schema' => array( 'type' => 'object' ), 'risk' => 1 );
$valid_fixture = array( array( 'fault' => 'none', 'latency_ms' => 1, 'delivery_count' => 1, 'response_valid' => true ) );
$clean = MAD4B_SCP_G8_Compatibility_Fuzz::evaluate( $context, $certified, $certified, $valid_fixture, 42 );
g8_check( 'NO_FINDINGS_IN_BOUNDED_FIXTURES' === $clean['state']
	&& false === $clean['candidate_active_response_eligible']
	&& false === $clean['provider_executed'], 'clean bounded corpus never promotes candidate' );
$c1 = MAD4B_SCP_G8_Compatibility_Fuzz::corpus( 1337, 32 );
$c2 = MAD4B_SCP_G8_Compatibility_Fuzz::corpus( 1337, 32 );
g8_check( is_array( $c1 ) && $c1 === $c2 && 32 === count( $c1 ), 'seeded corpus must reproduce' );
$divergent = $certified; $divergent['method'] = 'POST'; $divergent['risk'] = 0;
$divergent['effect'] = 'external'; $divergent['schema'] = array( 'type' => 'string' );
$evidence = MAD4B_SCP_G8_Compatibility_Fuzz::evaluate( $context, $certified, $divergent, $c1, 1337 );
g8_check( 'FINDINGS_REQUIRE_REVIEW' === $evidence['state']
	&& 'analytics:read' === $evidence['quarantine_scope']
	&& in_array( 'candidate_risk_downgrade', $evidence['findings'], true )
	&& in_array( 'contradictory_readonly_effect', $evidence['findings'], true )
	&& in_array( 'schema_semantic_diff', $evidence['findings'], true )
	&& false === $evidence['external_effect_performed'], 'fuzz semantic regression isolated per capability' );
$repeat = MAD4B_SCP_G8_Compatibility_Fuzz::evaluate( $context, $certified, $divergent, $c1, 1337 );
g8_check( $repeat['evidence_sha256'] === $evidence['evidence_sha256'], 'fuzz evidence digest deterministic' );
$unsafe_fixture = $valid_fixture;
$unsafe_fixture[0]['handler'] = static function () {};
g8_check( g8_is_error( MAD4B_SCP_G8_Compatibility_Fuzz::evaluate( $context, $certified, $certified, $unsafe_fixture, 42 ),
	'mad4b_g8_fuzz_contract_invalid' ), 'unserializable executable fixture fails closed instead of crashing' );
$shared = $context; $shared['shared_objects'] = true;
g8_check( g8_is_error( MAD4B_SCP_G8_Compatibility_Fuzz::evaluate( $shared, $certified, $certified, $valid_fixture, 42 ),
	'mad4b_g8_fuzz_isolation_required' ), 'fuzz shared objects forbidden' );
$paid = $context; $paid['paid_effects'] = true;
g8_check( g8_is_error( MAD4B_SCP_G8_Compatibility_Fuzz::evaluate( $paid, $certified, $certified, $valid_fixture, 42 ),
	'mad4b_g8_fuzz_isolation_required' ), 'paid provider fuzz forbidden' );
$GLOBALS['g8_environment'] = 'production';
g8_check( g8_is_error( MAD4B_SCP_G8_Compatibility_Fuzz::evaluate( $context, $certified, $certified, $valid_fixture, 42 ),
	'mad4b_g8_fuzz_isolation_required' ), 'Production fuzz forbidden' );
// Capability-local artifact drift preserves compatible read visibility.
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-g8-capability-convergence.php';
class MAD4B_SCP_Provider_Compatibility_Certification {
	public static function assess_provider( $provider ) {
		if ( ! empty( $GLOBALS['g8_epoch_flip_on_inspection'] ) ) $GLOBALS['g8_epoch'] = 2;
		$GLOBALS['g8_provider_inspections'] = (int) ( $GLOBALS['g8_provider_inspections'] ?? 0 ) + 1;
		if ( 'analytics' !== $provider ) return new WP_Error( 'unknown_provider' );
		$assessment = array( 'artifact' => array( 'runtime_artifact_fingerprint' => str_repeat( '1', 64 ) ),
			'capabilities' => array(
				'read' => array( 'capability_contract_digest' => str_repeat( '2', 64 ),
					'structural_compatible' => true, 'behavioral_evidence' => array( 'behavioral_verified' => true, 'rollback_verified' => false ),
					'read_eligible' => true, 'write_eligible' => false, 'certification_level' => 'READ_COMPATIBLE' ),
				'write' => array( 'capability_contract_digest' => str_repeat( '3', 64 ),
					'structural_compatible' => true, 'behavioral_evidence' => array( 'behavioral_verified' => true, 'rollback_verified' => true ),
					'read_eligible' => false, 'write_eligible' => true, 'certification_level' => 'REVERSIBLE_WRITE_CERTIFIED' ),
			) );
		if ( ! empty( $GLOBALS['g8_inject_executable_receipt'] ) ) {
			$assessment['capabilities']['read']['behavioral_evidence']['accepted_receipt'] = new class {
				public function __serialize() {
					$GLOBALS['g8_untrusted_serialize_invoked'] = true;
					return array( 'unsafe' => true );
				}
			};
		}
		return $assessment;
	}
}
$GLOBALS['g8_inject_executable_receipt'] = true;
$unsafe_receipt = MAD4B_SCP_G8_Capability_Convergence::observe( 'analytics' );
g8_check( g8_is_error( $unsafe_receipt, 'mad4b_g8_convergence_receipt_invalid' )
	&& empty( $GLOBALS['g8_untrusted_serialize_invoked'] ),
	'provider object must be rejected without invoking its __serialize method' );
$GLOBALS['g8_inject_executable_receipt'] = false;
$GLOBALS['g8_epoch_flip_on_inspection'] = true;
$raced_provider = MAD4B_SCP_G8_Capability_Convergence::observe( 'analytics' );
g8_check( g8_is_error( $raced_provider, 'mad4b_g8_convergence_identity_raced' ),
	'provider inspection must reject a restore-epoch race' );
$GLOBALS['g8_epoch'] = 1;
$GLOBALS['g8_epoch_flip_on_inspection'] = false;
$prior = MAD4B_SCP_G8_Capability_Convergence::observe( 'analytics' );
g8_check( is_array( $prior ) && 2 === count( $prior['capabilities'] ), 'current provider observation normalized' );
$foreign_site = $prior; $foreign_site['site_profile_sha256'] = str_repeat( 'f', 64 );
g8_check( g8_is_error( MAD4B_SCP_G8_Capability_Convergence::diff( $prior, $foreign_site ),
	'mad4b_g8_convergence_snapshot_invalid' ), 'foreign site profile cannot participate in convergence' );
$restored_snapshot = $prior; ++$restored_snapshot['restore_binding']['epoch'];
g8_check( g8_is_error( MAD4B_SCP_G8_Capability_Convergence::diff( $prior, $restored_snapshot ),
	'mad4b_g8_convergence_snapshot_invalid' ), 'restored epoch cannot reuse prior capability assessment' );
$after = $prior; $after['artifact_sha256'] = str_repeat( '4', 64 );
$diff = MAD4B_SCP_G8_Capability_Convergence::diff( $prior, $after );
g8_check( in_array( 'read', $diff['unrelated_compatible_capabilities'], true )
	&& 'IDENTITY_CHANGED_RECHECK_REQUIRED' === $diff['changed_capabilities']['write']['state']
	&& false === $diff['whole_provider_deactivation']
	&& false === $diff['candidate_promotion_allowed'], 'artifact drift fences only affected write capability' );
$after['capabilities']['read']['contract_sha256'] = str_repeat( '5', 64 );
$diff = MAD4B_SCP_G8_Capability_Convergence::diff( $prior, $after );
g8_check( 'STRUCTURE_CHANGED' === $diff['changed_capabilities']['read']['state']
	&& 'analytics:read' === $diff['changed_capabilities']['read']['quarantine_scope'], 'structural change quarantines exact read capability' );
$removed = $prior;
$removed['capabilities'] = array();
$provider_lost = MAD4B_SCP_G8_Capability_Convergence::diff( $prior, $removed );
g8_check( true === $provider_lost['provider_wide_quarantine']
	&& false === $provider_lost['whole_provider_deactivation']
	&& 'no_current_eligible_capabilities' === $provider_lost['provider_quarantine_reason'],
	'no current provider capabilities must be reported quarantined, without mutation' );
$replacement_receipt = $prior;
$replacement_receipt['capabilities']['read']['behavioral_evidence_sha256'] = str_repeat( 'e', 64 );
$receipt_diff = MAD4B_SCP_G8_Capability_Convergence::diff( $prior, $replacement_receipt );
g8_check( 'CERTIFICATION_EVIDENCE_CHANGED' === $receipt_diff['changed_capabilities']['read']['state'],
	'behavioral receipt replacement is not unchanged even with identical verification flags' );
$became_ineligible = $prior;
$became_ineligible['capabilities']['read']['read_eligible'] = false;
$fenced = MAD4B_SCP_G8_Capability_Convergence::diff( $prior, $became_ineligible );
g8_check( 'READ_FENCED' === $fenced['changed_capabilities']['read']['state']
	&& ! in_array( 'read', $fenced['unrelated_compatible_capabilities'], true ),
	'previously certified read is not preserved after read eligibility loss' );
$new_eligibility = $prior;
$new_eligibility['capabilities']['write']['write_eligible'] = false;
$promotion = MAD4B_SCP_G8_Capability_Convergence::diff( $new_eligibility, $prior );
g8_check( 'ELIGIBILITY_EXPANSION_REQUIRES_GOVERNED_REVIEW' === $promotion['changed_capabilities']['write']['state']
	&& false === $promotion['candidate_promotion_allowed'], 'write eligibility expansion cannot be classified unchanged' );

$external = MAD4B_SCP_G8_Capability_Convergence::live_acceptance();
g8_check( 'EXTERNAL_ACCEPTANCE_PENDING' === $external['state']
	&& false === $external['release_acceptance']
	&& false === $external['candidate_promotion_allowed'], 'repository test cannot invent live/browser acceptance' );
$GLOBALS['g8_environment'] = 'production';
$live = MAD4B_SCP_G8_Capability_Convergence::live_acceptance();
g8_check( false === $live['release_acceptance'], 'Production cannot gain auto acceptance' );
$inspections_before = $GLOBALS['g8_provider_inspections'];
g8_check( g8_is_error( MAD4B_SCP_G8_Capability_Convergence::observe( 'analytics' ),
	'mad4b_g8_convergence_staging_required' )
	&& $inspections_before === $GLOBALS['g8_provider_inspections'],
	'Production guard must execute before any provider inspection' );

// Distinct providers fill the bounded queue; failures trigger site cooldown.
$GLOBALS['g8_environment'] = 'staging';
$switch = MAD4B_SCP_Automation_SLO::switch_status();
g8_check( true === MAD4B_SCP_Automation_SLO::change_switch( '*', false, $switch['revision'] ), 'SLO storm fixture resume' );
$queued = array();
for ( $i = 0; $i < 4; ++$i ) {
	$ticket = MAD4B_SCP_Automation_SLO::reserve( 'test-provider-' . $i, 'probe', str_repeat( 'e', 64 ) );
	g8_check( is_array( $ticket ), 'bounded queue accepts four distinct scopes' );
	$queued[] = $ticket;
}
$overflow = MAD4B_SCP_Automation_SLO::reserve( 'test-provider-fifth', 'probe', str_repeat( 'e', 64 ) );
g8_check( g8_is_error( $overflow, 'mad4b_automation_site_backpressure' ), 'queue storm is isolated and bounded' );
foreach ( $queued as $ticket ) {
	g8_check( true === MAD4B_SCP_Automation_SLO::finish_existing( $ticket, new WP_Error( 'simulated_failure' ) ), 'fault outcome persisted' );
}
$cooldown = MAD4B_SCP_Automation_SLO::admission( 'fresh-provider', 'probe' );
g8_check( 'error_budget_cooldown' === $cooldown['reason'] && 'site' === $cooldown['scope'],
	'failure storm enforces shared site error budget' );
$option = MAD4B_SCP_Automation_SLO::OPTION;
$old_metrics = $GLOBALS['g8_options'][ $option ];
$broken = $old_metrics; $broken['eligible_workload_count'] = 'invalid';
$broken['seal'] = MAD4B_SCP_G8_Record::seal( $broken ); // Even a valid MAC cannot excuse invalid schema.
$GLOBALS['g8_options'][ $option ] = $broken;
$lost = MAD4B_SCP_Automation_SLO::admission( 'fresh-provider', 'probe' );
g8_check( 'mad4b_automation_metrics_lost' === $lost['reason'], 'telemetry tampering must fail closed' );
$GLOBALS['g8_options'][ $option ] = $old_metrics;
$lost_outcome = $old_metrics; ++$lost_outcome['eligible_workload_count'];
$lost_outcome['seal'] = MAD4B_SCP_G8_Record::seal( $lost_outcome );
$GLOBALS['g8_options'][ $option ] = $lost_outcome;
g8_check( 'mad4b_automation_metrics_lost' === MAD4B_SCP_Automation_SLO::admission( 'fresh-provider', 'probe' )['reason'],
	're-sealed missing outcome fails SLO conservation instead of understating results' );
$GLOBALS['g8_options'][ $option ] = $old_metrics;

// Post-restore admission must remain blocked until independent external-effect
// reconciliation and a governed acceptance receipt; CI cannot mint either.
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-g8-restore-convergence.php';
$recovery = MAD4B_SCP_G8_Restore_Convergence::status();
g8_check( 'BLOCKED_PENDING_GOVERNED_ACCEPTANCE' === $recovery['state']
	&& false === $recovery['write_resume_allowed']
	&& false === $recovery['old_receipts_replayed']
	&& in_array( 'unrewound_external_effect_reconciliation_required', $recovery['blockers'], true ),
	'restored local state cannot self-authorize external-effect replay' );

// A locally restored database is not proof that external payment/email effects
// were rewound. The comparison is data-only and cannot approve compensation.
$effect_id = str_repeat( 'a', 64 );
$new_effect_id = str_repeat( 'b', 64 );
$local_effects = array( $effect_id => array( 'state' => 'applied', 'effect_sha256' => str_repeat( 'c', 64 ) ) );
$remote_effects = $local_effects;
$remote_effects[ $new_effect_id ] = array( 'state' => 'applied', 'effect_sha256' => str_repeat( 'd', 64 ) );
$effect_diff = MAD4B_SCP_G8_Restore_Convergence::compare_external_effects( $local_effects, $remote_effects );
g8_check( 1 === $effect_diff['candidate_issue_count']
	&& 'external_effect_absent_from_local_snapshot' === $effect_diff['issues'][0]['reason']
	&& false === $effect_diff['acceptance_receipt_issued']
	&& false === $effect_diff['automatic_retry_allowed'], 'external effects cannot be blindly replayed' );
$bad_effects = array( 'invalid-key' => array( 'state' => 'applied', 'effect_sha256' => str_repeat( 'c', 64 ) ) );
g8_check( g8_is_error( MAD4B_SCP_G8_Restore_Convergence::compare_external_effects( $bad_effects, $remote_effects ),
	'mad4b_g8_restore_effects_invalid' ), 'untrusted external effect IDs are rejected' );

// The real native verifier is exercised in crypto-profile CI. This fixture
// models its verified/unverified return contract to test G8's exact operation
// binding and the independent GOVERNED acceptance boundary.
class MAD4B_SCP_Execution_Receipt {
	public static function verify( array $receipt ) {
		if ( true !== ( $receipt['mock_native_signature_verified'] ?? false ) )
			return new WP_Error( 'simulated_native_signature_rejected' );
		return array( 'valid' => true, 'cryptographic_signature_verified' => true,
			'receipt_sha256' => str_repeat( 'e', 64 ) );
	}
}
$operation_id = 'g8-effect-operation-1';
$correlation = hash( 'sha256', $operation_id );
$effect_sha = str_repeat( 'a', 64 );
$one_effect = array( $correlation => array( 'state' => 'applied', 'effect_sha256' => $effect_sha ) );
$native = array( 'request_id' => $operation_id, 'target_fingerprint' => $effect_sha,
	'provider_id' => 'external-provider', 'ability' => 'demo/provider-effect',
	'mock_native_signature_verified' => true );
$evidence = MAD4B_SCP_G8_Restore_Convergence::evidence_pack( array(), $one_effect,
	array( $correlation => $native ) );
g8_check( 1 === $evidence['signed_native_operation_count'] && ! $evidence['unwitnessed_effect_keys']
	&& false === $evidence['external_provider_readback_verified']
	&& false === $evidence['governed_restore_acceptance_issued']
	&& false === $evidence['write_resume_allowed'], 'native signature is not external rewind or governed acceptance' );
$untrusted = $native; $untrusted['mock_native_signature_verified'] = false;
$bad_native = MAD4B_SCP_G8_Restore_Convergence::evidence_pack( array(), $one_effect,
	array( $correlation => $untrusted ) );
g8_check( 0 === $bad_native['signed_native_operation_count']
	&& 'native_receipt_signature_or_operation_binding_invalid' === $bad_native['rejected_witness_reasons'][ $correlation ],
	'unsigned operation evidence cannot be treated as a verified effect' );
$foreign_native = $native; $foreign_native['target_fingerprint'] = str_repeat( 'f', 64 );
$foreign_pack = MAD4B_SCP_G8_Restore_Convergence::evidence_pack( array(), $one_effect,
	array( $correlation => $foreign_native ) );
g8_check( 0 === $foreign_pack['signed_native_operation_count']
	&& false === $foreign_pack['automatic_retry_allowed'], 'cross-effect receipt substitution is rejected' );
class G8_Effect_Object_Reject {
	public function __serialize() {
		$GLOBALS['g8_effect_object_serialized'] = true;
		return array( 'unexpected' => true );
	}
}
$unsafe_effect = $one_effect;
$unsafe_effect[ $correlation ]['payload'] = new G8_Effect_Object_Reject();
g8_check( g8_is_error( MAD4B_SCP_G8_Restore_Convergence::evidence_pack( array(), $unsafe_effect, array() ),
	'mad4b_g8_restore_effects_invalid' ) && empty( $GLOBALS['g8_effect_object_serialized'] ),
	'external effect inventories reject executable extra fields before hashing' );
$unsafe_witness = $native;
$unsafe_witness['callback'] = new G8_Effect_Object_Reject();
g8_check( g8_is_error( MAD4B_SCP_G8_Restore_Convergence::evidence_pack( array(), $one_effect,
	array( $correlation => $unsafe_witness ) ), 'mad4b_g8_restore_witness_invalid' )
	&& empty( $GLOBALS['g8_effect_object_serialized'] ),
	'native witnesses reject executable payloads before invoking a verifier' );
$unwitnessed = MAD4B_SCP_G8_Restore_Convergence::evidence_pack( array(), $one_effect, array() );
g8_check( 1 === count( $unwitnessed['unwitnessed_effect_keys'] )
	&& false === $unwitnessed['external_inventory_independently_certified'],
	'empty witnesses cannot certify external inventory' );

// Historic, signed and expired windows may not permanently block new
// providers; preserve the site bucket and never evict a live ticket scope.
$stale = $GLOBALS['g8_options'][ MAD4B_SCP_Automation_SLO::OPTION ];
$stale['buckets'] = array( 'site' => array( 'started_at' => time() - 7200,
	'attempts' => 0, 'errors' => 0, 'cooldown_until' => 0 ) );
for ( $i = 0; $i < 60; ++$i ) {
	$stale['buckets']['provider:expired-' . $i] = array( 'started_at' => time() - 7200,
		'attempts' => 1, 'errors' => 0, 'cooldown_until' => 0 );
}
$stale['seal'] = MAD4B_SCP_G8_Record::seal( $stale );
$GLOBALS['g8_options'][ MAD4B_SCP_Automation_SLO::OPTION ] = $stale;
$recovered = MAD4B_SCP_Automation_SLO::reserve( 'fresh', 'probe', str_repeat( 'e', 64 ) );
g8_check( is_array( $recovered ), 'expired supplier windows reclaimed before site capacity check' );
g8_check( true === MAD4B_SCP_Automation_SLO::finish_existing( $recovered, new WP_Error( 'simulated_error' ) ),
	'after reclamation automatic outcome remains durable' );

// A worker's self-reported 'completed' payload cannot fake an independently
// observed repair. A switch revision race also revokes its repair classification.
MAD4B_SCP_Runtime_Convergence::$ready = false;
$claimed = MAD4B_SCP_Automation_SLO::reserve( 'runtime-convergence', 'safe-phases', str_repeat( 'e', 64 ) );
g8_check( is_array( $claimed ) && false === $claimed['pre_ready'], 'capture unready runtime before forged result' );
$claimed_success = array( 'state' => 'completed', 'readback' => array( 'required_blockers' => array() ) );
g8_check( true === MAD4B_SCP_Automation_SLO::finish_existing( $claimed, $claimed_success ),
	'unverified completion remains a handoff' );
$metrics = MAD4B_SCP_Automation_SLO::status();
g8_check( 1 === $metrics['outcomes']['verified_repair'], 'unobserved repair never increments verified numerator' );
$revoked_ticket = MAD4B_SCP_Automation_SLO::reserve( 'runtime-convergence', 'safe-phases', str_repeat( 'e', 64 ) );
g8_check( is_array( $revoked_ticket ) && false === $revoked_ticket['pre_ready'], 'second repair starts unready' );
MAD4B_SCP_Runtime_Convergence::$ready = true;
$rev = MAD4B_SCP_Automation_SLO::switch_status()['revision'];
g8_check( true === MAD4B_SCP_Automation_SLO::change_switch( '*', false, $rev ),
	'a resume revision invalidates active ticket even when scope remains enabled' );
g8_check( true === MAD4B_SCP_Automation_SLO::finish_existing( $revoked_ticket, $claimed_success ),
	'switch-raced completion recorded only as unverified handoff' );
g8_check( 1 === MAD4B_SCP_Automation_SLO::status()['outcomes']['verified_repair'],
	'raced ticket never adds a verified repair' );

// A restored/rolled-back host clock cannot silently extend an old signed
// window or a worker's TTL beyond its original timing assumptions.
$clock_option = MAD4B_SCP_Automation_SLO::OPTION;
$clock_saved = $GLOBALS['g8_options'][ $clock_option ];
$future_bucket = $clock_saved;
$future_bucket['buckets']['site']['started_at'] = time() + 3600;
$future_bucket['seal'] = MAD4B_SCP_G8_Record::seal( $future_bucket );
$GLOBALS['g8_options'][ $clock_option ] = $future_bucket;
$skew = MAD4B_SCP_Automation_SLO::admission( 'clock-provider', 'probe' );
g8_check( 'clock_skew_requires_reconciliation' === $skew['reason'],
	'validly sealed future budget fails closed on backward wall clock' );
$GLOBALS['g8_options'][ $clock_option ] = $clock_saved;
$clock_ticket = MAD4B_SCP_Automation_SLO::reserve( 'clock-provider', 'probe', str_repeat( 'e', 64 ) );
g8_check( is_array( $clock_ticket ), 'clock test obtains an ordinary ticket first' );
$clock_saved = $GLOBALS['g8_options'][ $clock_option ];
$future_ticket = $clock_saved;
$future_ticket['tickets'][ $clock_ticket['token'] ]['started_at'] = time() + 600;
$future_ticket['tickets'][ $clock_ticket['token'] ]['expires_at'] = time() + 1800;
$future_ticket['seal'] = MAD4B_SCP_G8_Record::seal( $future_ticket );
$GLOBALS['g8_options'][ $clock_option ] = $future_ticket;
g8_check( g8_is_error( MAD4B_SCP_Automation_SLO::ticket_allowed( $future_ticket['tickets'][ $clock_ticket['token'] ] ),
	'mad4b_automation_clock_skew_requires_reconciliation' ), 'future ticket cannot pass execution fence' );
g8_check( 'clock_skew_requires_reconciliation' === MAD4B_SCP_Automation_SLO::admission( 'other-provider', 'probe' )['reason'],
	'future ticket blocks automatic site admissions' );
$GLOBALS['g8_options'][ $clock_option ] = $clock_saved;
g8_check( true === MAD4B_SCP_Automation_SLO::finish_existing( $clock_ticket, array( 'state' => 'handoff' ) ),
	'normal ticket can finish only after valid clock fixture restored' );

$foreign_ticket = MAD4B_SCP_Automation_SLO::reserve( 'foreign-scope', 'probe', str_repeat( 'e', 64 ) );
g8_check( is_array( $foreign_ticket ), 'foreign binding test starts from live ticket' );
$foreign_saved = $GLOBALS['g8_options'][ MAD4B_SCP_Automation_SLO::OPTION ];
$wrong_binding = $foreign_saved;
$wrong_binding['tickets'][ $foreign_ticket['token'] ]['restore_binding']['epoch'] = 99;
$wrong_binding['seal'] = MAD4B_SCP_G8_Record::seal( $wrong_binding );
$GLOBALS['g8_options'][ MAD4B_SCP_Automation_SLO::OPTION ] = $wrong_binding;
g8_check( 'mad4b_automation_metrics_lost' === MAD4B_SCP_Automation_SLO::admission( 'unrelated-provider', 'probe' )['reason'],
	're-sealed ticket from a different restore epoch invalidates the ledger' );
$GLOBALS['g8_options'][ MAD4B_SCP_Automation_SLO::OPTION ] = $foreign_saved;
$missing_budget = $foreign_saved;
unset( $missing_budget['buckets']['provider:foreign-scope'] );
$missing_budget['seal'] = MAD4B_SCP_G8_Record::seal( $missing_budget );
$GLOBALS['g8_options'][ MAD4B_SCP_Automation_SLO::OPTION ] = $missing_budget;
g8_check( 'mad4b_automation_metrics_lost' === MAD4B_SCP_Automation_SLO::admission( 'unrelated-provider', 'probe' )['reason'],
	're-sealed pending ticket with absent provider budget is invalid, not restorable' );
$GLOBALS['g8_options'][ MAD4B_SCP_Automation_SLO::OPTION ] = $foreign_saved;
g8_check( true === MAD4B_SCP_Automation_SLO::finish_existing( $foreign_ticket, array( 'state' => 'handoff' ) ),
	'valid restored ledger permits exact ticket cleanup' );

// A crashed/expired worker outcome remains uncertain. Do not purge its
// sealed ticket or allow another capability to work around the site breaker.
$orphan = MAD4B_SCP_Automation_SLO::reserve( 'abandoned-provider', 'repair', str_repeat( 'e', 64 ) );
g8_check( is_array( $orphan ), 'orphan fixture ticket admitted before simulated crash' );
$orphan_record = $GLOBALS['g8_options'][ MAD4B_SCP_Automation_SLO::OPTION ];
$orphan_record['tickets'][ $orphan['token'] ]['started_at'] = time() - 1300;
$orphan_record['tickets'][ $orphan['token'] ]['expires_at'] = time() - 100;
$orphan_record['seal'] = MAD4B_SCP_G8_Record::seal( $orphan_record );
$GLOBALS['g8_options'][ MAD4B_SCP_Automation_SLO::OPTION ] = $orphan_record;
$uncertain = MAD4B_SCP_Automation_SLO::admission( 'unrelated-provider', 'read' );
g8_check( false === $uncertain['allowed'] && 'expired_automation_outcome_uncertain' === $uncertain['reason'],
	'unknown expired outcome site-fences automatic retry' );
g8_check( g8_is_error( MAD4B_SCP_Automation_SLO::ticket_allowed( $orphan ),
	'mad4b_automation_ticket_not_live' ), 'expired worker ticket cannot resume execution' );
g8_check( 1 === MAD4B_SCP_Automation_SLO::status()['pending_count'],
	'expired uncertain ticket is retained for governed reconciliation' );

echo 'G8_EXTENDED_CONTRACT: PASS' . PHP_EOL;
