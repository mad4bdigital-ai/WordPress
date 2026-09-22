#!/usr/bin/env python3
import hashlib, json, os, re, sys, zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PLUGIN_ROOT = ROOT / "wp-content/plugins"
OUT = Path(sys.argv[1]) if len(sys.argv) > 1 else ROOT / "functional-gap-contract-evidence.json"

POLICY_PATH = PLUGIN_ROOT / "mad4b-site-control-plane/config/functional-gap-policy.json"
POLICY_RAW = POLICY_PATH.read_bytes()
POLICY = json.loads(POLICY_RAW.decode("utf-8"))
if POLICY.get("contract") != "mad4b.functional-gap-policy.v1":
    raise SystemExit("functional-gap policy contract mismatch")

CATALOG_PATH = PLUGIN_ROOT / "mad4b-site-control-plane/config/adapter-support-catalog.json"
ARTIFACT_MAP_PATH = PLUGIN_ROOT / "mad4b-site-control-plane/config/repository-plugin-artifacts.json"
CATALOG = json.loads(CATALOG_PATH.read_text("utf-8"))
ARTIFACT_MAP = json.loads(ARTIFACT_MAP_PATH.read_text("utf-8"))

def normalize_plugin_file(value):
    return str(value or "").replace("\\", "/").lstrip("/").lower()

def probe_regex_is_bounded(value):
    value=str(value or "").strip()
    if not value or len(value)>512:
        return False
    for token in (".*", ".+", ".{", "(?=", "(?!", "(?<=", "(?<!", "||", "(|", "|)"):
        if token in value:
            return False
    if re.search(r"\\[1-9]", value):
        return False
    literal=re.sub(r"\\.", "", value)
    return re.search(r"[A-Za-z0-9_]{3,}", literal) is not None

