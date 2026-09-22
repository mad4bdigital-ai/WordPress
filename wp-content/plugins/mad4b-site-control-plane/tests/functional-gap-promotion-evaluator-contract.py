#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
script = (ROOT.parents[2] / 'tools/evaluate-functional-gap-contract-promotion.py').read_text('utf-8')

required = [
    "mad4b.functional-gap-promotion-evaluation.v1",
    '"promotion_authorized":False',
    '"production_mutation":False',
    '"read_contract_candidate"',
    '"redacted_read_contract_candidate"',
    '"runtime_alignment_required"',
    '"semantic_attestation_required"',
    '"behavioral_recertification_required"',
    '"runtime_contract_evidence_captured"',
    "exact_runtime_tree_and_required_get_routes_verified",
    "exact_runtime_tree_and_secret_redaction_verified",
    "live_runtime_tree_does_not_match_repository_evidence",
]
for marker in required:
    if marker not in script:
        raise SystemExit(f'missing promotion evaluator invariant: {marker}')

for forbidden in [
    '"functional_ready"',
    '"promotion_authorized":True',
    '"production_mutation":True',
]:
    if forbidden in script:
        raise SystemExit(f'promotion evaluator must not auto-authorize functional/write readiness: {forbidden}')

# The two candidates that may graduate are intentionally read-only only.
bulk = script.split('family="bulk-taxonomy-editor"', 1)[1].split('# WPL', 1)[0]
for marker in ['posts_read', 'taxonomies_read', 'terms_read', 'post_create_or_update', 'bulk_taxonomy_write']:
    if marker not in bulk:
        raise SystemExit(f'bulk-taxonomy read-only boundary missing: {marker}')

wpl = script.split('family="wpl-client"', 1)[1].split('# Exact package identity', 1)[0]
for marker in ['license_verification_state_read_redacted', 'credential_read', 'plugin_or_theme_install', 'external_write']:
    if marker not in wpl:
        raise SystemExit(f'wpl redacted-read boundary missing: {marker}')

print('mad4b.functional-gap-promotion-evaluator.contract.v1: PASS')
