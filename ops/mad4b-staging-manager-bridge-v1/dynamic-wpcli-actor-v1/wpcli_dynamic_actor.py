#!/usr/bin/env python3
"""Staging-only dynamic enrolled WordPress diagnostic actor; never an owner consent or grant."""
import json
import re
import subprocess
from pathlib import Path

MARKER="__MAD4B_DYNAMIC_WORDPRESS_ACTOR__"
WP=['/usr/bin/docker','exec','-u','www-data','etg-commerce-staging-php','/usr/local/bin/wp',
    '--path=/var/www/html','--url=https://tours.dev.mad4b.com','--skip-themes']
CHECK=r"""$owner=MAD4B_Manager_Auto_Write_V1::enrolled_owner();
$manager=MAD4B_Company_Operation_Admission_V1::manager_id();
$managers=get_option(MAD4B_Company_Portfolio_Manager_V1::MANAGERS,[]);
$members=get_option(MAD4B_Company_Portfolio_Manager_V1::MEMBERS,[]);
$member=$members[$manager."|".$owner]??null;
$record=$managers[$manager]??null;
$valid=(wp_get_environment_type()==="staging"
    && defined("DB_NAME") && DB_NAME==="etg_commerce_staging"
    && untrailingslashit(home_url("/"))==="https://tours.dev.mad4b.com"
    && is_int($owner) && $owner>0 && wp_is_uuid($manager)
    && is_array($record) && ($record["manager_id"]??null)===$manager
    && ($record["state"]??null)==="registered"
    && is_array($member) && ($member["state"]??null)==="active"
    && (int)($member["user_id"]??0)===$owner
    && (bool)get_user_by("id",$owner)
    && user_can($owner,"manage_options") && user_can($owner,"activate_plugins"));
echo wp_json_encode(["valid"=>$valid,"actor_user_id"=>$valid?$owner:null,
    "owner_stepup"=>false,"write_authorized"=>false,"grant_issued"=>false]);
"""

def resolve_actor(runner=subprocess.run):
    p=runner(WP+['eval',CHECK],capture_output=True,text=True,
             check=False,timeout=40)
    if p.returncode!=0: raise RuntimeError("WPCLI_DYNAMIC_ACTOR_READBACK_DENIED")
    try: result=json.loads(p.stdout.strip())
    except (ValueError,TypeError):raise RuntimeError("WPCLI_DYNAMIC_ACTOR_INVALID_JSON")
    owner=result.get('actor_user_id')
    if (result.get('valid') is not True or type(owner) is not int
        or owner<1 or owner>2147483647
        or result.get('owner_stepup') is not False
        or result.get('write_authorized') is not False
        or result.get('grant_issued') is not False):
        raise RuntimeError("WPCLI_DYNAMIC_ACTOR_UNVERIFIED")
    return owner

def with_actor(argv,runner=subprocess.run):
    if MARKER not in argv: return argv
    if argv.count(MARKER)!=1 or any(isinstance(a,str) and a.startswith('--user=') for a in argv):
        raise RuntimeError("WPCLI_DYNAMIC_ACTOR_AMBIGUOUS")
    # This context is permitted only for the original registered central Staging container.
    prefix=('/usr/bin/docker','exec','-u','www-data')
    if not (argv[0] in ('docker','/usr/bin/docker') and
            tuple(argv[1:4])==prefix[1:] and
            'etg-commerce-staging-php' in argv[:8]):
        raise RuntimeError("WPCLI_DYNAMIC_ACTOR_CONTAINER_MISMATCH")
    owner=resolve_actor(runner=runner)
    return ['--user='+str(owner) if x==MARKER else x for x in argv]
