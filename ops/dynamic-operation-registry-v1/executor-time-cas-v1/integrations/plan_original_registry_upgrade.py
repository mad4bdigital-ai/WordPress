#!/usr/bin/env python3
"""Read-only plan for a version-neutral Original dynamic Registry extension.

This is NOT an installer, issuer, worker patcher or signing-key reader.
An upgrade is allowed only through an independently authorized Original
release after the target WordPress finalizer, Original 306, and operation
specific grants are ready. This planner never mutates /opt or WordPress.
"""
from __future__ import annotations
import argparse
import hashlib
import json
from pathlib import Path
import re
import stat
import sys

ROOT=Path(__file__).resolve().parents[2]
REGISTRY_SOURCE=ROOT
CAS_MANIFEST=REGISTRY_SOURCE/"providers/company.resource.cas.apply/manifest.json"
CAS_INDEX_RELATIVE="providers/company.resource.cas.apply/manifest.json"
INSTALLED=Path(
 "/opt/mad4b-company-transport/native-313-independent/"
 "dynamic-operation-registry-v1/enrolled"
)
SHA=re.compile(r"^[a-f0-9]{64}$")


class Refused(ValueError):
 pass


def require(ok,why):
 if not ok:raise Refused(why)


def sha(raw:bytes)->str:
 return hashlib.sha256(raw).hexdigest()


def trusted_raw(path:Path,maximum:int=2_000_000)->bytes:
 info=path.lstat()
 require(stat.S_ISREG(info.st_mode) and info.st_uid==0
         and not (info.st_mode&0o022) and not path.is_symlink()
         and 0<info.st_size<=maximum,
         "FIRST_PARTY_PINNED_SOURCE_REQUIRED")
 return path.read_bytes()


def catalog_upgrade(index:dict,manifest:dict,source_root:Path)->dict:
 """Returns a potential next index and copy plan, never installs either."""
 require(isinstance(index,dict)
         and index.get("contract")=="mad4b.dynamic-operation-index.v1"
         and index.get("installed_by_first_party") is True
         and isinstance(index.get("providers"),list)
         and 1<=len(index["providers"])<128
         and isinstance(manifest,dict)
         and manifest.get("contract")=="mad4b.dynamic-operation-provider.v1"
         and manifest.get("operation_id")=="company.resource.cas.apply",
         "ORIGINAL_313_REGISTRY_OR_PROVIDER_NOT_INSTALLED_SOURCE")
 existing=index["providers"]
 require(not any(row.get("manifest")==CAS_INDEX_RELATIVE
                 for row in existing),
         "CAS_PROVIDER_ALREADY_ENROLLED_RECONCILE_INSTEAD")
 require(manifest.get("command",{}).get("bound_operation_id")==
             manifest.get("operation_id")
         and manifest.get("command",{}).get("mode")=="plan_sha256"
         and manifest.get("cas_scope",{}).get("commercial_pricing_allowed") is False
         and manifest.get("cas_scope",{}).get("allowed_meta_keys")==["rate_title"],
         "UNAPPROVED_COMMERCIAL_CAS_EXTENSION_DENIED")
 components={}
 for name in ("handler","verifier","preclaim","finalizer","evidence"):
  row=manifest.get(name)
  require(isinstance(row,dict)
          and set(row)=={"path","sha256"}
          and isinstance(row.get("path"),str)
          and isinstance(row.get("sha256"),str)
          and SHA.fullmatch(row["sha256"]) is not None,
          "FIRST_PARTY_COMPONENT_NOT_PINNED:"+name)
  relative=Path(row["path"])
  require(not relative.is_absolute() and ".." not in relative.parts
          and row["path"]==relative.as_posix()
          and (relative.suffix==(".json" if name=="evidence" else ".py"))
          and row["path"] not in components,
          "FIRST_PARTY_COMPONENT_PATH_INVALID")
  blob=trusted_raw(source_root/relative)
  require(sha(blob)==row["sha256"],
          "ORIGINAL_313_SOURCE_COMPONENT_CHANGED:"+name)
  components[row["path"]]=row["sha256"]
 for row in manifest.get("dependencies",[]):
  require(isinstance(row,dict)
          and set(row)=={"path","sha256"}
          and row["path"] not in components,
          "ORIGINAL_DEPENDENCY_UNTRUSTED")
  blob=trusted_raw(source_root/row["path"])
  require(sha(blob)==row["sha256"],"ORIGINAL_DEPENDENCY_DIGEST_CHANGED")
  components[row["path"]]=row["sha256"]
 manifest_raw=trusted_raw(CAS_MANIFEST,65536)
 require(json.loads(manifest_raw)==manifest,
         "ORIGINAL_MANIFEST_NOT_SOURCE_TRUSTED")
 # Immutable first-party source files, validated by the Registry again on
 # the target before the last index mutation. Duplicate paths are blocked.
 components[CAS_INDEX_RELATIVE]=sha(manifest_raw)
 upgraded={**index,
           "providers":[*existing,{
             "manifest":CAS_INDEX_RELATIVE,
             "sha256":sha(manifest_raw)
           }]}
 return {
  "contract":"mad4b.original-dynamic-registry-cas-upgrade-plan.v1",
  "state":"CANDIDATE_ONLY_GOVERNED_RELEASE_REQUIRED",
  "registered_operations_before":len(existing),
  "registered_operations_after":len(upgraded["providers"]),
  "preserved_existing_index":True,
  "private_key_read":False,
  "original_305_job_created":False,
  "original_313_certificate_issued":False,
  "original_worker_mutated":False,
  "root_registry_mutated":False,
  "site_content_mutated":False,
  "old_index_sha256":sha(json.dumps(
     index,sort_keys=True,separators=(",",":")).encode()),
  "new_index_sha256":sha(json.dumps(
     upgraded,sort_keys=True,separators=(",",":")).encode()),
  "files_to_source_pin":components,
  "candidate_index":upgraded,
 }


def plan(installed:Path=INSTALLED)->dict:
 registry_dir=installed
 sys.path.insert(0,str(registry_dir))
 import registry
 registry.Registry(registry_dir)
 index=json.loads(registry.protected_file(
     registry_dir/"index.json",registry_dir,maximum=30000))
 manifest=json.loads(trusted_raw(CAS_MANIFEST,65536))
 return catalog_upgrade(index,manifest,REGISTRY_SOURCE)


def main():
 p=argparse.ArgumentParser()
 p.add_argument("--read-only-plan",action="store_true",required=True)
 args=p.parse_args()
 report=plan()
 # No output of public signing key or original source paths is necessary.
 report.pop("candidate_index")
 print(json.dumps(report,indent=2,sort_keys=True))


if __name__=="__main__":
 main()
