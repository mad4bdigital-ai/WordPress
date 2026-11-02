#!/usr/bin/env python3
"""Read-only Start Company → Reconcile completeness reducer.

Consumes exact-site evidence issued by existing MAD4B providers. The reducer never
grants authority, writes a site, publishes a page, or treats a proposed job as run.
Usage: python3 reconcile.py --policy company-start-reconcile-policy.json --evidence evidence.json
"""
from __future__ import annotations
import argparse
import datetime as dt
import hashlib
import json
from pathlib import Path

OBSERVATION_CONTRACT = "mad4b.company-start-observation.v1"
REQUIRED_TARGET_FIELDS = ("company_id", "site_uuid", "canonical_origin", "environment", "profile_revision")
SAFE_STATES = frozenset(("PASS", "NOT_APPLICABLE"))
STAGES = ("bootstrap", "functional", "publish", "commercial")


def canonical(obj):
    return json.dumps(obj, sort_keys=True, ensure_ascii=False, separators=(",", ":"))


def fingerprint(obj):
    return hashlib.sha256(canonical(obj).encode("utf-8")).hexdigest()


def parse_time(value):
    if not isinstance(value, str) or not value:
        return None
    try:
        got = dt.datetime.fromisoformat(value.replace("Z", "+00:00"))
        return got.astimezone(dt.timezone.utc) if got.tzinfo else None
    except (ValueError, TypeError, OverflowError):
        return None


