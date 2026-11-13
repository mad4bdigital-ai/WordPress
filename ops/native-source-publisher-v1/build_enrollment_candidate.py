#!/usr/bin/env python3
"""Build a provider manifest from an explicit operation definition.

No operation ID or approved ability is included in application code.
An untrusted definition alone CANNOT enroll a Provider: Original306 verifies
root-owned first-party registry, exact component hashes and authorization.
"""
import argparse
import hashlib
import importlib.util
import json
from pathlib import Path
import re

ROOT=Path(__file__).resolve().parents[2]
FOLDER=Path(__file__).resolve().parent
CATALOG=FOLDER/"catalog"
DEFINITIONS=CATALOG/"operations"
GENERATED=CATALOG/"generated"
CORE=ROOT/"ops/company-code-release-v1/company_code_release.py"
OP=re.compile(r"^[a-z][a-z0-9._-]{2,90}$")
ABILITY=re.compile(r"^[a-z0-9][a-z0-9._-]*/[a-z0-9][a-z0-9._-]*$")


def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def build(definition: dict | None = None) -> dict:
    if definition is None:
        raise ValueError("SOURCE_OPERATION_DEFINITION_REQUIRED")
    if (not isinstance(definition,dict) or
        definition.get("contract")!="mad4b.native-source-operation-definition.v2" or
        set(definition)!={"contract","operation_id","provider","approval_ability",
                         "source_family","version","evidence_schema","command"}):
        raise ValueError("SOURCE_OPERATION_DEFINITION_INVALID")
    for name in ("operation_id","provider","evidence_schema"):
        if not isinstance(definition[name],str) or not OP.fullmatch(definition[name]):
            raise ValueError("SOURCE_OPERATION_IDENTIFIER_INVALID")
    if not ABILITY.fullmatch(str(definition["approval_ability"])):
        raise ValueError("SOURCE_OPERATION_ABILITY_INVALID")
    if (definition["source_family"]!="mad4b.native-source-release.v2" or
        type(definition["version"]) is not int or not 1<=definition["version"]<10000):
        raise ValueError("SOURCE_OPERATION_FAMILY_OR_VERSION_INVALID")
    cmd=definition["command"]
    if (not isinstance(cmd,dict) or set(cmd)!={"format","mode","prefix","flag"} or
        cmd["format"]!="wpcli.argv.json.v1" or cmd["mode"]!="plan_sha256" or
        cmd["flag"]!="--plan_sha256=" or
        not isinstance(cmd["prefix"],list) or not 1<=len(cmd["prefix"])<=6 or
        any(not isinstance(x,str) or not OP.fullmatch(x) for x in cmd["prefix"])):
        raise ValueError("SOURCE_OPERATION_COMMAND_UNSAFE")
    files={
      "handler":(FOLDER/"original_313_host_handler.py",
        "native-source-publisher-v1/original_313_host_handler.py"),
      "preclaim":(FOLDER/"original_306_preclaim.py",
        "native-source-publisher-v1/original_306_preclaim.py"),
      "original305_reader":(ROOT/"ops/dynamic-operation-registry-v1/executor-time-cas-v1/preclaim.py",
        "executor-time-cas-v1/preclaim.py"),
      "verifier":(FOLDER/"original_313_independent_readback.py",
        "native-source-publisher-v1/original_313_independent_readback.py"),
      "finalizer":(FOLDER/"original_313_source_finalizer.py",
        "native-source-publisher-v1/original_313_source_finalizer.py"),
      "source":(FOLDER/"native_source_publisher.py",
        "native-source-publisher-v1/native_source_publisher.py"),
      "core":(CORE,"company-code-release-v1/company_code_release.py"),
      "association":(FOLDER/"operation_plan_association.py",
        "native-source-publisher-v1/operation_plan_association.py"),
      "evidence":(CATALOG/"evidence-schema.json",
        "dynamic-operation-registry-v1/providers/"+definition["operation_id"]+"/evidence-schema.json"),
    }
    for path,relative in files.values():
        if not path.is_file() or path.is_symlink() or not 1<=path.stat().st_size<=2_000_000:
            raise ValueError("SOURCE_CANDIDATE_COMPONENT_INVALID:"+relative)
    evidence=json.loads(files["evidence"][0].read_text())
    if evidence["schema_id"]!=definition["evidence_schema"]:
        raise ValueError("SOURCE_EVIDENCE_SCHEMA_MISMATCH")
    return {
        "contract":"mad4b.dynamic-operation-provider.v1",
        "operation_id":definition["operation_id"],
        "source_family":definition["source_family"],
        "approval_ability":definition["approval_ability"],
        "provider":definition["provider"],
        "version":definition["version"],
        "plan_contract":"mad4b.company-code-release.plan.v1",
        "handler":{"path":files["handler"][1],"sha256":sha(files["handler"][0])},
        "preclaim":{"path":files["preclaim"][1],"sha256":sha(files["preclaim"][0])},
        "verifier":{"path":files["verifier"][1],"sha256":sha(files["verifier"][0])},
        "finalizer":{"path":files["finalizer"][1],"sha256":sha(files["finalizer"][0])},
        # This signed first-party callback is required by the ORIGINAL306
        # Worker whenever this source-family operation shares a contract.
        # Its independent issuer public key is enrolled in the same root
        # Registry index by the certified first-party publisher, never here.
        "hooks":{"operation_association":{
            "path":files["association"][1],
            "sha256":sha(files["association"][0]),
            "entrypoint":"exclusive_operation"}},
        "evidence_schema":definition["evidence_schema"],
        "evidence":{"path":files["evidence"][1],"sha256":sha(files["evidence"][0])},
        "command":dict(cmd,bound_operation_id=definition["operation_id"]),
        "dependencies":[
            {"path":files["source"][1],"sha256":sha(files["source"][0])},
            {"path":files["core"][1],"sha256":sha(files["core"][0])},
            {"path":files["original305_reader"][1],
             "sha256":sha(files["original305_reader"][0])},
        ],
    }


