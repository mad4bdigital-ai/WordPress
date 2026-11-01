#!/usr/bin/env python3
"""MAD4B central WordPress WP-CLI: SSH-root-origin plan/readback only.

Host identity and exact company/site environment are read from the existing
root-owned MAD4B Native Operator scope; no --user impersonation or new key.
This bridge NEVER accepts arbitrary commands or calls a mutation endpoint.
"""
import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import uuid

CONTRACT="mad4b.central-wpcli-host-execution.v1"
OPERATOR=Path("/opt/mad4b-company-transport/vps-native-operator.py")
CONTAINER="etg-commerce-staging-php"
SITE="tours-central:staging"
WP="/var/www/html"
SCRIPTS={
 "context-inspect": ("mad4b","context","inspect"),
 "context-check": ("mad4b","context","check"),
 "full-authority-plan": ("mad4b","full-authority-unified"),
 "full-authority-lifecycle": ("mad4b","full-authority-lifecycle"),
}
EXPECTED_CONTRACTS={
 "context-inspect":"mad4b.cli-execution-context.v1",
 "context-check":"mad4b.cli-execution-context.v1",
 "full-authority-plan":"mad4b.unified-full-authority.v1",
 "full-authority-lifecycle":"mad4b.unified-authority-lifecycle-status.v1",
}

def module():
    spec=importlib.util.spec_from_file_location("mad4b_native_operator_host",OPERATOR)
    mod=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod

def run(command,timeout=35):
    process=subprocess.run(command,text=True,capture_output=True,timeout=timeout,check=False)
    if process.returncode!=0:
        raise RuntimeError("WP_CLI_HOST_READBACK_FAILED_"+str(process.returncode))
    return process.stdout.strip()

def trusted_identity(native):
    native.root_session()
    policy=native.authority()
    scopes=policy.get("scopes",{})
    entry=scopes.get(SITE)
    if not isinstance(entry,dict) or entry.get("allow_operator") is not True or \
       entry.get("site_environment")!="staging" or \
       entry.get("allow_production_mutation") is not False:
        raise RuntimeError("CENTRAL_STAGING_HOST_OPERATOR_SCOPE_UNAVAILABLE")
    expected_uuid=entry.get("site_uuid")
    expected_origin=entry.get("canonical_origin")
    if not isinstance(expected_uuid,str) or not re.fullmatch(r"[a-f0-9-]{36}",expected_uuid) or \
       expected_origin!="https://tours.dev.mad4b.com":
        raise RuntimeError("CENTRAL_STAGING_SCOPE_IDENTITY_INVALID")
    # Read WordPress-origin truth independently from the *enrolled* container;
    # never infer a company/site binding from the caller's environment.
    php='echo wp_json_encode(array("site_uuid"=>MAD4B_SCP_Site_Profile::site_uuid(),"origin"=>rtrim(home_url(),"/"),"environment"=>wp_get_environment_type(),"database"=>DB_NAME));'
    value=run(["docker","exec","-u","www-data",CONTAINER,"wp","eval",php,
               "--path="+WP,"--skip-themes"],timeout=28)
    actual=json.loads(value)
    if actual.get("site_uuid")!=expected_uuid or \
       actual.get("origin")!=expected_origin or \
       actual.get("environment")!="staging" or \
       not isinstance(actual.get("database"),str) or \
       not re.fullmatch(r"[A-Za-z0-9_]{3,64}",actual["database"]):
        raise RuntimeError("WORDPRESS_ORIGINAL_SITE_BINDING_MISMATCH")
    return {"site_uuid":expected_uuid,"origin":expected_origin,
            "environment":"staging","database":actual["database"]}

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument("command",choices=tuple(SCRIPTS))
    args=parser.parse_args()
    native=module()
    ident=trusted_identity(native)
    argv=["docker","exec","-u","0",
          "-e","MAD4B_CLI_TARGET_SITE_UUID="+ident["site_uuid"],
          "-e","MAD4B_CLI_TARGET_ORIGIN="+ident["origin"],
          "-e","MAD4B_CLI_TARGET_DATABASE="+ident["database"],
          CONTAINER,"wp",*SCRIPTS[args.command],
          "--allow-root","--path="+WP,"--skip-themes"]
    output=run(argv,timeout=58)
    parsed=json.loads(output)
    if not isinstance(parsed,dict) or \
       parsed.get("contract")!=EXPECTED_CONTRACTS[args.command] or \
       parsed.get("site_uuid")!=ident["site_uuid"] or \
       parsed.get("environment")!=ident["environment"]:
        raise RuntimeError("WRONG_SITE_OR_CONTRACT_READBACK")
    if args.command in ("context-inspect","context-check"):
        if parsed.get("host_site_binding_verified") is not True or \
           parsed.get("read_context_verified") is not True or \
           parsed.get("blockers"):
            raise RuntimeError("HOST_WPCLI_CONTEXT_NOT_VERIFIED")
    if parsed.get("mutation_performed") is not False:
        raise RuntimeError("READ_ONLY_COMMAND_REPORTED_MUTATION")
    digest=hashlib.sha256(output.encode()).hexdigest()
    receipt={"contract":CONTRACT,"state":"VERIFIED",
             "operation":args.command,"site_uuid":ident["site_uuid"],
             "environment":ident["environment"],"canonical_origin":ident["origin"],
             "source":"registered_root_ssh_plus_exact_host_scope",
             "readback_sha256":digest,"mutation_performed":False,
             "native_owner_mutation_requested":False,"result":parsed}
    native.journal("central-wpcli-"+uuid.uuid4().hex,"CENTRAL_WPCLI_READBACK_VERIFIED",
                   {"operation":args.command,"site_uuid":ident["site_uuid"],
                    "readback_sha256":digest,"production_mutation":False})
    print(json.dumps(receipt,sort_keys=True,separators=(",",":")))

if __name__=="__main__":
    try:
        main()
    except (RuntimeError,ValueError,KeyError,OSError,subprocess.TimeoutExpired,TypeError) as exc:
        print(json.dumps({"contract":CONTRACT,"state":"DENIED",
                          "reason":str(exc),"mutation_performed":False,
                          "production_mutation":False}))
        sys.exit(75)
