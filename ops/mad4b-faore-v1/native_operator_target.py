"""Adapter for existing host Native Operator: exact scoped dynamic SQL target.

Root-owned registry supplies identity, Coolify supplies mutable container
instance names. This adapter cannot grant an operation or execute SQL.
"""
from pathlib import Path
import json
import stat
from typing import Callable
from dynamic_runtime import resolve, resolve_database

REGISTRY=Path("/opt/mad4b-company-transport/native-operator-v1/target-runtime-bindings.v1.json")
CONTRACT="mad4b.company-native-target-runtime-bindings.v1"


def target(company: str, environment: str, *,
           manifest: Path = REGISTRY,
           verify_runtime: Callable = resolve,
           verify_database: Callable = resolve_database) -> dict:
    path=Path(manifest)
    if path.is_symlink() or not path.is_file():
        raise ValueError("HOST_REGISTRY_UNSAFE")
    info=path.stat()
    if not stat.S_ISREG(info.st_mode) or info.st_uid!=0 or info.st_mode&0o077:
        raise ValueError("HOST_REGISTRY_PERMISSIONS_INVALID")
    if info.st_size>65536 or info.st_size<1:
        raise ValueError("HOST_REGISTRY_SIZE_INVALID")
    registry=json.loads(path.read_text())
    if not isinstance(registry,dict) or registry.get("contract")!=CONTRACT:
        raise ValueError("HOST_REGISTRY_CONTRACT_INVALID")
    entry=(registry.get("targets") or {}).get(company+":"+environment)
    if not isinstance(entry,dict):
        raise ValueError("EXACT_COMPANY_TARGET_NOT_ENROLLED")
    uuid=entry.get("site_uuid")
    origin=entry.get("canonical_origin")
    if not isinstance(uuid,str) or not isinstance(origin,str):
        raise ValueError("EXACT_COMPANY_IDENTITY_UNAVAILABLE")
    observed=verify_runtime(company,environment,uuid,origin,
                            service_uuid=entry.get("coolify_service_uuid",""))
    if observed.get("state")!="TARGET_OBSERVED" or not observed.get("profile_verified"):
        raise ValueError("RUNTIME_SITE_IDENTITY_NOT_VERIFIED")
    resource=observed.get("coolify_service_uuid","")
    database=verify_database(resource,environment)
    if database.get("database_state")!="DATABASE_OBSERVED":
        raise ValueError("COOLIFY_DATABASE_BINDING_NOT_VERIFIED")
    return {
        "contract":"mad4b.faore.native-operator-dynamic-target.v1",
        "company_id":company,"environment":environment,
        "site_uuid":uuid,"canonical_origin":origin,
        "coolify_service_uuid":resource,
        "target_container":observed["target_container"],
        "database_container":database["database_container"],
        "target_container_id":observed.get("container_id",""),
        "database_container_id":database.get("database_container_id",""),
        "read_only":True,"authorization_created":False,
        "native_execution_performed":False
    }
