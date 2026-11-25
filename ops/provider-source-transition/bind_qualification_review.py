#!/usr/bin/env python3
"""Bind unsigned fixture review to exact source/package; never grant admission."""
import argparse
import hashlib
import json
import re
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
ADAPTER = "wp-content/plugins/mad4b-site-control-plane/includes/adapters/class-mad4b-scp-dfsb-adapter.php"

def digest(data):
    return hashlib.sha256(data).hexdigest()

def bind(build, package_sha, proposal, audit, head, adapter_sha):
    if build.get("source_sha") != head or build.get("zip_sha256") != package_sha or build.get("unsigned") is not True or build.get("certified") is not False:
        raise ValueError("exact unsigned package identity mismatch")
    if not re.fullmatch(r"[a-f0-9]{64}", proposal.get("descriptor_sha256", "")) or proposal.get("scope") != "isolated_fixture_only" or proposal.get("state") != "REVIEW_REQUIRED":
        raise ValueError("invalid fixture descriptor observation")
    for key in ("native_policy_changed", "runtime_admission_verified", "authorizing"):
        if proposal.get(key) is not False:
            raise ValueError("proposal must remain non-authorizing")
    if build.get("version") != audit.get("neutral_plugin_version") or audit.get("source_only_audit") is not True or audit.get("safe_to_cutover") is not False:
        raise ValueError("source audit mismatch")
    return {"contract":"mad4b.dfsb.exact-qualification-review.v1", "source_sha":head, "version":build["version"], "package_sha256":package_sha, "adapter_source_sha256":adapter_sha, "fixture_descriptor_sha256":proposal["descriptor_sha256"], "proposal_sha256":digest(json.dumps(proposal,sort_keys=True,separators=(",", ":")).encode()), "proposed_capabilities":proposal["capability_count"], "covered_names":proposal["covered_names"], "state":"UNSIGNED_REVIEW_ONLY", "runtime_descriptor_generation_verified":False, "provider_certified":False, "runtime_admission_verified":False, "authorizing":False}

def main():
    p=argparse.ArgumentParser()
    for name in ("build","package","fixture","audit"):
        p.add_argument("--"+name,type=Path,required=True)
    a=p.parse_args()
    head=subprocess.check_output(["git","-C",str(ROOT),"rev-parse","HEAD"],text=True).strip()
    committed=subprocess.check_output(["git","-C",str(ROOT),"show",head+":"+ADAPTER])
    if committed != (ROOT/ADAPTER).read_bytes():
        raise ValueError("adapter source differs from exact HEAD")
    rows=[json.loads(line) for line in a.fixture.read_text().splitlines() if line.startswith("{")]
    proposals=[row for row in rows if row.get("contract")=="mad4b.dfsb.capability-classification-proposal.v1"]
    if len(proposals)!=1:
        raise ValueError("exactly one fixture proposal required")
    print(json.dumps(bind(json.loads(a.build.read_text()),digest(a.package.read_bytes()),proposals[0],json.loads(a.audit.read_text()),head,digest(committed)),indent=2,sort_keys=True))

if __name__=="__main__":
    main()
