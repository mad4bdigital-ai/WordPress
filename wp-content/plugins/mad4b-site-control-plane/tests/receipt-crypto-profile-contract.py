#!/usr/bin/env python3
from pathlib import Path
import json
root=Path(__file__).resolve().parents[1]
cfg=json.loads((root/"config/crypto-profiles.json").read_text(encoding="utf-8"))
time_cfg=json.loads((root/"config/time-policy.json").read_text(encoding="utf-8"))
crypto=(root/"includes/class-mad4b-scp-crypto-profile.php").read_text(encoding="utf-8")
prep=(root/"includes/class-mad4b-scp-preparation-receipt.php").read_text(encoding="utf-8")
context=(root/"includes/class-mad4b-scp-context-preflight.php").read_text(encoding="utf-8")
execution=(root/"includes/class-mad4b-scp-execution-receipt.php").read_text(encoding="utf-8")

expected={
 "execution_receipt":"execution-receipt-rs256-v1",
 "preparation_receipt":"preparation-receipt-rs256-v1",
 "context_receipt":"context-receipt-rs256-v1",
}
if cfg.get("default_profiles") != expected:
    raise SystemExit("FAIL receipt crypto default profiles drift")
for purpose,prefix in [("execution_receipt","execution-receipt"),("preparation_receipt","preparation-receipt"),("context_receipt","context-receipt")]:
    for suffix,alg in [("rs256-v1","RS256"),("rs512-v2","RS512")]:
        row=cfg.get("profiles",{}).get(f"{prefix}-{suffix}",{})
        if row.get("purpose")!=purpose or row.get("algorithm")!=alg or row.get("enabled") is not True:
            raise SystemExit(f"FAIL {purpose} algorithm profile {alg}")
if "context_receipt" not in time_cfg.get("purposes",{}):
    raise SystemExit("FAIL context receipt time policy missing")
for marker in ["sign_digest_for_purpose","verify_digest_for_purpose","mad4b_crypto_signature_purpose_mismatch","mad4b_crypto_signature_overlap_expired","mad4b_crypto_signature_key_retired"]:
    if marker not in crypto: raise SystemExit("FAIL crypto lifecycle invariant missing "+marker)
for marker in ["CRYPTO_PURPOSE = 'preparation_receipt'","sign_digest_for_purpose","verify_digest_for_purpose"]:
    if marker not in prep: raise SystemExit("FAIL preparation receipt crypto migration missing "+marker)
if "hash_hmac" in prep or "wp_salt" in prep:
    raise SystemExit("FAIL preparation receipt retains legacy local HMAC signing")
for marker in ["RECEIPT_CRYPTO_PURPOSE = 'context_receipt'","sign_digest_for_purpose","verify_digest_for_purpose"]:
    if marker not in context: raise SystemExit("FAIL context receipt crypto migration missing "+marker)
if "receipt_signature_key" in context or "hash_hmac" in context or "wp_salt" in context:
    raise SystemExit("FAIL context receipt retains legacy local HMAC signing")
if "verify_digest_for_purpose( $receipt['signature'], $expected, 'execution_receipt' )" not in execution:
    raise SystemExit("FAIL execution receipt verification is not purpose-bound")
print("mad4b.receipt-crypto-profile.contract.v1: PASS")