def validate_policy():
    blockers=[]
    if CATALOG.get("contract") != "mad4b.adapter-support-catalog.v1":
        blockers.append("functional_gap_adapter_catalog_invalid")
    if ARTIFACT_MAP.get("contract") != "mad4b.repository-plugin-artifacts.v1":
        blockers.append("functional_gap_repository_artifact_catalog_invalid")
    if POLICY.get("default_mutation") != "deny":
        blockers.append("functional_gap_policy_mutation_default_not_deny")
    if POLICY.get("promotion_authorized") is not False:
        blockers.append("functional_gap_policy_promotion_not_false")

    catalog_by_family={
        str(row.get("id","")):row
        for row in CATALOG.get("families",[])
        if isinstance(row,dict) and row.get("id")
    }
    artifact_families=ARTIFACT_MAP.get("families",{}) if isinstance(ARTIFACT_MAP.get("families",{}),dict) else {}
    supported={"bounded_read_routes","redacted_status","exact_tree_review","runtime_only","premium_semantic","composite_behavioral"}
    prefix_owners={}

    probes=POLICY.get("probes") if isinstance(POLICY.get("probes"),dict) else {}
    for regex_key in ("rest_route_regex","ajax_hook_regex","cron_hook_regex","secret_key_regex"):
        value=str(probes.get(regex_key,"")).strip()
        try:
            re.compile(value, re.I)
        except re.error:
            blockers.append(f"functional_gap_policy_probe_regex_invalid_{regex_key}")
            continue
        if not probe_regex_is_bounded(value):
            blockers.append(f"functional_gap_policy_probe_regex_overbroad_{regex_key}")
    if not isinstance(probes.get("option_keys"),list) or not probes.get("option_keys"):
        blockers.append("functional_gap_policy_option_probe_set_missing")
    if not isinstance(probes.get("constants"),list) or not probes.get("constants"):
        blockers.append("functional_gap_policy_constant_probe_set_missing")

    for family,row in sorted((POLICY.get("families") or {}).items()):
        if not family or not isinstance(row,dict):
            blockers.append("functional_gap_policy_family_identity_invalid")
            continue
        mode=str(row.get("evaluation_mode",""))
        if mode not in supported:
            blockers.append(f"functional_gap_policy_mode_invalid_{family}")

        matches=[str(x) for x in (row.get("match") or []) if str(x)]
        versioned=[str(x) for x in (row.get("versioned_match") or []) if str(x)]
        if not matches and not versioned:
            blockers.append(f"functional_gap_policy_match_missing_{family}")

        catalog=catalog_by_family.get(family)
        if not isinstance(catalog,dict):
            blockers.append(f"functional_gap_policy_family_missing_from_adapter_catalog_{family}")
            catalog={}

        catalog_matches={normalize_plugin_file(x) for x in (catalog.get("match") or [])}
        catalog_versioned={normalize_plugin_file(x).strip("/") for x in (catalog.get("versioned_match") or [])}
        for match in matches:
            normalized=normalize_plugin_file(match)
            if normalized not in catalog_matches:
                blockers.append(f"functional_gap_policy_match_escapes_adapter_catalog_{family}")
        for base in versioned:
            normalized=normalize_plugin_file(base).strip("/")
            if normalized not in catalog_versioned:
                blockers.append(f"functional_gap_policy_versioned_match_escapes_adapter_catalog_{family}")

        identity_prefixes=[]
        for match in matches:
            normalized=normalize_plugin_file(match)
            if not normalized:
                blockers.append(f"functional_gap_policy_match_invalid_{family}")
                continue
            identity_prefixes.append(normalized)
        for base in versioned:
            normalized=normalize_plugin_file(base).strip("/")
            if not normalized or re.search(r"[^a-z0-9._-]", normalized):
                blockers.append(f"functional_gap_policy_versioned_match_invalid_{family}")
                continue
            identity_prefixes.append(normalized + "-v")

        for prefix in identity_prefixes:
            for known_prefix,known_family in prefix_owners.items():
                if known_family == family:
                    continue
                if prefix.startswith(known_prefix) or known_prefix.startswith(prefix):
                    blockers.append(f"functional_gap_policy_match_overlap_{known_family}_{family}")
            prefix_owners[prefix]=family

        repo_backed=row.get("repository_evidence") is True
        artifacts=[str(x) for x in (row.get("repository_artifacts") or []) if str(x)]
        if repo_backed and not artifacts:
            blockers.append(f"functional_gap_policy_repository_artifacts_missing_{family}")
        if not repo_backed and artifacts:
            blockers.append(f"functional_gap_policy_runtime_only_artifacts_present_{family}")

        if repo_backed and catalog:
            authority_family=str(catalog.get("adapter_id") or family)
            canonical_row=artifact_families.get(authority_family,{})
            canonical=set(canonical_row.get("artifacts",[]) if isinstance(canonical_row,dict) else [])
            if not canonical:
                blockers.append(f"functional_gap_policy_repository_authority_family_missing_{family}")
            for artifact in artifacts:
                if artifact not in canonical:
                    blockers.append(f"functional_gap_policy_artifact_escapes_canonical_map_{family}")

        if mode == "bounded_read_routes":
            routes=row.get("required_get_routes")
            if not isinstance(routes,list) or not routes:
                blockers.append(f"functional_gap_policy_required_routes_missing_{family}")
                routes=[]
            if not isinstance(row.get("safe_now"),list) or not row.get("safe_now") or not isinstance(row.get("blocked"),list) or not row.get("blocked"):
                blockers.append(f"functional_gap_policy_read_boundary_missing_{family}")
            if row.get("require_non_public_permissions") is not True:
                blockers.append(f"functional_gap_policy_read_permission_boundary_missing_{family}")
            callback_map=row.get("required_get_permission_callbacks") if isinstance(row.get("required_get_permission_callbacks"),dict) else {}
            for route in routes:
                callbacks=callback_map.get(route)
                if not isinstance(callbacks,list) or not callbacks:
                    blockers.append(f"functional_gap_policy_read_permission_callbacks_missing_{family}")
                    continue
                for callback in callbacks:
                    value=str(callback).strip()
                    if value.lower() in {"__return_true","closure"} or re.fullmatch(r"[A-Za-z_\\\\][A-Za-z0-9_\\\\]*(?:::[A-Za-z_][A-Za-z0-9_]*)?", value) is None:
                        blockers.append(f"functional_gap_policy_read_permission_callback_identity_invalid_{family}")

        if mode in {"premium_semantic","composite_behavioral"}:
            if not repo_backed:
                blockers.append(f"functional_gap_policy_identity_evidence_required_{family}")
            if not artifacts:
                blockers.append(f"functional_gap_policy_identity_artifacts_required_{family}")

    blockers=sorted(set(blockers))
    if blockers:
        raise SystemExit("functional-gap policy validation failed: " + " | ".join(blockers))

validate_policy()

FAMILIES = {
    family: list(row.get("repository_artifacts", []))
    for family, row in POLICY.get("families", {}).items()
    if row.get("repository_evidence") is True
}
if not FAMILIES or any(not archives for archives in FAMILIES.values()):
    raise SystemExit("functional-gap policy repository evidence set is incomplete")

PATTERNS = {
    "rest_route": re.compile(r"register_rest_route\s*\(([^;]{0,1000})", re.I | re.S),
    "ajax_action": re.compile(r"wp_ajax_(?:nopriv_)?([A-Za-z0-9_\-]+)"),
    "option_read": re.compile(r"\b(?:get_option|get_site_option)\s*\(\s*['\"]([^'\"]+)['\"]"),
    "option_write": re.compile(r"\b(?:update_option|add_option|delete_option|update_site_option|delete_site_option)\s*\(\s*['\"]([^'\"]+)['\"]"),
    "post_meta_read": re.compile(r"\bget_post_meta\s*\([^,]+,\s*['\"]([^'\"]+)['\"]"),
    "post_meta_write": re.compile(r"\b(?:update_post_meta|add_post_meta|delete_post_meta)\s*\([^,]+,\s*['\"]([^'\"]+)['\"]"),
    "capability": re.compile(r"\bcurrent_user_can\s*\(\s*['\"]([^'\"]+)['\"]"),
    "remote_call": re.compile(r"\b(?:wp_remote_get|wp_remote_post|wp_remote_request)\s*\("),
    "filesystem_write": re.compile(r"\b(?:file_put_contents|unlink|rename|copy|mkdir|wp_mkdir_p)\s*\("),
    "db_write": re.compile(r"\$wpdb->(?:insert|update|delete|replace|query)\s*\("),
}

