#!/usr/bin/env python3
from pathlib import Path
root=Path(__file__).resolve().parents[1]
critical=(root.parents[2]/".github/workflows/feature-007-critical-kernel.yml").read_text(encoding="utf-8")
audit=(root.parents[2]/".github/workflows/feature-007-pre-staging-hybrid-audit.yml").read_text(encoding="utf-8")
entropy=(root/"includes/class-mad4b-scp-entropy.php").read_text(encoding="utf-8")
prep=(root/"includes/class-mad4b-scp-preparation-receipt.php").read_text(encoding="utf-8")
breaker=(root/"includes/class-mad4b-scp-provider-circuit-breaker.php").read_text(encoding="utf-8")
fixture=(root/"tests/deterministic-test-runtime.php").read_text(encoding="utf-8")

for marker in ["Hermetic deterministic security kernel","MAD4B_TEST_SEED","MAD4B_TEST_WALL_EPOCH","MAD4B_TEST_MONOTONIC_MS","cmp -s"]:
    if marker not in critical: raise SystemExit("FAIL critical deterministic gate missing "+marker)
if "Hermetic deterministic security kernel" not in audit: raise SystemExit("FAIL pre-staging deterministic gate missing")
for forbidden in ["continue-on-error","|| true","exit 0","pytest-rerun","flaky"]:
    if forbidden in critical: raise SystemExit("FAIL critical release gate contains bypass marker "+forbidden)
for marker in ["MAD4B_SCP_TEST_RUNTIME","random_bytes","test_seed_max_bytes","max_request_bytes","mad4b_entropy_test_seed_forbidden"]:
    if marker not in entropy: raise SystemExit("FAIL entropy invariant missing "+marker)
if "MAD4B_SCP_Entropy::hex( 'preparation_receipt_nonce', 16 )" not in prep:
    raise SystemExit("FAIL preparation nonce not routed through bounded entropy")
if "MAD4B_SCP_Entropy::hex('provider_breaker_probe',16)" not in breaker:
    raise SystemExit("FAIL breaker probe token not routed through bounded entropy")
for marker in ["MAD4B_SCP_Time_Policy::set_test_clock","MAD4B_SCP_Entropy::set_test_seed","mad4b.hermetic-critical-ci.v1"]:
    if marker not in fixture: raise SystemExit("FAIL deterministic fixture missing "+marker)
print("mad4b.hermetic-critical-ci.contract.v1: PASS")
