#!/usr/bin/env python3
"""Read-only live-origin byte parity and MCP 0.7.1 certification checks."""
import argparse
import hashlib
import json
from pathlib import Path

P=Path(__file__).resolve().parent
ROOTS={
 "toursdev":Path("/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content/plugins/mad4b-site-control-plane"),
 "wayfa":Path("/opt/wayfa-rebuild-20261102/site-copy/wp-content/plugins/mad4b-site-control-plane")
}

def verify(site="package"):
 lock=json.loads((P/"source-lock.json").read_text())
 if lock.get("contract")!="mad4b.wayfa-live-first-parity-candidate.v1":
  raise RuntimeError("SOURCE_LOCK_INVALID")
 observed={x.relative_to(P).as_posix() for x in (P/"runtime").rglob("*") if x.is_file()}
 if observed!=set(lock["files"]) or len(observed)!=7:raise RuntimeError("SOURCE_FILE_SET_DRIFT")
 root=P/"runtime" if site=="package" else ROOTS[site]
 if root.is_symlink() or not root.is_dir():raise RuntimeError("SITE_ROOT_NOT_ENROLLED")
 for rel,digest in lock["files"].items():
  f=P/rel if site=="package" else root/rel.removeprefix("runtime/")
  if f.is_symlink() or not f.is_file() or hashlib.sha256(f.read_bytes()).hexdigest()!=digest:
   raise RuntimeError("SOURCE_PARITY_DRIFT:"+rel)
 base=P/"runtime/config"
 policy=json.loads((base/"runtime-release-policy.json").read_text())
 protocol=json.loads((base/"mcp-protocol-profiles.json").read_text())
 providers=json.loads((base/"certified-provider-profiles.json").read_text())
 assert policy["target_adapter_version"]=="0.7.1"
 assert protocol["certified_adapter"]["version"]==policy["target_adapter_version"]
 assert protocol["certified_adapter"]["exact_package_governed"] is True
 provider=providers["providers"]["mcp_adapter"]["0.7.1"]
 assert provider["version"]=="0.7.1" and len(provider["archive_sha256"])==64
 compatibility=provider["transport_compatibility"]
 expected=list(compatibility["modern_per_request_revisions"])+list(reversed(compatibility["legacy_session_revisions"]))
 assert protocol["supported_protocol_versions"]==expected
 ui=(P/"runtime/includes/class-mad4b-scp-deferred-admin-reports.php").read_text()
 js=(P/"runtime/assets/deferred-admin-reports.js").read_text()
 assert "class MAD4B_SCP_Host_Authority_Lifecycle" in ui
 assert "READ_ONLY__INDEPENDENT_AUTHORITY_NOT_CERTIFIED" in ui
 assert "host_authority" in js and "host_authority" in ui
 return {"site":site,"files_checked":len(observed),"sha256_match":True,"mcp_adapter":"0.7.1","read_only_ui":True,"signed_release":False}

if __name__=="__main__":
 parser=argparse.ArgumentParser()
 parser.add_argument("--site",choices=["package","toursdev","wayfa"],default="package")
 args=parser.parse_args()
 try:print(json.dumps(verify(args.site),sort_keys=True))
 except Exception as e:print(json.dumps({"state":"FAIL_CLOSED","reason":str(e)[:200]}));raise SystemExit(2)
