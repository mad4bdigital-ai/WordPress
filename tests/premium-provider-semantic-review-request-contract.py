#!/usr/bin/env python3
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROL = ROOT / "wp-content/plugins/mad4b-site-control-plane"
REQUESTS = json.loads((CONTROL / "config/premium-provider-semantic-review-requests.json").read_text(encoding="utf-8"))
PROFILES = json.loads((CONTROL / "config/certified-provider-profiles.json").read_text(encoding="utf-8"))
ATTEST = json.loads((CONTROL / "config/premium-provider-attestations.json").read_text(encoding="utf-8"))

if REQUESTS.get("contract") != "mad4b.premium-provider-semantic-review-requests.v1":
    raise SystemExit("semantic review request contract drifted")
if REQUESTS.get("non_authorizing") is not True:
    raise SystemExit("semantic review request became authorizing")

evidence = REQUESTS.get("evidence", {})
if not re.fullmatch(r"[a-f0-9]{40}", str(evidence.get("source_head_sha", ""))):
    raise SystemExit("semantic review evidence head is invalid")
if not str(evidence.get("workflow_run_id", "")).isdigit():
    raise SystemExit("semantic review workflow run id is invalid")
if not isinstance(evidence.get("artifact_id"), int) or evidence["artifact_id"] <= 0:
    raise SystemExit("semantic review artifact id is invalid")
if not re.fullmatch(r"sha256:[a-f0-9]{64}", str(evidence.get("artifact_digest", ""))):
    raise SystemExit("semantic review artifact digest is invalid")

premium_policy = PROFILES.get("premium_provider_policy", {})
requests = REQUESTS.get("requests", {})
expected = {"jetengine": "3.8.15", "jetsmartfilters": "3.8.5"}
if set(requests) != set(expected):
    raise SystemExit("semantic review provider set drifted")

for provider, version in expected.items():
    row = requests[provider]
    policy = premium_policy[provider]
    if row.get("provider") != provider or row.get("version") != version:
        raise SystemExit(f"{provider}: request identity drifted")
    if row.get("semantic_review_status") != "pending":
        raise SystemExit(f"{provider}: semantic review must remain pending")
    if row.get("promotion_authorized") is not False:
        raise SystemExit(f"{provider}: promotion must remain denied")
    if row.get("repository_source_archive_sha256") != policy.get("repository_archive_sha256"):
        raise SystemExit(f"{provider}: source archive SHA does not match repository policy")
    if row.get("normalized_archive_sha256") != policy.get("normalized_archive_sha256"):
        raise SystemExit(f"{provider}: normalized archive SHA does not match repository policy")
    if int(row.get("normalized_archive_bytes", -1)) != int(policy.get("normalized_archive_bytes", -2)):
        raise SystemExit(f"{provider}: normalized archive byte count does not match repository policy")
    if not re.fullmatch(r"[a-f0-9]{64}", str(row.get("critical_manifest_sha256", ""))):
        raise SystemExit(f"{provider}: critical manifest digest invalid")
    if int(row.get("changed_critical_file_count", 0)) <= 0:
        raise SystemExit(f"{provider}: semantic review request must expose changed critical files")
    if PROFILES.get("providers", {}).get(provider):
        raise SystemExit(f"{provider}: certified profile exists before semantic review")
    if ATTEST.get("attestations", {}).get(provider):
        raise SystemExit(f"{provider}: authorizing attestation exists before semantic review")

print("mad4b.premium-provider-semantic-review-requests.v1: PASS")
