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
