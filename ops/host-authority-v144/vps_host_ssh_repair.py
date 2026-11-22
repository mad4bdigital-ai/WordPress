#!/usr/bin/env python3
"""MAD4B VPS -> enrolled hosting: pinned SSH remote-repair transport preflight.

Reuses Original Broker/Registry SSH. Does not invent authorizations:
transport can inspect a host but CANNOT issue Original305/312/313 Owner
signatures or perform a write without a separately verified central grant.
"""
from __future__ import annotations
import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import stat
import subprocess
import sys
import time

ROOT=Path("/opt/mad4b-company-transport")
ADAPTER=ROOT/"broker-v1/registry_ssh_adapter.py"
INVENTORY=ROOT/"broker-v1/external-site-ssh-inventory.v1.json"
ADAPTER_SHA256="a1667c50677da35266306b17b032e2a6a546ae94d262d5190e2d40d672500764"
INVENTORY_SHA256="8ecbdc50b284f8eec77140aaeb09de80938f8aea2f195d39d74bec318e538a22"
KEY_ROOT=Path("/opt/hostinger-shell-mcp/external-targets")
ROOT_REPORT=ROOT/"host-authority-v144-ssh-repair-readonly"
OUTPUT=ROOT_REPORT/"latest.json"
MARKER="MAD4B_VPS_HOST_SSH_READ_ONLY_CANARY"
HOSTING2={
  "company":"all-royal-egypt","environment":"staging",
  "host":"92.113.28.241","port":65002,"user":"u948953904",
  "public_sha256":"2f71d5c6d2dd65da8ef79b9e9dfa846cf39e08cdba4754876a315109c2ee6877",
  "known_sha256":"abdba0a6d111aa3b447ed7a777ce2e6d5b8b006d3f69de23a1bbb1266c8e6031",
}
CONTRACT="mad4b.host-v144.vps-registered-host-ssh-repair-status.v1"
TARGETS=("hosting-1","hosting-2","hosting-3")
PROBLEMS={
  "ssh_authentication":"ORIGINAL_SSH_IDENTITY_REENROLLMENT_REQUIRED",
  "ssh_hostkey_drift":"HARD_HOLD_ORIGINAL_HOST_KEY_REATTESTATION_REQUIRED",
  "wp_runtime":"NATIVE_ORIGINAL_BROKER_EXACT_REPAIR_GRANT_REQUIRED",
  "source_release":"NATIVE_ORIGINAL_BROKER_EXACT_INSTALL_GRANT_AND_ROLLBACK_REQUIRED",
  "original313_predecessor":"INDEPENDENT_SIGNED_PREDECESSOR_LEDGER_REQUIRED",
  "original305_owner":"FIRSTPARTY_OWNER_V3_SIGNER_OR_NEW_AUTHORITY_PROTOCOL_REQUIRED",
  "original312_issuer":"INDEPENDENT_ORIGINAL312_SIGNED_ENROLLMENT_REQUIRED",
  "original318_readback":"SIGNED_ORIGINAL318_READBACK_REPAIR_REQUIRED",
}

class RepairDenied(ValueError): pass
def need(ok,code):
    if not ok: raise RepairDenied(code)

def pin(path, sha=None, *, private=True):
    st=path.lstat()
    need(stat.S_ISREG(st.st_mode) and not path.is_symlink()
         and st.st_uid==0 and st.st_gid==0
         and st.st_size<=262144,
         "SSH_ROOT_PINNED_REFERENCE_NOT_TRUSTED")
    need((st.st_mode & (0o077 if private else 0o022))==0,
         "SSH_CREDENTIAL_FILE_PERMISSIONS_INVALID")
    data=path.read_bytes()
    if sha is not None:
        need(hashlib.sha256(data).hexdigest()==sha,
             "SSH_PINNED_REFERENCE_HASH_DRIFT")
    return data

def original_registry():
    pin(ADAPTER,ADAPTER_SHA256)
    spec=importlib.util.spec_from_file_location("_mad4b_pinned_ssh_original",ADAPTER)
    need(spec is not None and spec.loader is not None, "ORIGINAL_BROKER_ADAPTER_UNAVAILABLE")
    mod=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    need(set(mod.HOSTS)=={"hosting-1","hosting-3"}
         and mod.ROOT==KEY_ROOT,
         "ORIGINAL_BROKER_SSH_SCOPE_CHANGED")
    return mod