def definitions(folder: Path = DEFINITIONS) -> list[dict]:
    """Read arbitrary declarative operation descriptors; NEVER a code allowlist."""
    if not folder.is_dir() or folder.is_symlink():
        raise ValueError("SOURCE_CATALOG_DIRECTORY_NOT_VALID")
    files=sorted(folder.glob("*.json"))
    if not files or len(files)>128:
        raise ValueError("SOURCE_OPERATION_CATALOG_EMPTY_OR_TOO_LARGE")
    operations=[]
    ids=set()
    for file in files:
        if not file.is_file() or file.is_symlink() or not 1<=file.stat().st_size<=25000:
            raise ValueError("SOURCE_OPERATION_DEFINITION_UNSAFE")
        item=json.loads(file.read_text())
        op=item.get("operation_id")
        if not isinstance(op,str) or file.name!=op+".json" or op in ids:
            raise ValueError("SOURCE_OPERATION_FILENAME_OR_DUPLICATE")
        ids.add(op)
        operations.append(item)
    return operations


def build_all(folder: Path = DEFINITIONS) -> dict[str,dict]:
    result={}
    for item in definitions(folder):
        manifest=build(item)
        result[manifest["operation_id"]]=manifest
    return result



def validate_enrollment_documents(catalog: Path = CATALOG,
                                  definitions_dir: Path = DEFINITIONS) -> dict:
    """Fail-closed source-only admission lint; never enroll or approve a provider.

    Resolve runtime constants from the actual publisher implementation, not
    from a second hardcoded operation list or a stale documentation version.
    """
    publisher_path=FOLDER/"native_source_publisher.py"
    spec=importlib.util.spec_from_file_location(
        "_mad4b_source_candidate_contracts",publisher_path)
    if spec is None or spec.loader is None:
        raise ValueError("SOURCE_PUBLISHER_RUNTIME_NOT_READABLE")
    publisher=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(publisher)
    template_path=catalog/"policy-template.DISABLED.json"
    proposal_path=catalog/"publisher-enrollment-proposal.json"
    for path in (template_path,proposal_path):
        if (not path.is_file() or path.is_symlink() or
            not 1<=path.stat().st_size<=25000):
            raise ValueError("SOURCE_ENROLLMENT_DOCUMENT_UNTRUSTED")
    template=json.loads(template_path.read_text())
    proposal=json.loads(proposal_path.read_text())
    if not isinstance(template,dict) or not isinstance(proposal,dict):
        raise ValueError("SOURCE_ENROLLMENT_DOCUMENT_INVALID")
    if (template.get("contract")!=publisher.POLICY_CONTRACT or
        template.get("enabled") is not False or
        template.get("broker_public_key_b64")!="NOT_ENROLLED" or
        template.get("master_approval_public_key_b64")!="NOT_ENROLLED"):
        raise ValueError("SOURCE_DISABLED_POLICY_TEMPLATE_CONTRACT_DRIFT")
    if (proposal.get("source_only") is not True or
        any(proposal.get(k) is not False for k in (
           "registry_enrolled","publisher_enabled","production_writes_authorized")) or
        proposal.get("approval_contract")!=publisher.APPROVAL_CONTRACT or
        proposal.get("policy_contract")!=publisher.POLICY_CONTRACT or
        proposal.get("policy_path")!=str(publisher.POLICY_PATH) or
        proposal.get("source_family")!=publisher.FAMILY):
        raise ValueError("SOURCE_ENROLLMENT_PROPOSAL_CONTRACT_DRIFT")
    source_ops=definitions(definitions_dir)
    operation=next((d for d in source_ops if
        d["operation_id"]==proposal.get("operation_id")),None)
    if (operation is None or
        proposal.get("approval_ability")!=operation["approval_ability"] or
        proposal.get("native_provider_id")!=operation["provider"] or
        proposal.get("source_family")!=operation["source_family"]):
        raise ValueError("SOURCE_ENROLLMENT_OPERATION_BINDING_DRIFT")
    scope=str(proposal.get("company",""))+":"+str(proposal.get("environment",""))
    targets=template.get("targets")
    if not isinstance(targets,dict) or scope not in targets:
        raise ValueError("SOURCE_ENROLLMENT_TARGET_NOT_DECLARED")
    target=targets[scope]
    if (not isinstance(target,dict) or
        target.get("site_uuid")!=proposal.get("site_uuid") or
        target.get("origin")!=proposal.get("origin") or
        target.get("allowed_targets")!=proposal.get("file_allowlist") or
        template.get("operation")!=proposal.get("operation_id")):
        raise ValueError("SOURCE_ENROLLMENT_SITE_OR_TARGET_DRIFT")
    return {"state":"SOURCE_ONLY_ENROLLMENT_CONTRACTS_ALIGNED",
            "operation_id":operation["operation_id"],
            "policy_enabled":False,"registry_enrolled":False,
            "broker_or_master_grant_created":False}


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument("--definitions-dir",type=Path,default=DEFINITIONS)
    parser.add_argument("--output-dir",type=Path,default=GENERATED)
    args=parser.parse_args()
    validate_enrollment_documents(CATALOG,args.definitions_dir)
    manifests=build_all(args.definitions_dir)
    args.output_dir.mkdir(parents=True,exist_ok=True)
    rows=[]
    for op,manifest in manifests.items():
        dest=args.output_dir/(op+".manifest.json")
        dest.write_text(json.dumps(manifest,indent=2,sort_keys=True)+"\n")
        rows.append({"operation_id":op,"provider":manifest["provider"],
              "candidate_manifest_sha256":sha(dest)})
    print(json.dumps({
        "state":"SOURCE_ONLY_MANIFESTS_PINNED__REGISTRY_NOT_INSTALLED",
        "registered_candidate_count":len(rows),
        "candidates":rows,
        "first_party_registry_mutated":False},sort_keys=True))


if __name__=="__main__":
    main()
