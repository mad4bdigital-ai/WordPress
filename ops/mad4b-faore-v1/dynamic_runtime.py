"""Coolify-backed exact WordPress runtime discovery; never authorizes writes."""
import json
import re
import subprocess
from urllib.parse import urlsplit
from uuid import UUID

VALID_NAME = re.compile(r"^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$")


def run(args, timeout=15):
    p = subprocess.run(args, capture_output=True, text=True, timeout=timeout, check=False)
    if p.returncode or len(p.stdout)>262144:
        raise RuntimeError("READBACK_UNAVAILABLE")
    return p.stdout.strip()


def containers():
    names = run(["docker","ps","--filter","label=coolify.managed=true",
                 "--filter","label=com.docker.compose.service=php",
                 "--format","{{.Names}}"]).splitlines()
    if len(names)>80:
        raise RuntimeError("DISCOVERY_OVERFLOW")
    result=[]
    for name in names:
        if not VALID_NAME.fullmatch(name):
            continue
        try:
            result.append(json.loads(run(["docker","inspect",name,"--format","{{json .}}"])))
        except (RuntimeError, ValueError):
            continue
    return result


def profile(container):
    base=["docker","exec","-u","www-data",container,"wp",
          "--path=/var/www/html","--skip-plugins","--skip-themes","option","get"]
    row=json.loads(run(base+["mad4b_scp_site_profile_v2","--format=json"],20))
    row["_siteurl"]=run(base+["siteurl"],20)
    return row


def resolve(company, environment, site_uuid, origin, *, service_uuid="",
            discover=containers, read_profile=profile):
    """Find exactly one healthy Coolify PHP service matching original site identity."""
    if not re.fullmatch(r"[a-z][a-z0-9-]{2,47}",company):
        raise ValueError("INVALID_COMPANY")
    if environment not in ("staging","production","development"):
        raise ValueError("INVALID_ENVIRONMENT")
    if str(UUID(site_uuid))!=site_uuid.lower():
        raise ValueError("INVALID_UUID")
    u=urlsplit(origin)
    if (u.scheme!="https" or not u.hostname or u.username or u.password
        or u.port or u.query or u.fragment or u.path not in ("","/")):
        raise ValueError("INVALID_ORIGIN")
    if service_uuid and not re.fullmatch(r"[a-z0-9]{20,64}",service_uuid):
        raise ValueError("INVALID_RESOURCE")
    matches=[]
    candidates=discover()
    if len(candidates)>80:
        return {"state":"BLOCKED","reason":"DISCOVERY_OVERFLOW"}
    for info in candidates:
        if not isinstance(info,dict):
            continue
        labels=(info.get("Config") or {}).get("Labels") or {}
        resource=labels.get("coolify.serviceUuid","")
        state=info.get("State") or {}
        health=(state.get("Health") or {}).get("Status")
        if (state.get("Running") is not True
            or (health is not None and health != "healthy")
            or labels.get("coolify.managed")!="true"
            or labels.get("coolify.type")!="service"
            or labels.get("com.docker.compose.service")!="php"
            or labels.get("coolify.environmentName")!=environment
            or not isinstance(resource,str)
            or not re.fullmatch(r"[a-z0-9]{20,64}",resource)
            or labels.get("com.docker.compose.project")!=resource
            or (service_uuid and service_uuid!=resource)):
            continue
        name=str(info.get("Name","")).lstrip("/")
        if not VALID_NAME.fullmatch(name):
            continue
        try:
            p=read_profile(name)
        except (RuntimeError,ValueError,TypeError,subprocess.TimeoutExpired):
            continue
        if (isinstance(p,dict) and p.get("contract")=="mad4b.site-profile.v2"
            and p.get("site_uuid")==site_uuid and p.get("environment")==environment
            and p.get("canonical_origin")==origin and p.get("_siteurl")==origin):
            container_id=info.get("Id","")
            if not isinstance(container_id,str) or not re.fullmatch(r"[a-f0-9]{64}",container_id):
                continue
            matches.append((name,resource,container_id))
    if len(matches)!=1:
        return {"state":"BLOCKED","reason":
            "NO_MATCHING_LIVE_COOLIFY_WORDPRESS" if not matches
            else "AMBIGUOUS_MATCHING_LIVE_RUNTIMES"}
    name,resource,container_id=matches[0]
    return {"state":"TARGET_OBSERVED","target_container":name,
            "coolify_service_uuid":resource,"container_id":container_id,
            "profile_verified":True,
            "discovered_dynamically":True,"read_only":True,
            "native_write_authorized":False,"native_313_verified":False}


def database_containers():
    """Find Docker resources labeled as Coolify DB services, not by name."""
    names=run(["docker","ps","--filter","label=coolify.managed=true",
               "--filter","label=com.docker.compose.service=db",
               "--format","{{.Names}}"]).splitlines()
    if len(names)>80:
        raise RuntimeError("DATABASE_DISCOVERY_OVERFLOW")
    rows=[]
    for name in names:
        if not VALID_NAME.fullmatch(name):
            continue
        try:
            rows.append(json.loads(run(["docker","inspect",name,"--format","{{json .}}"])))
        except (RuntimeError,ValueError):
            continue
    return rows


def resolve_database(service_uuid, environment, *, discover=database_containers):
    """Resolve exactly one current DB from the same already-verified Coolify service."""
    if not re.fullmatch(r"[a-z0-9]{20,64}",service_uuid):
        raise ValueError("COOLIFY_RESOURCE_INVALID")
    if environment not in ("staging","production","development"):
        raise ValueError("ENVIRONMENT_INVALID")
    rows=discover()
    if len(rows)>80:
        return {"database_state":"BLOCKED","database_reason":"DATABASE_DISCOVERY_OVERFLOW"}
    found=[]
    for info in rows:
        if not isinstance(info,dict):
            continue
        state=info.get("State") or {}
        labels=(info.get("Config") or {}).get("Labels") or {}
        if (state.get("Running") is not True
            or labels.get("coolify.managed")!="true"
            or labels.get("coolify.type")!="service"
            or labels.get("com.docker.compose.service")!="db"
            or labels.get("coolify.environmentName")!=environment
            or labels.get("coolify.serviceUuid")!=service_uuid
            or labels.get("com.docker.compose.project")!=service_uuid):
            continue
        health=(state.get("Health") or {}).get("Status")
        if health is not None and health!="healthy":
            continue
        name=str(info.get("Name","")).lstrip("/")
        container_id=info.get("Id","")
        if VALID_NAME.fullmatch(name) and isinstance(container_id,str) and re.fullmatch(r"[a-f0-9]{64}",container_id):
            found.append((name,container_id))
    if len(found)!=1:
        return {"database_state":"BLOCKED","database_reason":
            "DATABASE_NOT_FOUND" if not found else "AMBIGUOUS_DATABASES"}
    return {"database_state":"DATABASE_OBSERVED",
            "database_container":found[0][0],"database_container_id":found[0][1],
            "database_service_uuid":service_uuid,
            "database_write_authorized":False,"read_only":True}
