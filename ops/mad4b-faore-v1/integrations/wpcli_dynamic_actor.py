#!/usr/bin/env python3
"""Resolve the enrolled Central WP-CLI actor and runtime from live scope facts.

Read identity only, not an impersonation grant or owner approval. The original
root-owned Host Authority remains the independent allowed-site anchor.
"""
from __future__ import annotations
import json
import os
import re
import subprocess
import time
from pathlib import Path
from typing import Callable

from stdio_scope import central_target

MARKER = "__MAD4B_DYNAMIC_WORDPRESS_ACTOR__"
TARGET_MARKER = "@mad4b-central-staging"
AUTHORITY = Path("/opt/mad4b-company-transport/native-operator-v1/host-authority.json")
SCOPE = "tours-central:staging"

CHECK = r"""$s=class_exists('MAD4B_SCP_Site_Profile',false)?MAD4B_SCP_Site_Profile::status():array();
$owner=MAD4B_Manager_Auto_Write_V1::enrolled_owner();
$manager=MAD4B_Company_Operation_Admission_V1::manager_id();
$managers=get_option(MAD4B_Company_Portfolio_Manager_V1::MANAGERS,[]);
$members=get_option(MAD4B_Company_Portfolio_Manager_V1::MEMBERS,[]);
$member=$members[$manager."|".$owner]??null;
$record=$managers[$manager]??null;
$valid=(wp_get_environment_type()==="staging"
    && ($s["contract"]??"")==="mad4b.site-profile.v2"
    && ($s["configured"]??false)===true
    && ($s["origin_match"]??false)===true
    && ($s["environment_match"]??false)===true
    && ($s["deployment_binding_match"]??false)===true
    && ($s["environment"]??"")==="staging"
    && ($s["canonical_origin"]??"")===untrailingslashit(home_url("/"))
    && ($s["site_uuid"]??"")!=="" && wp_is_uuid($s["site_uuid"])
    && defined("DB_NAME") && preg_match('/^[A-Za-z0-9_]{3,64}$/D',DB_NAME)
    && is_int($owner) && $owner>0 && wp_is_uuid($manager)
    && is_array($record) && ($record["manager_id"]??null)===$manager
    && ($record["state"]??null)==="registered"
    && is_array($member) && ($member["state"]??null)==="active"
    && (int)($member["user_id"]??0)===$owner
    && (bool)get_user_by("id",$owner)
    && user_can($owner,"manage_options") && user_can($owner,"activate_plugins"));
echo wp_json_encode(["valid"=>$valid,"actor_user_id"=>$valid?$owner:null,
    "site_uuid"=>$s["site_uuid"]??null,
    "canonical_origin"=>$s["canonical_origin"]??null,
    "environment"=>$s["environment"]??null,
    "owner_stepup"=>false,
    "write_authorized"=>false,"grant_issued"=>false]);"""


def root_scope(policy_path: Path = AUTHORITY) -> dict:
    stat = policy_path.lstat()
    if (policy_path.is_symlink() or not policy_path.is_file()
            or stat.st_uid != 0 or stat.st_mode & 0o077
            or stat.st_size < 1 or stat.st_size > 65536):
        raise RuntimeError("HOST_AUTHORITY_ROOT_OWNERSHIP_REQUIRED")
    data = json.loads(policy_path.read_bytes())
    if (not isinstance(data,dict)
            or data.get("contract")!="mad4b.native-vps-operator.v1"
            or data.get("principal")!="root"
            or data.get("revoked") is True):
        raise RuntimeError("ORIGINAL_HOST_AUTHORITY_INVALID")
    host=subprocess.run(["/usr/bin/hostname","-s"],text=True,capture_output=True,
                        check=False,timeout=5)
    if host.returncode or data.get("host_id")!=host.stdout.strip():
        raise RuntimeError("HOST_IDENTITY_MISMATCH")
    entry=data.get("scopes",{}).get(SCOPE)
    if (not isinstance(entry,dict) or entry.get("allow_operator") is not True
            or entry.get("allow_production_mutation") is not False
            or entry.get("site_environment")!="staging"
            or not isinstance(entry.get("site_uuid"),str)
            or not re.fullmatch(r"[0-9a-f-]{36}",entry["site_uuid"])
            or not isinstance(entry.get("canonical_origin"),str)
            or not entry["canonical_origin"].startswith("https://")):
        raise RuntimeError("CENTRAL_STAGING_OPERATOR_SCOPE_MISSING")
    return {"site_uuid":entry["site_uuid"],
            "canonical_origin":entry["canonical_origin"],
            "environment":"staging"}


