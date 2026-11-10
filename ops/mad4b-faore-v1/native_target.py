"""Read-only exact target preflight for original Native Executor / 313.

Inspects an existing host-owned target binding. It cannot issue grants,
register a company, start a container, run WP-CLI, or execute a job.
"""
from pathlib import Path
import argparse
import json
import re
import subprocess
from typing import Callable
from urllib.parse import urlsplit
from uuid import UUID
from dynamic_runtime import resolve as dynamic_resolve, resolve_database

MANIFEST = Path("/opt/mad4b-company-transport/native-operator-v1/target-runtime-bindings.v1.json")
CONTRACT = "mad4b.company-native-target-runtime-bindings.v1"
IDENTIFIER = re.compile(r"^[a-z][a-z0-9-]{2,47}$")
CONTAINER = re.compile(r"^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}$")


def inspect(company: str, environment: str, site_uuid: str, origin: str,
            *, binding_path: Path = MANIFEST,
            resolve_runtime: Callable = dynamic_resolve,
            resolve_db: Callable = resolve_database) -> dict:
    if IDENTIFIER.fullmatch(company) is None or environment not in ("staging","production"):
        raise ValueError("COMPANY_ENVIRONMENT_INVALID")
    try:
        if str(UUID(site_uuid)) != site_uuid.lower():
            raise ValueError()
    except (ValueError, TypeError, AttributeError):
        raise ValueError("SITE_UUID_INVALID")
    parsed = urlsplit(origin)
    if parsed.scheme != "https" or not parsed.hostname or parsed.username or parsed.password:
        raise ValueError("ORIGIN_INVALID")
    path = Path(binding_path)
    if path.is_symlink() or not path.is_file() or path.stat().st_size > 65536:
        raise ValueError("NATIVE_BINDING_FILE_INVALID")
    config = json.loads(path.read_text())
    if not isinstance(config,dict) or config.get("contract") != CONTRACT:
        raise ValueError("NATIVE_BINDING_CONTRACT_INVALID")
    target = config.get("targets",{}).get(company+":"+environment)
    output = {
        "contract":"mad4b.faore.native-target-preflight.v1",
        "company_id":company,"environment":environment,
        "site_uuid":site_uuid,"read_only":True,
        "write_authorized":False,"execution_performed":False,
        "native_313_verified":False,
    }
    if not isinstance(target,dict):
        return {**output,"state":"BLOCKED","reason":"TARGET_NOT_REGISTERED"}
    if target.get("site_uuid")!=site_uuid or target.get("canonical_origin")!=origin:
        return {**output,"state":"BLOCKED","reason":"TARGET_BINDING_MISMATCH"}
    # The original static container name is historical metadata only.
    # The default adapter re-discovers Coolify PHP and matches live WordPress
    # Site Profile UUID/environment/origin on every preflight.
    observed = resolve_runtime(company,environment,site_uuid,origin,
        service_uuid=target.get("coolify_service_uuid",""))
    if observed.get("state")!="TARGET_OBSERVED":
        return {**output,**observed}
    database = resolve_db(observed["coolify_service_uuid"], environment)
    return {**output,**observed,**database,
        "reason":"FURTHER_NATIVE_ADMISSION_REQUIRED",
        "write_authorized":False,"native_313_verified":False}



def main() -> None:
    p=argparse.ArgumentParser(description="Read-only original Native target check")
    p.add_argument("--company",required=True)
    p.add_argument("--environment",required=True)
    p.add_argument("--site-uuid",required=True)
    p.add_argument("--origin",required=True)
    a=p.parse_args()
    print(json.dumps(inspect(a.company,a.environment,a.site_uuid,a.origin),
                     sort_keys=True))


if __name__=="__main__":
    main()
