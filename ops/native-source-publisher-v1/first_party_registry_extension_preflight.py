#!/usr/bin/env python3
"""Read-only, manifest-driven Original306/313 registry extension preflight.

Never installs, enrolls, signs, approves, issues, claims or executes. It uses
both the installed trusted index and the existing first-party source validator
instead of a new operations allowlist or a parallel Provider executor.
"""
from __future__ import annotations

import base64
import hashlib
import importlib.util
import json
from pathlib import Path
import re
import stat
from typing import Any

HERE=Path(__file__).resolve().parent
OPS=HERE.parent
FIRST_PARTY_SOURCE=OPS/"dynamic-operation-registry-v1"
INSTALLED=Path("/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled")
POLICY=Path("/etc/mad4b/native-source-publisher-policy.v2.json")
SHA=re.compile(r"^[0-9a-f]{64}$")


class ExtensionDenied(ValueError):
    pass


def require(condition: bool, reason: str) -> None:
    if not condition:
        raise ExtensionDenied(reason)


def sha(blob: bytes) -> str:
    return hashlib.sha256(blob).hexdigest()


def load_module(name: str, source: Path) -> Any:
    spec=importlib.util.spec_from_file_location(name,source)
    require(spec is not None and spec.loader is not None,
            "FIRST_PARTY_CATALOG_MODULE_NOT_FOUND")
    module=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def source_bytes(path: Path, root: Path, maximum: int = 2_000_000) -> bytes:
    """Observe pinned root-owned source without resolving symlink escapes."""
    require(path.is_relative_to(root),"UNTRUSTED_SOURCE_PATH")
    for component in (path, *path.parents):
        if component == root.parent:
            break
        require(not component.is_symlink(),"SYMLINK_SOURCE_DENIED")
    st=path.stat()
    require(stat.S_ISREG(st.st_mode) and st.st_uid == 0 and
            not (st.st_mode & 0o022) and 0 < st.st_size <= maximum,
            "UNTRUSTED_SOURCE_COMPONENT")
    value=path.read_bytes()
    require(len(value)==st.st_size,"SOURCE_COMPONENT_READ_RACE")
    return value


def manifest_source_components(manifest: dict, source_ops: Path,
                               source_catalog: Path) -> dict[str,dict]:
    """Collect every role; operation comes from the descriptor, never a static list."""
    require(isinstance(manifest,dict),"SOURCE_MANIFEST_INVALID")
    result={}
    specs=[]
    for role in ("handler","verifier","preclaim","finalizer","evidence"):
        if role in manifest:
            specs.append((role,manifest[role]))
    for i,dependency in enumerate(manifest.get("dependencies",[])):
        specs.append(("dependency:"+str(i),dependency))
    hooks=manifest.get("hooks",{})
    require(isinstance(hooks,dict) and len(hooks)<=16,
            "SOURCE_DYNAMIC_HOOKS_INVALID")
    for name,component in sorted(hooks.items()):
        specs.append(("hook:"+name,component))
    require(len(specs)<=40,"SOURCE_COMPONENT_LIST_UNBOUNDED")
    existing={}
    for role,spec in specs:
        require(isinstance(spec,dict) and
                isinstance(spec.get("path"),str) and
                isinstance(spec.get("sha256"),str) and
                SHA.fullmatch(spec["sha256"]) is not None,
                "SOURCE_COMPONENT_SPEC_INVALID:"+role)
        if role.startswith("hook:"):
            require(set(spec)=={"path","sha256","entrypoint"},
                    "SOURCE_HOOK_CONTRACT_INVALID")
        else:
            require(set(spec)=={"path","sha256"},
                    "SOURCE_COMPONENT_CONTRACT_INVALID")
        rel=spec["path"]
        parts=Path(rel)
        require(not parts.is_absolute() and rel==parts.as_posix() and
                ".." not in parts.parts and "." not in parts.parts and
                "\\" not in rel and "//" not in rel and
                rel not in existing,
                "SOURCE_COMPONENT_PATH_NOT_EXCLUSIVE")
        existing[rel]=role
        if role=="evidence":
            # Source catalog may be laid out differently from installed
            # provider evidence; builder declares its exact pinned bytes.
            source=source_catalog/"evidence-schema.json"
        else:
            source=source_ops/rel
            # Existing first-party CAS components are source-repo submodules
            # but installed under the Registry root. Never search arbitrary
            # paths: the exact declared SHA must still match the source file.
            if not source.is_file():
                source=source_ops/"dynamic-operation-registry-v1"/rel
        require(source.is_relative_to(source_ops),"SOURCE_COMPONENT_OUTSIDE_SOURCE")
        blob=source_bytes(source,source_ops)
        require(sha(blob)==spec["sha256"],
                "SOURCE_COMPONENT_SHA_DRIFT:"+role)
        require(parts.suffix==(".json" if role=="evidence" else ".py"),
                "SOURCE_COMPONENT_EXTENSION_INVALID")
        result[role]={"installed_relative":rel,
                      "sha256":spec["sha256"],"bytes":len(blob)}
    return result


