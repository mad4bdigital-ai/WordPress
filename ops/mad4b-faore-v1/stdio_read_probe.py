"""Bounded host-side MCP STDIO read probe for the Central Runtime Registry.

Finds WordPress by Compose PHP role and exact enrolled Site Profile, lists
servers dynamically, invokes one declared read-only canary, and publishes
redacted, short-lived availability only. Never creates a job or grant.
"""
from __future__ import annotations
import argparse
import hashlib
import json
import re
import subprocess
import sys
import time
from pathlib import Path
from typing import Callable

from stdio_routing import run, server_ids

CANARY = "mad4b-content-job-list"


def php_target(site_uuid: str, origin: str, environment: str,
               *, runner: Callable = run) -> str:
    names=runner(["docker","ps","--filter",
                  "label=com.docker.compose.service=php","--format","{{.Names}}"]).splitlines()
    if not names or len(names)>30:
        raise RuntimeError("CENTRAL_PHP_DISCOVERY_UNBOUNDED")
    matches=[]
    for name in names:
        if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_.-]{0,127}",name):
            continue
        def option(key,fmt=None):
            args=["docker","exec","-u","www-data",name,"wp",
                  "--path=/var/www/html","--skip-plugins","--skip-themes",
                  "option","get",key]
            if fmt:args.append("--format="+fmt)
            return runner(args,timeout=18).strip()
        try:
            profile=json.loads(option("mad4b_scp_site_profile_v2","json"))
            siteurl=option("siteurl")
        except (RuntimeError,ValueError,subprocess.TimeoutExpired):
            continue
        if (isinstance(profile,dict) and
            profile.get("contract")=="mad4b.site-profile.v2" and
            profile.get("site_uuid")==site_uuid and
            profile.get("canonical_origin")==origin and
            profile.get("environment")==environment and siteurl==origin):
            matches.append(name)
    if len(matches)!=1:
        raise RuntimeError("CENTRAL_EXACT_PHP_NOT_UNIQUE" if matches
                           else "CENTRAL_SITE_PROFILE_NOT_DISCOVERED")
    return matches[0]


def exchange(container: str, actor: int, server: str, method: str,
             arguments: dict | None = None, *, runner: Callable = run) -> dict:
    messages=[
        {"jsonrpc":"2.0","id":1,"method":"initialize",
         "params":{"protocolVersion":"2025-06-18","capabilities":{},
                   "clientInfo":{"name":"faore-vps-stdio-probe","version":"1"}}},
        {"jsonrpc":"2.0","method":"notifications/initialized","params":{}},
        {"jsonrpc":"2.0","id":2,"method":method,"params":arguments or {}},
    ]
    payload="\n".join(json.dumps(m,separators=(",",":")) for m in messages)+"\n"
    output=runner([
        "docker","exec","-i","-u","www-data",container,"wp",
        "--path=/var/www/html","--skip-themes",
        "mcp-adapter","serve","--server="+server,"--user="+str(actor)
    ],payload=payload,timeout=35)
    matches=[]
    for line in output.splitlines():
        try:row=json.loads(line)
        except ValueError:continue
        if isinstance(row,dict) and row.get("id")==2:matches.append(row)
    if len(matches)!=1 or "error" in matches[0] or not isinstance(matches[0].get("result"),dict):
        raise RuntimeError("MCP_STDIO_RESPONSE_INVALID")
    return matches[0]["result"]