def host2_binding():
    raw=pin(INVENTORY,INVENTORY_SHA256)
    obj=json.loads(raw)
    need(obj.get("contract")=="mad4b.external-company-ssh-inventory.v1"
         and set(obj.get("targets",{}))=={"hosting-2","hosting-3"},
         "ORIGINAL_HOST_BINDINGS_CHANGED")
    cfg=obj["targets"]["hosting-2"]
    need((cfg.get("company"),cfg.get("environment"),cfg.get("hostname"),
          cfg.get("port"),cfg.get("username"))==(
          HOSTING2["company"],HOSTING2["environment"],
          HOSTING2["host"],HOSTING2["port"],HOSTING2["user"]),
          "HOST2_SCOPE_OR_ENDPOINT_DRIFT")
    root=KEY_ROOT/"hosting-2"
    pin(root/"id_ed25519")
    pin(root/"known_hosts_verified",HOSTING2["known_sha256"])
    pin(root/"id_ed25519.pub",HOSTING2["public_sha256"],private=False)
    return root

def host2_fixed_probe(*,network=False):
    root=host2_binding()
    row={
       "target_id":"hosting-2","company":HOSTING2["company"],
       "environment":HOSTING2["environment"],
       "identity_pinned":True,"ssh_authenticated":False,
       "fixed_diagnostic_shell_verified":False,
       "source":"registered_ssh_inventory_and_pinned_host_key",
    }
    if not network:
        return dict(row,state="PINNED_LOCAL_SSH_IDENTITY_VERIFIED")
    cmd=["/usr/bin/ssh","-F","/dev/null","-T",
      "-o","BatchMode=yes","-o","IdentitiesOnly=yes",
      "-o","StrictHostKeyChecking=yes",
      "-o","UserKnownHostsFile="+str(root/"known_hosts_verified"),
      "-o","GlobalKnownHostsFile=/dev/null",
      "-o","ClearAllForwardings=yes",
      "-o","PermitLocalCommand=no","-o","ProxyCommand=none",
      "-o","ConnectTimeout=8","-o","LogLevel=ERROR",
      "-i",str(root/"id_ed25519"),"-p",str(HOSTING2["port"]),
      HOSTING2["user"]+"@"+HOSTING2["host"],"printf "+MARKER]
    try:
        result=subprocess.run(cmd,text=True,capture_output=True,
                              timeout=16,check=False)
        ok=result.returncode==0 and result.stdout==MARKER and result.stderr==""
        return dict(row,ssh_authenticated=ok,fixed_diagnostic_shell_verified=ok,
               state="PINNED_SSH_FIXED_DIAGNOSTIC_VERIFIED" if ok
                     else "SSH_CONNECTION_OR_DIAGNOSTIC_UNVERIFIED")
    except (OSError,subprocess.TimeoutExpired):
        return dict(row,state="SSH_CONNECTIVITY_UNCERTAIN_NO_RETRY")

def scoped_plan(target,problem,probe):
    need(target in TARGETS and problem in PROBLEMS, "UNRECOGNIZED_REMOTE_REPAIR_ROUTE")
    need(probe.get("target_id")==target
         and type(probe.get("ssh_authenticated")) is bool,
         "REMOTE_REPAIR_PROBE_INVALID")
    transport_ok=probe["ssh_authenticated"]
    # The native Broker owns the actual authorization. Target "hosting-1"
    # has SSH transport but no proven company binding in this inventory.
    bound=target in ("hosting-2","hosting-3")
    if not transport_ok:
        decision="TRANSPORT_REQUIRES_REPAIR_BEFORE_REMOTE_OPERATOR"
    elif not bound:
        decision="COMPANY_BINDING_REQUIRED_FOR_ANY_WRITE_GRANT"
    elif problem in ("original313_predecessor","original305_owner",
                     "original312_issuer","original318_readback"):
        decision="ROOT_FIRSTPARTY_ISSUER_DIAGNOSTIC_ONLY"
    else:
        decision="EXACT_BROKER_GRANT_REQUIRED_BEFORE_HOST_REPAIR_WRITE"
    return {
      "target":target,"problem":problem,"remediation":PROBLEMS[problem],
      "route":"VPS_TO_REGISTERED_HOST_PINNED_SSH",
      "owner_device_ssh_required":False,
      "owner_private_key_on_vps_required":False,
      "host_ssh_key_is_owner_stepup_signature":False,
      "existing_ssh_credentials_reused":True,
      "central_signed_exact_grant_required":True,
      "original313_signed_history_required_for_v3_write":True,
      "valid_company_scope_verified":bound,
      "decision":decision,
      "auto_repair_write_executed":False,"write_authorized":False,
    }