def projection(index: dict, manifest: dict, component_rows: dict,
               installed: Path, *, worker_compatible: bool,
               association_anchor_ready: bool, policy_present: bool,
               word_press_ability_readback: bool = False) -> dict:
    """Never produce an executable index; only a pinned candidate digest."""
    require(isinstance(index,dict) and
            index.get("contract")=="mad4b.dynamic-operation-index.v1" and
            index.get("installed_by_first_party") is True and
            isinstance(index.get("providers"),list),
            "INSTALLED_FIRST_PARTY_INDEX_REQUIRED")
    op=manifest.get("operation_id")
    require(isinstance(op,str) and re.fullmatch(r"[a-z][a-z0-9._-]{2,90}",op),
            "PROVIDER_OPERATION_IDENTIFIER_INVALID")
    rel="dynamic-operation-registry-v1/providers/"+op+"/manifest.json"
    original=list(index["providers"])
    require(0<len(original)<128 and all(isinstance(x,dict) for x in original),
            "REGISTRY_INDEX_LIMIT_OR_SHAPE_INVALID")
    require(not any(x.get("manifest")==rel for x in original),
            "OPERATION_ALREADY_REGISTERED_RECONCILE_SEPARATELY")
    # The same operation ID must never appear under a second file name.
    for row in original:
        require(isinstance(row.get("manifest"),str) and
                row["manifest"].split("/")[-2] != op,
                "DUPLICATE_OPERATION_ID_IN_INDEX")
    raw=json.dumps(manifest,sort_keys=True,indent=2).encode()+b"\n"
    digest=sha(raw)
    index_next={**index,"providers":[*original,{"manifest":rel,"sha256":digest}]}
    require(index_next["providers"][:-1]==original and
            all(index_next.get(k)==v for k,v in index.items() if k!="providers"),
            "EXISTING_FIRST_PARTY_INDEX_CHANGED")
    clashes=[]
    for role, item in component_rows.items():
        dest=installed/item["installed_relative"]
        if dest.is_symlink():
            clashes.append({"role":role,"reason":"INSTALLED_SYMLINK_CONFLICT"})
        elif dest.exists():
            if not dest.is_file() or sha(dest.read_bytes())!=item["sha256"]:
                clashes.append({"role":role,"reason":"INSTALLED_COMPONENT_CAS_CONFLICT"})
    blockers=[]
    if "preclaim" not in manifest and "preclaim" not in manifest.get("hooks",{}):
        blockers.append("PROVIDER_ORIGINAL306_PRECLAIM_NOT_DECLARED")
    if "finalizer" not in manifest and "finalizer" not in manifest.get("hooks",{}):
        blockers.append("PROVIDER_ORIGINAL313_FINALIZER_NOT_DECLARED")
    if manifest.get("source_family") is not None and not association_anchor_ready:
        blockers.append("ORIGINAL306_SIGNED_ASSOCIATION_ANCHOR_NOT_ENROLLED")
    if not worker_compatible:
        blockers.append("ORIGINAL306_WORKER_SOURCE_NOT_INSTALLED")
    if not policy_present:
        blockers.append("ROOT_OWNED_SOURCE_PUBLISHER_POLICY_NOT_ENROLLED")
    if not word_press_ability_readback:
        blockers.append("WORDPRESS_NATIVE_SOURCE_WRITE_ABILITY_NOT_VERIFIED")
    if clashes:
        blockers.append("INSTALLED_COMPONENT_CAS_CONFLICT_REQUIRES_REVIEW")
    # Source review never substitutes for certified first-party approval.
    blockers.append("FIRST_PARTY_SIGNED_EXTENSION_APPROVAL_REQUIRED")
    return {"contract":"mad4b.original-dynamic-provider-extension-preflight.v1",
            "state":"HOLD__FIRST_PARTY_EXTENSION_REVIEW_REQUIRED",
            "operation_id":op,"provider":manifest.get("provider"),
            "approval_ability":manifest.get("approval_ability"),
            "current_manifest_count":len(original),
            "proposed_manifest_count":len(index_next["providers"]),
            "existing_index_preserved":True,
            "current_index_sha256":sha(json.dumps(index,sort_keys=True,separators=(",",":")).encode()),
            "candidate_index_sha256":sha(json.dumps(index_next,sort_keys=True,separators=(",",":")).encode()),
            "candidate_manifest_sha256":digest,
            "pinned_source_components":component_rows,
            "collisions":clashes,"blockers":sorted(set(blockers)),
            "original305_claim_verified":False,
            "original313_signed_receipt_issued":False,
            "first_party_registry_changed":False,
            "wordpress_changed":False,"grant_created":False,
            "private_key_read":False,"authorized_for_install":False}


