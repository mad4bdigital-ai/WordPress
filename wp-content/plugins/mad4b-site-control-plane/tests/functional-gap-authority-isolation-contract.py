#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
INCLUDES = ROOT / 'includes'
evidence_path = INCLUDES / 'class-mad4b-scp-functional-gap-evidence.php'
evidence = evidence_path.read_text('utf-8')

required = [
    "mad4b.functional-gap-decision-handoff.v1",
    "'promotion_authorized' => false",
    "'mutation_authorized' => false",
    "'must_revalidate_before_mutation' => true",
    "'revalidation_contract' => 'recompute_current_runtime_and_require_exact_fingerprint_match'",
    "'runtime_evidence_fingerprint'",
    "'decision_fingerprint'",
    "'snapshot_identity_sha256'",
    "public static function decision_handoff()",
]
for marker in required:
    if marker not in evidence:
        raise SystemExit(f'missing non-authorizing decision handoff invariant: {marker}')

# Decision identity is evidence, not authority. It may only originate from the
# read-only evidence class. Any future mutation workflow must introduce an
# explicit plan-bound revalidation contract rather than consume these tokens
# directly inside an existing authority/mutation class.
sensitive = [
    'mad4b.functional-gap-decision-handoff.v1',
    'must_revalidate_before_mutation',
    'recompute_current_runtime_and_require_exact_fingerprint_match',
]
violations = []
for php in sorted(INCLUDES.rglob('*.php')):
    if php == evidence_path:
        continue
    text = php.read_text('utf-8', errors='replace')
    for marker in sensitive:
        if marker in text:
            violations.append(f'{php.relative_to(ROOT)}:{marker}')
if violations:
    raise SystemExit('functional-gap decision handoff leaked outside read-only evidence class: ' + ', '.join(violations))

# No current writer may consume the handoff accessor directly.
for php in sorted(INCLUDES.rglob('*.php')):
    if php == evidence_path:
        continue
    text = php.read_text('utf-8', errors='replace')
    if 'MAD4B_SCP_Functional_Gap_Evidence::decision_handoff(' in text:
        raise SystemExit(f'current mutation/read class consumes decision handoff directly: {php.relative_to(ROOT)}')

for forbidden in [
    "'promotion_authorized' => true",
    "'mutation_authorized' => true",
    "'must_revalidate_before_mutation' => false",
]:
    if forbidden in evidence:
        raise SystemExit(f'functional-gap handoff authority boundary weakened: {forbidden}')

print('mad4b.functional-gap-authority-isolation.contract.v1: PASS')
