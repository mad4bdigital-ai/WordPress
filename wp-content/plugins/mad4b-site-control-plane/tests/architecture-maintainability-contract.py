from __future__ import annotations
import argparse, fnmatch, hashlib, json, re, subprocess, sys
from pathlib import Path

root=Path(__file__).resolve().parents[1]
repo=root.parents[2]
policy=json.loads((root/"config/architecture-maintainability-policy.json").read_text(encoding="utf-8"))
if policy.get("contract")!="mad4b.architecture-maintainability-policy.v1":
    raise SystemExit("ARCHITECTURE_POLICY_CONTRACT_INVALID")
if policy.get("authorizing") is not False:
    raise SystemExit("ARCHITECTURE_POLICY_MUST_BE_NON_AUTHORIZING")

ap=argparse.ArgumentParser()
ap.add_argument("--base",default="")
ap.add_argument("--head",default="HEAD")
ap.add_argument("--pr-number",type=int,default=0)
ap.add_argument("--output",default="")
args=ap.parse_args()

includes=root/"includes"
php_files=sorted(includes.rglob("*.php"))
class_to_path={}
contents={}
class_re=re.compile(r"\b(?:final\s+)?class\s+(MAD4B_SCP_[A-Za-z0-9_]+)")
ref_re=re.compile(r"\b(MAD4B_SCP_[A-Za-z0-9_]+)\b")
for p in php_files:
    rel=p.relative_to(repo).as_posix()
    text=p.read_text(encoding="utf-8",errors="replace")
    contents[rel]=text
    for cls in class_re.findall(text):
        class_to_path[cls]=rel

edges=[]
for src,text in contents.items():
    src_classes=class_re.findall(text)
    if not src_classes:
        continue
    refs=sorted(set(ref_re.findall(text)))
    for src_cls in src_classes:
        for dst_cls in refs:
            if dst_cls==src_cls or dst_cls not in class_to_path:
                continue
            edges.append((src_cls,dst_cls,src,class_to_path[dst_cls]))
edges=sorted(set(edges))

dep=policy["dependency_policy"]
authority=set(dep["authority_truth_paths"])
for src_cls,dst_cls,src,dst in edges:
    if src not in authority:
        continue
    if any(dst.startswith(prefix) for prefix in dep["forbidden_target_path_prefixes"]):
        raise SystemExit(f"ARCHITECTURE_FORBIDDEN_DEPENDENCY:{src}->{dst}")
    if any(fragment in dst for fragment in dep["forbidden_target_name_fragments"]):
        raise SystemExit(f"ARCHITECTURE_FORBIDDEN_DEPENDENCY:{src}->{dst}")

size=policy["size_budgets"]
for rel,limit in size["legacy_facades"].items():
    p=repo/rel
    if not p.is_file():
        raise SystemExit("LEGACY_FACADE_MISSING:"+rel)
    lines=len(p.read_text(encoding="utf-8",errors="replace").splitlines())
    if lines>int(limit):
        raise SystemExit(f"LEGACY_FACADE_SIZE_BUDGET_EXCEEDED:{rel}:{lines}>{limit}")

changed=[]
additions=0
if args.base:
    changed=subprocess.check_output(["git","diff","--name-only",f"{args.base}...{args.head}"],cwd=repo,text=True).splitlines()
    numstat=subprocess.check_output(["git","diff","--numstat",f"{args.base}...{args.head}"],cwd=repo,text=True).splitlines()
    for line in numstat:
        parts=line.split("\t",2)
        if len(parts)>=2 and parts[0].isdigit():
            additions+=int(parts[0])
    added=subprocess.check_output(["git","diff","--name-status",f"{args.base}...{args.head}"],cwd=repo,text=True).splitlines()
    for row in added:
        parts=row.split("\t")
        if len(parts)>=2 and parts[0]=="A" and parts[1].startswith("wp-content/plugins/mad4b-site-control-plane/includes/") and parts[1].endswith(".php"):
            p=repo/parts[1]
            lines=len(p.read_text(encoding="utf-8",errors="replace").splitlines())
            if lines>int(size["new_php_domain_service_max_lines"]):
                raise SystemExit(f"NEW_DOMAIN_SERVICE_SIZE_BUDGET_EXCEEDED:{parts[1]}:{lines}")

slice_policy=policy["change_slice_policy"]
over_limit=len(changed)>int(slice_policy["max_changed_files_without_slice_manifest"]) or additions>int(slice_policy["max_additions_without_slice_manifest"])
grandfathered=args.pr_number in set(int(x) for x in slice_policy.get("grandfathered_pull_requests",[]))
if over_limit and not grandfathered:
    manifest_path=repo/slice_policy["manifest_path"]
    if not manifest_path.is_file():
        raise SystemExit("CHANGE_SLICE_MANIFEST_REQUIRED")
    manifest=json.loads(manifest_path.read_text(encoding="utf-8"))
    if manifest.get("contract")!="mad4b.change-slices.v1" or manifest.get("exact_head_sha")!=args.head:
        raise SystemExit("CHANGE_SLICE_MANIFEST_EXACT_HEAD_INVALID")
    slices=manifest.get("slices")
    if not isinstance(slices,list) or not slices:
        raise SystemExit("CHANGE_SLICE_MANIFEST_EMPTY")
    matches={p:[] for p in changed}
    for row in slices:
        if not isinstance(row,dict) or not row.get("semantic_unit") or not row.get("revert_boundary") or row.get("certification")!="exact_head":
            raise SystemExit("CHANGE_SLICE_SEMANTIC_CONTRACT_INVALID")
        pats=row.get("path_globs") or []
        if not isinstance(pats,list) or not pats:
            raise SystemExit("CHANGE_SLICE_PATHS_MISSING")
        for p in changed:
            if any(fnmatch.fnmatch(p,pat) for pat in pats):
                matches[p].append(row.get("id"))
        owned=[p for p in changed if row.get("id") in matches[p]]
        if len(owned)>int(slice_policy["max_files_per_slice"]):
            raise SystemExit(f"CHANGE_SLICE_TOO_LARGE:{row.get('id')}:{len(owned)}")
    bad=[p for p,v in matches.items() if len(v)!=1]
    if bad:
        raise SystemExit("CHANGE_SLICE_OWNERSHIP_INVALID:"+",".join(bad[:20]))

graph={
    "contract":dep["graph_output_contract"],
    "node_count":len(class_to_path),
    "edge_count":len(edges),
    "nodes":[{"class":c,"path":p} for c,p in sorted(class_to_path.items())],
    "edges":[{"from":a,"to":b,"from_path":sp,"to_path":dp} for a,b,sp,dp in edges],
    "authorizing":False,
}
raw=json.dumps(graph,sort_keys=True,separators=(",",":")).encode()
graph["graph_sha256"]=hashlib.sha256(raw).hexdigest()
if args.output:
    out=Path(args.output)
    out.parent.mkdir(parents=True,exist_ok=True)
    out.write_text(json.dumps(graph,indent=2,sort_keys=True)+"\n",encoding="utf-8")
print(f"mad4b.architecture-maintainability-policy.v1: PASS nodes={len(class_to_path)} edges={len(edges)} changed={len(changed)} additions={additions} grandfathered={str(grandfathered).lower()}")