SECRET_RE = re.compile(str(POLICY.get("probes", {}).get("secret_key_regex", r"(?:secret|token|password|passwd|api[_-]?key|license[_-]?key|access[_-]?key|client[_-]?secret)")), re.I)

def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()

def text_files(zf):
    for name in zf.namelist():
        if name.endswith("/") or "__MACOSX/" in name:
            continue
        if not re.search(r"\.(?:php|json|js|txt|md)$", name, re.I):
            continue
        try:
            raw = zf.read(name)
        except Exception:
            continue
        yield name, raw.decode("utf-8", errors="replace")

def canonical_zip_tree(zf):
    names=[n for n in zf.namelist() if not n.endswith("/") and "__MACOSX/" not in n]
    roots={n.split("/",1)[0] for n in names if "/" in n}
    common_root=(next(iter(roots)) + "/") if len(roots)==1 else ""
    rows=[]
    total=0
    for name in sorted(names):
        raw=zf.read(name)
        rel=name[len(common_root):] if common_root and name.startswith(common_root) else name
        digest=sha256(raw)
        size=len(raw)
        total+=size
        rows.append(f"{rel}\0{size}\0{digest}")
    material="\n".join(rows).encode("utf-8")
    return {
        "root_prefix": common_root,
        "file_count": len(rows),
        "total_bytes": total,
        "tree_sha256": sha256(material),
    }

def plugin_headers(zf):
    out=[]
    for name,text in text_files(zf):
        if not name.lower().endswith(".php"):
            continue
        head=text[:65536]
        pm=re.search(r"^\s*\*?\s*Plugin Name:\s*([^\r\n]+)",head,re.I|re.M)
        vm=re.search(r"^\s*\*?\s*Version:\s*([^\r\n]+)",head,re.I|re.M)
        if pm:
            out.append({"file":name,"plugin_name":pm.group(1).strip(),"version":vm.group(1).strip() if vm else ""})
    return out

def inspect(path: Path):
    raw=path.read_bytes()
    found={k:set() for k in PATTERNS if k not in ("remote_call","filesystem_write","db_write")}
    counts={"remote_call":0,"filesystem_write":0,"db_write":0}
    php_files=0
    with zipfile.ZipFile(path) as zf:
        headers=plugin_headers(zf)
        tree=canonical_zip_tree(zf)
        for name,text in text_files(zf):
            if not name.lower().endswith(".php"):
                continue
            php_files+=1
            for key,pat in PATTERNS.items():
                if key in counts:
                    counts[key]+=len(pat.findall(text))
                else:
                    for match in pat.findall(text):
                        if isinstance(match,tuple): match=" ".join(match)
                        found[key].add(str(match).strip()[:500])
    reads=sorted(found["option_read"])
    writes=sorted(found["option_write"])
    secret_like=sorted(x for x in set(reads+writes) if SECRET_RE.search(x))
    return {
        "archive":path.name,
        "archive_sha256":sha256(raw),
        "archive_bytes":len(raw),
        "plugin_headers":headers,
        "package_tree":tree,
        "php_file_count":php_files,
        "surfaces":{
            "rest_route_evidence":sorted(found["rest_route"])[:200],
            "ajax_actions":sorted(found["ajax_action"])[:200],
            "option_read_keys":reads[:400],
            "option_write_keys":writes[:400],
            "post_meta_read_keys":sorted(found["post_meta_read"])[:300],
            "post_meta_write_keys":sorted(found["post_meta_write"])[:300],
            "capabilities":sorted(found["capability"])[:200],
            "remote_call_count":counts["remote_call"],
            "filesystem_write_count":counts["filesystem_write"],
            "db_write_count":counts["db_write"],
            "secret_like_option_keys":secret_like[:200],
        },
    }

report={
    "contract":"mad4b.functional-gap-contract-evidence.v1",
    "source_commit_sha":os.environ.get("SOURCE_SHA",""),
    "policy_contract":POLICY["contract"],
    "policy_sha256":sha256(POLICY_RAW),
    "families":{},
}
for family,archives in FAMILIES.items():
    rows=[]
    for archive in archives:
        path=PLUGIN_ROOT/archive
        if not path.exists():
            raise SystemExit(f"missing repository artifact: {archive}")
        rows.append(inspect(path))
    policy_row=POLICY["families"][family]
    report["families"][family]={
        "artifacts":rows,
        "evaluation_mode":policy_row.get("evaluation_mode",""),
        "evidence_only":True,
        "promotion_authorized":False,
    }

OUT.parent.mkdir(parents=True,exist_ok=True)
OUT.write_text(json.dumps(report,indent=2,sort_keys=True)+"\n",encoding="utf-8")
print(f"mad4b.functional-gap-contract-evidence.v1: PASS families={len(report['families'])}")
print(f"evidence={OUT}")
