"""Discover actual MCP tools on enrolled WordPress STDIO servers.

Read-only MCP initialize/tools/list, no tools/call, no OAuth, no grants.
Server selection is based on advertised exact tool names, not guessed aliases.
"""
from __future__ import annotations
import json
import re
import subprocess
from typing import Callable

ID = re.compile(r"^[a-z][a-z0-9-]{2,63}$")
TOOL = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._/-]{2,127}$")
CONTAINER = re.compile(r"^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$")
PROTOCOL = "2025-06-18"


def run(argv, *, payload=None, timeout=35):
    p = subprocess.run(argv, input=payload, capture_output=True, text=True,
                       check=False, timeout=timeout)
    if p.returncode != 0:
        if "STAGING_WPCLI_BUSY" in ((p.stderr or "") + (p.stdout or "")):
            raise RuntimeError("MCP_STDIO_READ_SLOT_BUSY")
        raise RuntimeError("MCP_STDIO_PROBE_UNAVAILABLE")
    if len(p.stdout) > 5_000_000:
        raise RuntimeError("MCP_STDIO_PROBE_UNAVAILABLE")
    return p.stdout


def server_ids(container: str, actor: int, *, runner: Callable = run) -> list[str]:
    if not CONTAINER.fullmatch(container) or type(actor) is not int or actor <= 0:
        raise ValueError("MCP_PROBE_ACTOR_OR_TARGET_INVALID")
    rows = json.loads(runner([
        "docker","exec","-u","www-data",container,"wp",
        "--path=/var/www/html","--skip-themes",
        "mcp-adapter","list","--format=json",
    ]))
    if not isinstance(rows,list) or len(rows)>30:
        raise RuntimeError("MCP_SERVER_REGISTRY_UNBOUNDED")
    names=[]
    for item in rows:
        sid=item.get("ID") if isinstance(item,dict) else None
        if isinstance(sid,str) and ID.fullmatch(sid) and sid not in names:
            names.append(sid)
    return names


def tools_on_server(container: str, actor: int, server: str,
                    *, runner: Callable = run) -> set[str]:
    if not CONTAINER.fullmatch(container) or not ID.fullmatch(server):
        raise ValueError("MCP_SERVER_TARGET_INVALID")
    if type(actor) is not int or actor <= 0:
        raise ValueError("MCP_ACTOR_UNVERIFIED")
    messages=[
      {"jsonrpc":"2.0","id":1,"method":"initialize",
       "params":{"protocolVersion":PROTOCOL,"capabilities":{},
                 "clientInfo":{"name":"mad4b-faore-readonly-inventory","version":"1.0"}}},
      {"jsonrpc":"2.0","method":"notifications/initialized","params":{}},
      {"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}},
    ]
    payload="\n".join(json.dumps(x,separators=(',',':')) for x in messages)+"\n"
    data=runner([
        "docker","exec","-i","-u","www-data",container,"wp",
        "--path=/var/www/html","--skip-themes","mcp-adapter","serve",
        "--server="+server,"--user="+str(actor)
    ],payload=payload)
    responses=[]
    for line in data.splitlines():
        try: row=json.loads(line)
        except ValueError: continue
        if isinstance(row,dict) and row.get("id")==2:
            responses.append(row)
    if len(responses)!=1 or "error" in responses[0]:
        raise RuntimeError("MCP_TOOLS_LIST_UNVERIFIED")
    result=responses[0].get("result") or {}
    if (not isinstance(result,dict) or not isinstance(result.get("tools"),list)
        or result.get("nextCursor") or len(result["tools"])>1200):
        raise RuntimeError("MCP_TOOLS_INVENTORY_INCOMPLETE")
    names=set()
    for item in result["tools"]:
        name=item.get("name") if isinstance(item,dict) else None
        if not isinstance(name,str) or not TOOL.fullmatch(name):
            raise RuntimeError("MCP_TOOL_NAME_INVALID")
        names.add(name)
    return names


def resolve(tool: str, effect: str, container: str, actor: int,
            *, list_servers: Callable = server_ids,
            list_tools: Callable = tools_on_server) -> dict:
    if not isinstance(tool,str) or not TOOL.fullmatch(tool):
        raise ValueError("MCP_TOOL_INVALID")
    if effect not in ("read","write"):
        raise ValueError("MCP_EFFECT_INVALID")
    discovered=list_servers(container,actor)
    # Server role is discovered from currently registered names. Restricting
    # inventory to the requested effect avoids unbounded developer catalogs
    # and avoids treating a content/read endpoint as write authority.
    roles=("write","content") if effect=="write" else ("read","content")
    candidates=[sid for sid in discovered if any(role in sid.split("-") for role in roles)]
    matches=[]
    for sid in candidates:
        if tool in list_tools(container,actor,sid):
            matches.append(sid)
    output={
        "contract":"mad4b.faore.mcp-stdio-server-route.v1",
        "transport":"vps_mcp_stdio",
        "tool_name":tool,"effect":effect,
        "registered_server_count":len(discovered),
        "server_count_scanned":len(candidates),
        "matched_server_count":len(matches),
        "selected_server":None,
        "authorization_granted":False,
        "tools_invoked":False,
        "oauth_required":False,
        "write_admitted":False,
        "unknown_previous_attempt_safe_to_retry":False,
    }
    if not candidates:
        return {**output,"state":"EFFECT_SERVER_NOT_REGISTERED"}
    if not matches:
        return {**output,"state":"TOOL_NOT_ADVERTISED_FOR_EFFECT"}
    if len(matches)>1:
        return {**output,"state":"MULTIPLE_MCP_SERVERS_REQUIRE_EXPLICIT_POLICY"}
    return {**output,"selected_server":matches[0],
            "state":"TOOL_DISCOVERED_REQUIRES_LOCAL_ADMISSION"}
