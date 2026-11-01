#!/usr/bin/env python3
"""Read-only central company engine governance audit: NEVER dispatches or grants execution."""
import argparse
import hashlib
import json
import re
from pathlib import Path

CONTRACT = "mad4b.central-company-engine-registry.v1"
ID = re.compile(r"^company\.[a-z][a-z0-9_]{2,63}$")
SITE = re.compile(r"^[A-Za-z0-9._:-]{1,128}$")
ALLOWED_RISKS = {"low", "medium", "high", "exceptional"}
PROTECTED = {"company_identity", "grants"}
DEFAULT_ROOT = "/opt/mad4b-company-transport"
DEFAULT_CP = ("/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy"
              "/wp-content/plugins/mad4b-site-control-plane")

class InvalidContract(ValueError):
    pass

def load_json(path):
    p = Path(path)
    if p.is_symlink() or not p.is_file():
        raise InvalidContract("missing_or_symlinked_catalog")
    return json.loads(p.read_text(encoding="utf-8"))

def digest(data):
    return hashlib.sha256(json.dumps(data, sort_keys=True, separators=(",", ":"), ensure_ascii=False).encode()).hexdigest()

def checked_catalog(data):
    if not isinstance(data, dict) or data.get("contract") != CONTRACT or data.get("version") != 1:
        raise InvalidContract("unknown_engine_contract")
    if data.get("domain_owner") != "central_company_management":
        raise InvalidContract("wrong_domain")
    if data.get("core_authority") != "MAD4B_SCP_Operation_Registry" or data.get("core_dispatch") != "original_governed_operation_pipeline":
        raise InvalidContract("parallel_authority_or_dispatcher")
    if data.get("default_write_policy") != "deny_without_native_operation_and_fresh_site_bound_authority":
        raise InvalidContract("write_policy_not_fail_closed")
    if data.get("site_data_replication") != "never_implicit":
        raise InvalidContract("implicit_site_replication")
    es = data.get("engines")
    if not isinstance(es, list) or not 1 <= len(es) <= 100:
        raise InvalidContract("invalid_engines")
    ids = set()
    operations = set()
    for e in es:
        if not isinstance(e, dict) or not ID.fullmatch(str(e.get("id", ""))) or e["id"] in ids:
            raise InvalidContract("invalid_or_duplicate_engine")
        ids.add(e["id"])
        if e.get("owner") != "central_company_management" or e.get("scope") != "company_and_site":
            raise InvalidContract("engine_owner_scope_invalid")
        if e.get("source") not in ("wordpress", "vps") or e.get("risk") not in ALLOWED_RISKS:
            raise InvalidContract("invalid_source_or_risk")
        if not isinstance(e.get("assets"), list) or not e["assets"]:
            raise InvalidContract("missing_asset_contract")
        if not isinstance(e.get("protected"), list) or not e["protected"]:
            raise InvalidContract("missing_protected_assets")
        if not isinstance(e.get("modules"), list) or not e["modules"]:
            raise InvalidContract("missing_modules")
        for m in e["modules"]:
            if not isinstance(m, str) or m.startswith("/") or ".." in Path(m).parts or "\\" in m or not re.fullmatch(r"[a-zA-Z0-9_./-]+", m):
                raise InvalidContract("unsafe_module_path")
        op = e.get("native_operation")
        if op is not None:
            if not isinstance(op, str) or not re.fullmatch(r"[a-z][a-z0-9.-]{2,95}", op) or op in operations:
                raise InvalidContract("duplicate_or_invalid_native_operation")
            operations.add(op)
        if not isinstance(e.get("depends_on"), list) or len(set(e["depends_on"])) != len(e["depends_on"]):
            raise InvalidContract("bad_dependencies")
    visit, stack = set(), set()
    index = {e["id"]: e for e in es}
    def dfs(k):
        if k in stack:
            raise InvalidContract("dependency_cycle")
        if k in visit:
            return
        stack.add(k)
        for dep in index[k]["depends_on"]:
            if dep not in index or dep == k:
                raise InvalidContract("unknown_or_self_dependency")
            dfs(dep)
        stack.remove(k)
        visit.add(k)
    for k in index:
        dfs(k)
    return index