def assess(policy, evidence, *, now=None):
    now = now or dt.datetime.now(dt.timezone.utc)
    if now.tzinfo is None:
        raise ValueError("now must contain timezone")
    if policy.get("contract") != "mad4b.company-start-reconcile-completeness-policy.v1":
        raise ValueError("policy contract mismatch")
    if evidence.get("contract") != OBSERVATION_CONTRACT:
        raise ValueError("evidence contract mismatch")
    target = evidence.get("target") or {}
    if any(not target.get(k) for k in REQUIRED_TARGET_FIELDS):
        raise ValueError("exact target identity incomplete")
    if target["environment"] not in ("staging", "production"):
        raise ValueError("unsupported environment")
    if evidence.get("mode") not in policy["modes"]:
        raise ValueError("unsupported new-company mode")
    if evidence.get("source_origin") == target["canonical_origin"] and evidence["mode"] != "fresh":
        raise ValueError("cross-site source cannot equal destination")
    manifest = evidence.get("manifest") or {}
    for k in policy["invariants"]["expected_manifest_requires"]:
        if not manifest.get(k):
            raise ValueError("manifest incomplete: " + k)
    obs = evidence.get("observations") or {}
    if not isinstance(obs, dict):
        raise ValueError("observations must be object")
    checks = policy["checks"]
    required = {name: set(policy["gates"][name]) for name in STAGES}
    results, proposals, blockers = {}, [], []
    valid_identity = True
    max_age = policy["invariants"]["evidence_max_age_seconds"]
    disabled = set(evidence.get("disabled_features") or [])
    for check_id, rule in checks.items():
        reading = obs.get(check_id) or {}
        state = str(reading.get("state") or "UNKNOWN").upper()
        if state not in ("PASS", "NOT_APPLICABLE", "FAIL", "PENDING", "UNKNOWN", "BLOCKED"):
            state = "UNKNOWN"
        reason = reading.get("reason") or "no_evidence"
        if state in SAFE_STATES:
            if any(reading.get(k) != target.get(k) for k in REQUIRED_TARGET_FIELDS):
                state, reason = "WRONG_TARGET", "site_binding_mismatch"
            elif reading.get("manifest_digest") != manifest["eligible_object_manifest_digest"]:
                state, reason = "STALE_MANIFEST", "manifest_digest_mismatch"
            elif not reading.get("receipt_id"):
                state, reason = "UNVERIFIED", "missing_provider_receipt"
            else:
                seen = parse_time(reading.get("observed_at"))
                if seen is None or (now - seen).total_seconds() > max_age or seen > now + dt.timedelta(seconds=60):
                    state, reason = "STALE_EVIDENCE", "expired_or_future_receipt"
                elif state == "NOT_APPLICABLE":
                    if (check_id not in disabled or not reading.get("signed_feature_disabled")
                            or not reading.get("reason")):
                        state, reason = "UNVERIFIED", "unsupported_n_a"
        results[check_id] = {"state": state, "severity": rule["severity"], "owner": rule["owner"],
                             "reason": reason}
        if state not in SAFE_STATES:
            blockers.append({"check": check_id, "state": state, "severity": rule["severity"], "reason": reason})
            if check_id in ("identity_binding", "authority_chain", "source_scope", "private_data_exclusion"):
                valid_identity = False
            job_hash = fingerprint([target, manifest["eligible_object_manifest_digest"], check_id,
                                    reading.get("observed_digest", "none")])
            proposals.append({"job_type": rule["repair"], "check": check_id, "owner": rule["owner"],
                              "priority": rule["severity"], "job_key": job_hash,
                              "auto_remediation_candidate": bool(rule["auto"]),
                              "authorized": False, "requires_exact_site_grant": True,
                              "requires_execution_and_readback_receipts": True})
    # Runtime gating is layered: install truth is never a publish proof.
    stage_results = {}
    cumulative = set()
    for stage in STAGES:
        cumulative.update(required[stage])
        failed = sorted(x for x in cumulative if results[x]["state"] not in SAFE_STATES)
        stage_results[stage] = {"passed": not failed, "missing": failed,
                                "required_checks": len(cumulative)}
    serving = bool(evidence.get("public_serving", False))
    if serving and not stage_results["publish"]["passed"]:
        status = "LIVE_WITH_GAPS_P0"
    elif not stage_results["bootstrap"]["passed"]:
        status = "BLOCKED_BOOTSTRAP"
    elif not stage_results["functional"]["passed"]:
        status = "RECONCILING_FUNCTIONAL"
    elif not stage_results["publish"]["passed"]:
        status = "BLOCKED_PUBLICATION"
    elif not serving:
        status = "READY_TO_PUBLISH"
    elif stage_results["commercial"]["passed"]:
        status = "COMMERCIAL_READY"
    else:
        status = "LIVE_VERIFIED_COMMERCE_PENDING"
    if not valid_identity:
        # Proposed operations cannot be auto-dispatched without original identity/authority.
        for j in proposals:
            j["auto_remediation_candidate"] = False
    return {
        "contract": "mad4b.company-start-reconcile-verdict.v1",
        "company_id": target["company_id"], "site_uuid": target["site_uuid"],
        "canonical_origin": target["canonical_origin"], "environment": target["environment"],
        "mode": evidence["mode"], "profile_revision": target["profile_revision"],
        "manifest_digest": manifest["eligible_object_manifest_digest"],
        "status": status, "public_serving": serving, "authority_issued": False,
        "mutation_performed": False, "ready_to_publish": stage_results["publish"]["passed"],
        "commercial_ready": stage_results["commercial"]["passed"] and serving,
        "stage_results": stage_results, "checks": results,
        "blockers": blockers, "job_proposals": proposals,
        "receipt_digest": fingerprint([target, manifest, results, serving]),
        "reconcile_source_only": True
    }


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--policy", required=True)
    ap.add_argument("--evidence", required=True)
    ap.add_argument("--output")
    args = ap.parse_args()
    decision = assess(json.loads(Path(args.policy).read_text(encoding="utf-8")),
                      json.loads(Path(args.evidence).read_text(encoding="utf-8")))
    payload = json.dumps(decision, ensure_ascii=False, indent=2, sort_keys=True) + "\n"
    if args.output:
        Path(args.output).write_text(payload, encoding="utf-8")
    else:
        print(payload, end="")
    return 0 if decision["ready_to_publish"] else 2


if __name__ == "__main__":
    raise SystemExit(main())
