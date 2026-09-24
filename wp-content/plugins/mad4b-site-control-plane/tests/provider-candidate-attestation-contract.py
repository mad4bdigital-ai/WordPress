#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
provider = (ROOT / 'includes/class-mad4b-scp-provider-contracts.php').read_text('utf-8')
smoke = (ROOT / 'tests/runtime-jetengine-reversible-adapter-smoke.php').read_text('utf-8')
profiles = (ROOT / 'config/certified-provider-profiles.json').read_text('utf-8')

for marker in [
    'private static $profiles = null',
    'public static function profile_catalog()',
    'public static function candidate_attestation(',
    "$result['candidate_attestation'] = $candidate_attestation",
    "'candidate_policy_available'",
    "'candidate_relation' => 'installed_version_not_exact_candidate'",
    "'installed_version_eligible_for_candidate_attestation' => false",
    "'candidate_attestation_required'",
    "'candidate_attestation_required'",
]:
    assert marker in provider, marker

for marker in [
    'pending_semantic_review',
    'fail_closed_until_attested_exact_package_manifest',
    'candidate_attestation_required',
    'mad4b_provider_mutation_not_certified',
    'runtime-jetengine-side-channel-boundary.v2',
]:
    assert marker in smoke or marker in profiles, marker

# Candidate metadata must never weaken the existing mutation gate.
assert "public static function mutation_allowed" in provider
assert "empty( self::runtime_violations" in provider
assert "return new WP_Error( 'mad4b_provider_mutation_not_certified'" in provider
assert "candidate_attestation_required" in provider
assert "pending_semantic_review" in profiles
assert "fail_closed_until_attested_exact_package_manifest" in profiles

# Candidate metadata may explain a mismatch but can never turn a non-exact installed
# version into an attested candidate or add mutation authority.
assert "'attestation_required' => false" in provider
assert "'authorizing' => false" in provider

# The old exact certified-provider contract remains authoritative until explicit attestation updates it.
assert "if ( empty( $status['status'] ) || 'certified' !== $status['status'] )" in provider
assert "runtime_contract_ok" in provider

print('mad4b.provider-candidate-attestation.contract.v1: PASS')