def inspect_all(*,network):
    need(os.geteuid()==0,"ROOT_READONLY_SSH_PROBE_REQUIRED")
    reg=original_registry()
    rows=[]
    for name in TARGETS:
        try:
            if name=="hosting-2":
                row=host2_fixed_probe(network=network)
            else:
                source=reg.check_host(name,use_network=network)
                row={
                  "target_id":name,"company":None if name=="hosting-1" else "egypt-tour-gates",
                  "environment":None if name=="hosting-1" else "staging",
                  "identity_pinned":source.get("pinned_identity") is True,
                  "ssh_authenticated":source.get("ssh_authenticated") is True,
                  "fixed_diagnostic_shell_verified":
                     source.get("verified_shell_permission") is True,
                  "source":"original_pinned_broker_registry",
                  "state":source.get("state"),
                }
        except (OSError,ValueError,KeyError) as e:
            row={
              "target_id":name,"state":type(e).__name__+"_FAIL_CLOSED",
              "identity_pinned":False,"ssh_authenticated":False,
              "fixed_diagnostic_shell_verified":False,
            }
        rows.append(row)
    return {
      "contract":CONTRACT,"observed_epoch":int(time.time()),
      "method":"VPS_TO_EXISTING_REGISTERED_HOST_SSH",
      "network_probe":bool(network),"targets":rows,
      "authenticated_targets":[x["target_id"] for x in rows
                               if x["ssh_authenticated"]],
      "central_grant_verifier_status":
           "VERIFIED" if network and reg.observe_central_verifier()
           else "NOT_EXECUTED",
      "owner_workstation_required_for_host_ssh":False,
      "no_private_keys_exported":True,
      "owner_stepup_v3_satisfied_by_ssh":False,
      "arbitrary_remote_shell_enabled":False,
      "write_authorized":False,"production_mutation":False,
    }

def report_status(report):
    need(os.geteuid()==0 and ROOT_REPORT.is_dir()
         and ROOT_REPORT.stat().st_uid==0
         and stat.S_IMODE(ROOT_REPORT.stat().st_mode)==0o700
         and not ROOT_REPORT.is_symlink(),
         "ROOT_SSH_REPORT_DIRECTORY_INVALID")
    if OUTPUT.exists():
        pin(OUTPUT)
    payload=(json.dumps(report,sort_keys=True,indent=2)+"\n").encode()
    temp=ROOT_REPORT/(".latest-"+str(os.getpid()))
    fd=os.open(temp,os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW|os.O_WRONLY,0o600)
    try:
        with os.fdopen(fd,"wb") as f:
            f.write(payload);f.flush();os.fsync(f.fileno())
        os.replace(temp,OUTPUT)
        fd=os.open(ROOT_REPORT,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
        try:os.fsync(fd)
        finally:os.close(fd)
    finally:
        if temp.exists():temp.unlink()

def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument("mode",choices=("local","probe","publish","plan"))
    p.add_argument("--target",choices=TARGETS)
    p.add_argument("--problem",choices=tuple(PROBLEMS))
    args=p.parse_args()
    need((args.mode=="plan")==(bool(args.target) and bool(args.problem)),
         "EXACT_PLAN_TARGET_AND_PROBLEM_REQUIRED")
    network=args.mode in ("probe","publish")
    status=inspect_all(network=network)
    if args.mode=="plan":
        target=next(x for x in status["targets"]
                    if x["target_id"]==args.target)
        status["requested_repair_plan"]=scoped_plan(
            args.target,args.problem,target)
    if args.mode=="publish":
        report_status(status)
    print(json.dumps(status,sort_keys=True))
    return 0

if __name__=="__main__":
    try:sys.exit(main())
    except (RepairDenied,ValueError,OSError,KeyError,
            subprocess.TimeoutExpired) as e:
        print("HOST_V144_SSH_REPAIR_FAIL_CLOSED:"+str(e),file=sys.stderr)
        sys.exit(3)