def read_probe(container: str, actor: int, *, runner: Callable = run) -> dict:
    servers=server_ids(container,actor,runner=runner)
    # The canary's category is content; actual tool presence and MCP
    # readOnlyHint must independently confirm it can be used for probing.
    scoped=[sid for sid in servers if "content" in sid.split("-")]
    found=[]
    for sid in scoped:
        material=exchange(container,actor,sid,"tools/list",runner=runner)
        tools=material.get("tools")
        if not isinstance(tools,list) or len(tools)>1200 or material.get("nextCursor"):
            raise RuntimeError("MCP_READ_TOOL_INVENTORY_UNVERIFIED")
        match=[tool for tool in tools if isinstance(tool,dict) and tool.get("name")==CANARY]
        if match:found.append((sid,len(tools),match[0]))
    if len(found)!=1:
        raise RuntimeError("MCP_READ_CANARY_NOT_UNIQUE")
    server,tool_count,descriptor=found[0]
    annotations=descriptor.get("annotations") or {}
    if (annotations.get("readOnlyHint") is not True
        or annotations.get("destructiveHint") is not False):
        raise RuntimeError("MCP_READ_CANARY_NOT_READONLY")
    result=exchange(container,actor,server,"tools/call",
                    {"name":CANARY,"arguments":{"limit":1}},runner=runner)
    if result.get("isError") is not False or not isinstance(result.get("content"),list):
        raise RuntimeError("MCP_READ_CANARY_FAILED")
    serialized=json.dumps(result,sort_keys=True,separators=(",",":"),ensure_ascii=False)
    return {"tool_count":tool_count,"read_tool_name":CANARY,
            "readback_sha256":hashlib.sha256(serialized.encode()).hexdigest(),
            "read_tool_verified":True}


def publish(container: str, observation: dict, *, runner: Callable = run):
    php="""$p=json_decode(stream_get_contents(STDIN),true);
    $s=class_exists('MAD4B_SCP_Site_Profile',false)?MAD4B_SCP_Site_Profile::status():array();
    if(!is_array($p)||($p['contract']??'')!=='mad4b.vps-mcp-stdio-read-probe.v1'
       ||($p['site_uuid']??'')!==($s['site_uuid']??'')
       ||($p['canonical_origin']??'')!==($s['canonical_origin']??'')
       ||($p['environment']??'')!==($s['environment']??'')
       ||($p['mutation_performed']??null)!==false
       ||($p['oauth_used']??null)!==false
       ||($p['read_tool_verified']??null)!==true) {echo 'INVALID';return;}
    echo set_transient('mad4b_vps_mcp_stdio_probe_v1',$p,360)?'SAVED':'FAILED';"""
    result=runner([
        "docker","exec","-i","-u","www-data",container,"wp",
        "--path=/var/www/html","--skip-themes","eval",php],
        payload=json.dumps(observation,separators=(",",":"))+"\n",timeout=35)
    if result.strip()!="SAVED":
        raise RuntimeError("MCP_STDIO_OBSERVATION_NOT_STORED")


def run_once(site_uuid: str, origin: str, environment: str, *,
             target: Callable = php_target, probe: Callable = read_probe,
             store: Callable = publish, actor_resolver: Callable | None = None) -> dict:
    if actor_resolver is None:
        sys.path.insert(0,"/opt/mad4b-company-transport")
        from wpcli_dynamic_actor import resolve_actor
        actor_resolver=resolve_actor
    container=target(site_uuid,origin,environment)
    actor=actor_resolver()
    if type(actor) is not int or actor<1:
        raise RuntimeError("HOST_WORDPRESS_ACTOR_UNVERIFIED")
    proof=probe(container,actor)
    record={
        "contract":"mad4b.vps-mcp-stdio-read-probe.v1",
        "site_uuid":site_uuid,"canonical_origin":origin,
        "environment":environment,"observed_at":int(time.time()),
        **proof,"oauth_used":False,"mutation_performed":False,
    }
    store(container,record)
    return {"state":"READ_PROBE_RECORDED","transport":"vps_mcp_stdio",
            "read_tool_verified":True,"tool_count":proof["tool_count"],
            "authorization_granted":False,"mutation_performed":False}


if __name__=="__main__":
    parser=argparse.ArgumentParser()
    parser.add_argument("--site-uuid",required=True)
    parser.add_argument("--origin",required=True)
    parser.add_argument("--environment",required=True)
    a=parser.parse_args()
    try:print(json.dumps(run_once(a.site_uuid,a.origin,a.environment),sort_keys=True))
    except Exception as e:
        print(json.dumps({"state":"READ_PROBE_UNAVAILABLE","reason":str(e)[:120],
                          "authorization_granted":False,"mutation_performed":False}))
        sys.exit(2)
