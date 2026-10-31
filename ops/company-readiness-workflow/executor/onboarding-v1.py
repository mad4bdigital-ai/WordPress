#!/usr/bin/env python3
"""MAD4B registered company/environment inventory: discover, don't grant."""
import datetime
import json
import os
import pathlib
import re
import subprocess
import sys
import tempfile
from urllib.parse import urlsplit

BASE=pathlib.Path("/opt/mad4b-company-transport")
DEST=BASE/"environment-inventory-v1.json"
CMD=["docker","exec","-u","www-data","etg-commerce-staging-php",
     "wp","mad4b-company-env","status","--user=1","--skip-plugins",
     "--skip-themes","--path=/var/www/html"]
CONTRACT="mad4b.company-transport-inventory.v1"
HOST_POLICY=BASE/"native-operator-v1"/"host-authority.json"
OPERATOR=BASE/"vps-native-operator.py"

def load_host_policy():
    if not HOST_POLICY.is_file() or HOST_POLICY.is_symlink():
        return {}
    st=HOST_POLICY.stat()
    if st.st_uid!=0 or st.st_mode & 0o077 or st.st_size>65536:
        return {}
    try:
        policy=json.loads(HOST_POLICY.read_text())
    except (ValueError,OSError):
        return {}
    if (policy.get("contract")!="mad4b.native-vps-operator.v1"
        or policy.get("host_id")!="srv2030908"
        or policy.get("principal")!="root"
        or policy.get("revoked") is True
        or not OPERATOR.is_file() or OPERATOR.is_symlink()):
        return {}
    return policy


def project(source, host_policy=None):
    if source.get("contract")!="mad4b.company-environment-cli.v1" or \
       source.get("environment")!="staging" or \
       source.get("central_origin")!="https://tours.dev.mad4b.com":
        raise ValueError("untrusted_central_inventory")
    rows=source.get("registrations")
    if not isinstance(rows,dict):raise ValueError("invalid_registry_rows")
    out={}
    host_policy=host_policy if isinstance(host_policy,dict) else {}
    scopes=host_policy.get("scopes",{}) if isinstance(host_policy.get("scopes"),dict) else {}
    for key,r in rows.items():
        if not re.fullmatch(r"[a-z][a-z0-9-]{2,47}:(?:staging|production)",key):
            raise ValueError("invalid_company_environment")
        if not isinstance(r,dict):raise ValueError("invalid_target")
        env=key.rsplit(":",1)[1]
        origin=r.get("canonical_origin","")
        u=urlsplit(origin)
        if u.scheme!="https" or not u.hostname or u.username or u.password or u.port or \
           u.query or u.fragment or u.path not in ("","/"):
            raise ValueError("untrusted_target_origin")
        uuid=r.get("site_uuid")
        if uuid is not None and not re.fullmatch(r"[a-f0-9-]{36}",str(uuid)):
            raise ValueError("invalid_target_uuid")
        signed=isinstance(r.get("identity_candidate"),bool) and r.get("identity_candidate")
        read_adapter=key=="all-royal-egypt:staging" and \
          origin=="https://staging.allroyalegypt.com" and \
          uuid=="49c562d1-8f2f-456f-b454-26816c6ba4cb"
        host_scope=scopes.get(key,{})
        if not isinstance(host_scope,dict):host_scope={}
        host_shell=(host_policy.get("allow_general_shell") is True
            and host_scope.get("allow_operator") is True)
        host_sql=(host_policy.get("allow_raw_sql") is True
            and host_scope.get("allow_operator") is True
            and host_scope.get("allow_sql") is True)
        host_write=((host_shell or host_sql) and host_scope.get("allow_mutation") is True
            and (env!="production" or host_scope.get("allow_production_mutation") is True))
        out[key]={
          "company_id":key.rsplit(":",1)[0],
          "environment":env,
          "canonical_origin":origin,
          "site_uuid":uuid,
          "read_adapter":"pinned_staging_ssh" if read_adapter else None,
          "identity_probe_candidate":signed,
          "managed_executor":"separate_enrollment_required",
          "managed_execution_authorized":False,
          "production_mutation":False,
          "native_vps_operator_capability_available":True,
          "native_vps_shell_authorized":host_shell,
          "native_vps_sql_authorized":host_sql,
          "native_vps_write_authorized":host_write,
          "onboarding_state":"HOST_NATIVE_OPERATOR_AUTHORIZED_MANAGED_PENDING" if (host_shell or host_sql) else
            "READ_ADAPTER_READY_MANAGED_PENDING" if read_adapter else
            "PRODUCTION_HOST_AUTHORITY_ENROLLMENT_REQUIRED" if env=="production" else
            "READ_AND_MANAGED_ENROLLMENT_REQUIRED"
        }
    return out

def scan():
    p=subprocess.run(CMD,capture_output=True,text=True,timeout=25,check=False)
    if p.returncode:raise RuntimeError("central_staging_wpcli_not_ready")
    try:data=json.loads(p.stdout)
    except ValueError:raise RuntimeError("invalid_central_wpcli_response")
    fresh=project(data,load_host_policy())
    old={}
    if DEST.exists():
        if DEST.is_symlink():raise RuntimeError("unsafe_inventory_destination")
        try:old=json.loads(DEST.read_text()).get("targets",{})
        except (ValueError,TypeError):old={}
    added=sorted(set(fresh)-set(old))
    removed=sorted(set(old)-set(fresh))
    changed=sorted(k for k in set(fresh)&set(old) if fresh[k]!=old[k])
    result={"contract":CONTRACT,"updated_at":datetime.datetime.now(datetime.timezone.utc).isoformat(),
            "company_count":len({r["company_id"] for r in fresh.values()}),
            "environment_count":len(fresh),"targets":fresh,
            "changes":{"added":added,"removed":removed,"updated":changed},
            "production_mutation":False,"external_mutation":False}
    fd,tmp=tempfile.mkstemp(dir=BASE,prefix=".env-inventory-")
    try:
        os.fchmod(fd,0o600)
        with os.fdopen(fd,"w") as f:
            json.dump(result,f,sort_keys=True)
            f.flush();os.fsync(f.fileno())
        os.replace(tmp,DEST)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)
    return result

def main():
    os.umask(0o077)
    if os.geteuid()!=0:raise SystemExit("root_owned_inventory_only")
    if len(sys.argv)!=2 or sys.argv[1] not in ("scan","status"):
        raise SystemExit("usage: onboarding-v1.py scan|status")
    if sys.argv[1]=="scan":r=scan()
    else:
        if not DEST.is_file() or DEST.is_symlink():raise SystemExit("inventory_missing")
        r=json.loads(DEST.read_text())
    print(json.dumps({"contract":r["contract"],"updated_at":r["updated_at"],
       "company_count":r["company_count"],"environment_count":r["environment_count"],
       "changes":r["changes"],"targets":r["targets"],"production_mutation":False},
       sort_keys=True))

if __name__=="__main__":
    try:main()
    except (RuntimeError,ValueError,OSError,subprocess.TimeoutExpired) as e:
        print(json.dumps({"state":"INVENTORY_DISCOVERY_FAILED_CLOSED",
              "reason":str(e)[:120],"production_mutation":False}),file=sys.stderr)
        sys.exit(75)
