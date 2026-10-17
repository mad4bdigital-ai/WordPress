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

expected={"execution_receipt":"execution-receipt-rs256-v1","preparation_receipt":"preparation-receipt-rs256-v1","context_receipt":"context-receipt-rs256-v1","production_evidence":"production-evidence-rs256-v1","behavioral_evidence":"behavioral-evidence-rs256-v1","certification_pack":"certification-pack-rs256-v1"}
if cfg.get("default_profiles") != expected: raise SystemExit("FAIL receipt crypto default profiles drift")
for purpose,prefix in (("execution_receipt","execution-receipt"),("preparation_receipt","preparation-receipt"),("context_receipt","context-receipt"),("production_evidence","production-evidence")):
    for suffix,alg in (("rs256-v1","RS256"),("rs512-v2","RS512")):
        row=cfg.get("profiles",{}).get(f"{prefix}-{suffix}",{})
        if row.get("purpose")!=purpose or row.get("algorithm")!=alg or row.get("enabled") is not True: raise SystemExit(f"FAIL {purpose} algorithm profile {alg}")
for purpose in ("behavioral_evidence","certification_pack"):
    row=cfg.get("profiles",{}).get(expected[purpose],{})
    if row.get("purpose")!=purpose or row.get("algorithm")!="RS256" or row.get("enabled") is not True or row.get("key_bits",0)<3072 or row.get("overlap_seconds")!=86400 or row.get("max_active_keys")!=2:
        raise SystemExit("FAIL G3 purpose-specific crypto lifecycle "+purpose)
for purpose in ("context_receipt","production_evidence"):
    if purpose not in time_cfg.get("purposes",{}): raise SystemExit("FAIL "+purpose+" time policy missing")
if time_cfg["purposes"]["production_evidence"].get("max_age_seconds",0)<=0: raise SystemExit("FAIL production evidence freshness is unbounded")
for marker in ("sign_digest_for_purpose","verify_digest_for_purpose","mad4b_crypto_signature_purpose_mismatch","mad4b_crypto_signature_overlap_expired","mad4b_crypto_signature_key_retired"):
    if marker not in crypto: raise SystemExit("FAIL crypto lifecycle invariant missing "+marker)
for marker in ("CRYPTO_PURPOSE = 'preparation_receipt'","sign_digest_for_purpose","verify_digest_for_purpose"):
    if marker not in prep: raise SystemExit("FAIL preparation receipt crypto migration missing "+marker)
if "hash_hmac" in prep or "wp_salt" in prep: raise SystemExit("FAIL preparation receipt retains legacy local HMAC signing")
for marker in ("RECEIPT_CRYPTO_PURPOSE = 'context_receipt'","sign_digest_for_purpose","verify_digest_for_purpose"):
    if marker not in context: raise SystemExit("FAIL context receipt crypto migration missing "+marker)
if "receipt_signature_key" in context or "self::receipt_signature(" in context or "hash_hmac" in context or "wp_salt" in context: raise SystemExit("FAIL context receipt retains legacy local HMAC signing/callsite")
if "verify_digest_for_purpose( $receipt['signature'], $expected, 'execution_receipt' )" not in execution: raise SystemExit("FAIL execution receipt verification is not purpose-bound")
print("mad4b.receipt-crypto-profile.contract.v1: PASS")
