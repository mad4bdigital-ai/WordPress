#!/usr/bin/env python3
"""Deterministic source-bound *disabled* Host Anchor v2 Registry declarations.

Never enrolls the live index, creates public keys, issues any signature or
touches WordPress. Installer must separately validate exact Source commit,
existing root Broker public identity, signed original312 issuer, independent
original313 verifier, and exact reserved jobs before activating.
"""
from __future__ import annotations
import argparse
import hashlib
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SUBROOT = ROOT/"host-anchor-v2"
PROVIDERS = ROOT/"dynamic-operation-registry-v1"/"providers"
ISSUE = "company.host-authority.anchor.issue"
REVOKE = "company.host-authority.anchor.revoke"
SCHEMA_FIELDS = {
    "result_status":"string",
    "operation_id":"string",
    "job_id":"string",
    "anchor_revision":"integer",
    "original313_proof_sha256":"sha256",
    "signed_original312_verified":"boolean",
    "independent_highwater_verified":"boolean",
    "original313_receipt_issued":"boolean",
}


def blob(relative: str) -> dict:
    data = (SUBROOT/relative).read_bytes()
    return {"path":"host-anchor-v2/"+relative,
            "sha256":hashlib.sha256(data).hexdigest()}


def canonical_document(doc: dict) -> bytes:
    return (json.dumps(doc,sort_keys=True,indent=2,ensure_ascii=False)+"\n").encode()


def artifacts() -> dict[Path,bytes]:
    paths={}
    for op in (ISSUE,REVOKE):
        name="issue" if op==ISSUE else "revoke"
        dest=PROVIDERS/op
        schema={
            "contract":"mad4b.dynamic-evidence-schema.v1",
            "schema_id":"host_anchor_"+name+".v2",
            "fields":SCHEMA_FIELDS,
            "success_state": "HOST_ANCHOR_SIGNED_"+name.upper()+"_PENDING_ORIGINAL313",
        }
        b=canonical_document(schema)
        schema_rel=f"dynamic-operation-registry-v1/providers/{op}/evidence-schema.json"
        plan=("mad4b.host-authority.anchor-issue.plan.v2" if op==ISSUE else
              "mad4b.host-authority.anchor-revoke.plan.v2")
        signed=(["mad4b.original312.host-principal.bootstrap-issue.v1",
                  "mad4b.original312.host-principal.exact-job.v2"] if op==ISSUE
                  else ["mad4b.original312.host-principal.exact-job.v2"])
        manifest={
            "contract":"mad4b.dynamic-operation-provider.v1",
            "operation_id":op,
            "provider":"mad4b-host-anchor-v2",
            "version":85,
            "plan_contract":plan,
            "source_family":"mad4b.original-host-authority-anchor.v2",
            "resource_kind":"host_anchor",
            "target_scope":"host",
            "job_type":"signed_remote_hostinger_wpcli_v2",
            "original312_contracts":signed,
            "independent313_contract":
                "mad4b.original313.host-anchor-operation-proof.v1",
            "execution_requires_signed_originals":True,
            "execution_enabled":False,
            "handler":blob("anchor_handler.py"),
            "verifier":blob("anchor_verifier.py"),
            "preclaim":blob("anchor_preclaim.py"),
            "finalizer":blob("anchor_finalizer.py"),
            "dependencies":[
                blob("operation_plan_contract.py"),
                blob("bootstrap_signed_job.py"),
                blob("original312_host_v2_exact_job_contract.py"),
                blob("operation313_evidence.py"),
            ],
            "evidence_schema":schema["schema_id"],
            "evidence":{"path":schema_rel,
                        "sha256":hashlib.sha256(b).hexdigest()},
            "command":{
                "format":"host.anchor.ref.v2",
                "mode":"plan_sha256",
                "bound_operation_id":op,
                "prefix":["mad4b","host-anchor",name],
                "flag":"--plan_sha256=",
            },
        }
        paths[dest/"evidence-schema.json"]=b
        paths[dest/"manifest.json"]=canonical_document(manifest)
    return paths


def main() -> None:
    parser=argparse.ArgumentParser()
    parser.add_argument("--verify",action="store_true")
    args=parser.parse_args()
    for path,data in artifacts().items():
        if args.verify:
            assert path.is_file() and path.read_bytes()==data, (
                "MANIFEST_SOURCE_CHANGED:"+str(path))
        else:
            path.parent.mkdir(parents=True,exist_ok=True)
            path.write_bytes(data)
        print(str(path.relative_to(ROOT)), hashlib.sha256(data).hexdigest())


if __name__ == "__main__":
    main()