def read_only_plan(installed: Path = INSTALLED, source_ops: Path = OPS) -> dict:
    source_registry=load_module("_mad4b_extension_trusted_source_registry",
                                source_ops/"dynamic-operation-registry-v1/registry.py")
    original_registry=load_module("_mad4b_extension_original_catalog_builder",
                                  source_ops/"native-source-publisher-v1/build_enrollment_candidate.py")
    # Validate the installed Index and *all* currently enrolled components,
    # without opening signing keys or modifying any path.
    active=source_registry.Registry(installed)
    raw=source_registry.protected_file(installed/"index.json",installed,maximum=65536)
    index=json.loads(raw)
    candidate=original_registry.build_all()
    require(0<len(candidate)<=128,"CANDIDATE_OPERATION_CATALOG_INVALID")
    worker=source_ops/"dynamic-operation-registry-v1/worker_adapter.py"
    installed_worker=installed/"worker_adapter.py"
    worker_match=(installed_worker.is_file() and not installed_worker.is_symlink()
                  and sha(installed_worker.read_bytes())==sha(source_bytes(worker,source_ops)))
    results=[]
    for op,manifest in sorted(candidate.items()):
        require(op==manifest.get("operation_id"),"SOURCE_OPERATION_BINDING_CHANGED")
        # Recompute from source descriptors; stale generated manifest is NOT
        # allowed to authorize an install or direct index edit.
        items=manifest_source_components(manifest,source_ops,
                    source_ops/"native-source-publisher-v1/catalog")
        result=projection(index,manifest,items,installed,
            worker_compatible=worker_match and
                hasattr(source_registry,"load_provider_hook"),
            association_anchor_ready=getattr(active,"operation_association_authority",None) is not None,
            policy_present=POLICY.is_file() and not POLICY.is_symlink(),
            word_press_ability_readback=False)
        results.append(result)
    return {"contract":"mad4b.original-dynamic-registry-source-review.v1",
            "state":"HOLD__SIGNED_PROVIDER_ENROLLMENT_REQUIRED",
            "candidate_count":len(results),"candidates":results,
            "installed_first_party_registry_verified":True,
            "original306_worker_pinned_source_matches_installed":worker_match,
            "source_signer_key_accessed":False,"registry_updated":False,
            "jobs_issued":False,"wordpress_mutated":False}


if __name__=="__main__":
    try:
        print(json.dumps(read_only_plan(),sort_keys=True,indent=2))
    except (ExtensionDenied,OSError,ValueError) as exc:
        print(json.dumps({"state":"HOLD__REGISTRY_EXTENSION_SOURCE_NOT_VERIFIED",
                          "error":str(exc),"registered":False,"mutation_performed":False}))
        raise SystemExit(2)