def audit(registry, operation_registry, cp_root, vps_root):
    engines = checked_catalog(registry)
    if (operation_registry.get("contract") != "mad4b.operation-registry.v1"
            or operation_registry.get("default_mutation_policy") != "deny"
            or operation_registry.get("generic_shell") is not False
            or operation_registry.get("arbitrary_operation_ids") is not False):
        raise InvalidContract("unsafe_original_operation_registry")
    ops = operation_registry.get("operations")
    if not isinstance(ops, list):
        raise InvalidContract("original_operations_unavailable")
    native = {}
    for op in ops:
        oid = op.get("id") if isinstance(op, dict) else None
        if not isinstance(oid, str) or oid in native:
            raise InvalidContract("invalid_or_duplicate_core_operation")
        native[oid] = op
    out = []
    for e in engines.values():
        missing = []
        present = []
        for m in e["modules"]:
            p = ((Path(cp_root)/"includes"/m) if e["source"] == "wordpress" else (Path(vps_root)/m))
            if p.is_symlink() or not p.is_file():
                missing.append(m)
            else:
                present.append(m)
        op = e.get("native_operation")
        bound = op is not None and op in native
        pair = native.get(op, {}) if bound else {}
        pair_ok = bool(bound and isinstance(pair.get("planner"), str) and isinstance(pair.get("executor"), str)
                       and pair.get("pipeline_profile") == "governed_mutation_v1"
                       and not pair.get("generic_mutation_dispatch", False))
        blockers = []
        if missing:
            blockers.append("engine_modules_missing")
        if not op:
            blockers.append("native_operation_unregistered")
        elif not pair_ok:
            blockers.append("native_operation_contract_unverified")
        # A registered operation proves catalog presence, not native executor readiness.
        status = "SOURCE_INCOMPLETE" if missing else ("CORE_OPERATION_MAPPED_UNCERTIFIED" if pair_ok else "PLANNING_ONLY")
        out.append({
            "engine_id":e["id"], "risk":e["risk"], "source":e["source"],
            "modules_present":len(present), "modules_missing":missing,
            "dependencies":e["depends_on"], "native_operation":op,
            "core_registry_mapping":pair_ok, "status":status, "blockers":blockers,
            "mutation_authorized":False, "executor_certified":False
        })
    report = {
        "contract":"mad4b.central-company-engine-governance-audit.v1",
        "registry_sha256":digest(registry),
        "core_registry_sha256":digest(operation_registry),
        "engine_count":len(out), "core_operations_observed":len(native),
        "mapped_operations":sum(row["core_registry_mapping"] for row in out),
        "source_complete_engines":sum(not row["modules_missing"] for row in out),
        "engines":out,
        "policy":{"company_site_scope_required":True,
                  "native_core_authorization_only":True,
                  "auto_write_on_discovery":False,
                  "source_canonical_is_not_site_data":True,
                  "site_bound_receipts_required":True,
                  "production_capability_disabled":False,
                  "production_higher_risk_lane":True,
                  "central_governance_never_dispatches":True},
        "status":"INVENTORY_COMPLETE_GOVERNED_WRITES_PENDING",
        "mutation_authorized":False, "write_performed":False
    }
    report["report_sha256"] = digest(report)
    return report

def assess_engine(report, engine_id, binding):
    """Non-authorizing advice; caller's claims never grant access."""
    items = {e["engine_id"]:e for e in report["engines"]}
    if engine_id not in items:
        raise InvalidContract("engine_not_registered")
    if not isinstance(binding, dict) or set(binding) != {"company_id","site_uuid","origin","environment"}:
        raise InvalidContract("exact_site_binding_required")
    if any(not isinstance(v,str) or not SITE.fullmatch(v) for k,v in binding.items() if k in ("company_id","site_uuid")):
        raise InvalidContract("invalid_company_or_site")
    if not (isinstance(binding["origin"],str) and re.fullmatch(r"https://[A-Za-z0-9.-]{1,190}(?::[0-9]{2,5})?", binding["origin"])):
        raise InvalidContract("invalid_origin")
    if binding["environment"] not in ("staging","production","development","local"):
        raise InvalidContract("invalid_environment")
    eng = items[engine_id]
    blockers = list(eng["blockers"])
    blockers.extend(["native_site_bound_identity_not_attested","fresh_authority_not_obtained",
                     "independent_restore_and_readback_not_certified"])
    return {"contract":"mad4b.central-company-engine-assessment.v1",
            "engine_id":engine_id, "company_id":binding["company_id"],
            "site_uuid":binding["site_uuid"], "origin":binding["origin"],
            "environment":binding["environment"], "core_registry_mapping":eng["core_registry_mapping"],
            "blockers":blockers, "status":"NEEDS_NATIVE_EVIDENCE",
            "authorizing":False,"execute":False,"mutation_performed":False}

def main():
    p = argparse.ArgumentParser()
    p.add_argument("--registry",default=str(Path(__file__).with_name("engine-registry.json")))
    p.add_argument("--core-registry",default=str(Path(DEFAULT_CP)/"config/operation-registry.json"))
    p.add_argument("--control-plane",default=DEFAULT_CP)
    p.add_argument("--vps-root",default=DEFAULT_ROOT)
    p.add_argument("--output")
    args = p.parse_args()
    report = audit(load_json(args.registry), load_json(args.core_registry),args.control_plane,args.vps_root)
    raw = json.dumps(report, indent=2, ensure_ascii=False, sort_keys=True)+"\n"
    if args.output:
        dst = Path(args.output)
        if dst.is_symlink() or not dst.parent.is_dir():
            raise InvalidContract("unsafe_output")
        dst.write_text(raw, encoding="utf-8")
    print(raw)

if __name__ == "__main__":
    main()