def enrolled_target(*, discover: Callable = central_target,
                    scope_loader: Callable = root_scope) -> dict:
    scope=scope_loader()
    actual=discover("staging")
    for key in ("site_uuid","canonical_origin","environment"):
        if actual.get(key) != scope.get(key):
            raise RuntimeError("HOST_SCOPE_SITE_PROFILE_MISMATCH")
    name=actual.get("container")
    if not isinstance(name,str) or not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_.-]{0,127}",name):
        raise RuntimeError("CENTRAL_RUNTIME_CONTAINER_INVALID")
    return actual


def resolve_actor(runner=subprocess.run, *,
                  target_resolver: Callable = enrolled_target) -> int:
    target = target_resolver()
    container=target["container"]
    cli=["/usr/bin/docker","exec","-u","www-data",container,
         "/usr/local/bin/wp","--path=/var/www/html","--skip-themes",
         "eval",CHECK]
    for attempt in range(4):
        p=runner(cli,capture_output=True,text=True,check=False,timeout=40)
        if p.returncode==0:
            break
        if "STAGING_WPCLI_BUSY" not in (p.stderr or "") or attempt==3:
            raise RuntimeError("WPCLI_DYNAMIC_ACTOR_READBACK_DENIED")
        time.sleep(attempt+1)
    try:
        result=json.loads(p.stdout.strip().splitlines()[-1])
    except (ValueError,TypeError,IndexError):
        raise RuntimeError("WPCLI_DYNAMIC_ACTOR_INVALID_JSON")
    if not isinstance(result,dict):
        raise RuntimeError("WPCLI_DYNAMIC_ACTOR_INVALID_JSON")
    owner=result.get("actor_user_id")
    if (result.get("valid") is not True or type(owner) is not int
            or owner<1 or owner>2147483647
            or result.get("site_uuid")!=target["site_uuid"]
            or result.get("canonical_origin")!=target["canonical_origin"]
            or result.get("environment")!=target["environment"]
            or result.get("owner_stepup") is not False
            or result.get("write_authorized") is not False
            or result.get("grant_issued") is not False):
        raise RuntimeError("WPCLI_DYNAMIC_ACTOR_UNVERIFIED")
    return owner


def with_actor(argv: list[str], runner=subprocess.run,
               *, target_resolver: Callable = enrolled_target) -> list[str]:
    if MARKER not in argv:
        return argv
    if argv.count(MARKER)!=1 or any(isinstance(a,str) and a.startswith("--user=") for a in argv):
        raise RuntimeError("WPCLI_DYNAMIC_ACTOR_AMBIGUOUS")
    if not (len(argv)>=9 and argv[0] in ("docker","/usr/bin/docker")
            and argv[1:4]==["exec","-u","www-data"]
            and argv[5] in ("wp","/usr/local/bin/wp")
            and "--path=/var/www/html" in argv[6:]
            and "--skip-themes" in argv[6:]):
        raise RuntimeError("WPCLI_DYNAMIC_ACTOR_COMMAND_INVALID")
    target=target_resolver()
    if argv[4] not in (TARGET_MARKER,target["container"]):
        raise RuntimeError("WPCLI_DYNAMIC_ACTOR_CONTAINER_MISMATCH")
    # Retain current WP-CLI command and flags. Only a scope-authorized target
    # marker can be rebound to an actually observed exact Central runtime.
    actual_argv=list(argv)
    actual_argv[4]=target["container"]
    actor=resolve_actor(runner=runner,target_resolver=lambda:target)
    return ["--user="+str(actor) if x==MARKER else x for x in actual_argv]
