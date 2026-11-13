#!/usr/bin/env python3
"""Read-only tenant-plugin lineage snapshot. Does not migrate or relabel source."""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import re
import stat
import sys

EXTENSIONS = {".php", ".css", ".js", ".json"}
PLUGIN = re.compile(r"^[a-z][a-z0-9-]{2,90}$")


def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def inventory(base: Path, plugin: str) -> dict[str, dict]:
    if not isinstance(plugin, str) or PLUGIN.fullmatch(plugin) is None:
        raise ValueError("TENANT_PLUGIN_ID_INVALID")
    folder = base / "wp-content/plugins" / plugin
    if not folder.is_dir() or folder.is_symlink():
        raise ValueError("TENANT_PLUGIN_ROOT_UNAVAILABLE")
    files: dict[str, dict] = {}
    for candidate in folder.rglob("*"):
        if candidate.is_symlink():
            raise ValueError("TENANT_PLUGIN_SYMLINK_DENIED")
        if not candidate.is_file() or candidate.suffix.lower() not in EXTENSIONS:
            continue
        if any(part.startswith(".") for part in candidate.relative_to(folder).parts):
            continue
        st=candidate.stat()
        if not stat.S_ISREG(st.st_mode) or st.st_size > 4_000_000:
            raise ValueError("TENANT_PLUGIN_OBJECT_NOT_ALLOWED")
        rel = candidate.relative_to(folder).as_posix()
        files[rel] = {"sha256":sha(candidate), "bytes":st.st_size,
                      "mode":stat.S_IMODE(st.st_mode),
                      "uid":st.st_uid,"gid":st.st_gid}
    return files


def reconcile(source: Path, tenant: Path, plugin: str) -> dict:
    source_files = inventory(source, plugin)
    tenant_files = inventory(tenant, plugin)
    source_names = set(source_files)
    tenant_names = set(tenant_files)
    common = sorted(source_names & tenant_names)
    return {
        "contract":"mad4b.tenant-plugin-source-reconciliation.v1",
        "plugin":plugin,
        "source_file_count":len(source_files),
        "tenant_file_count":len(tenant_files),
        "tenant_only":[{"path":p,**tenant_files[p]}
                       for p in sorted(tenant_names-source_names)],
        "source_only":[{"path":p,**source_files[p]}
                       for p in sorted(source_names-tenant_names)],
        "changed":[{"path":p,"source":source_files[p],
                    "tenant":tenant_files[p]}
                   for p in common
                   if source_files[p]["sha256"] != tenant_files[p]["sha256"]],
        "matching":sum(source_files[p]["sha256"] == tenant_files[p]["sha256"]
                       for p in common),
        "safe_to_overwrite_tenant":False,
        "production_mutated":False,
    }


def main() -> int:
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source-root",type=Path,required=True)
    parser.add_argument("--tenant-root",type=Path,required=True)
    parser.add_argument("--plugin",required=True)
    args=parser.parse_args()
    result=reconcile(args.source_root,args.tenant_root,args.plugin)
    print(json.dumps(result,indent=2,ensure_ascii=False))
    return 3 if result["tenant_only"] or result["changed"] else 0


if __name__ == "__main__":
    raise SystemExit(main())
