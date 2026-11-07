"""FAORE read-only, real WordPress + original host Broker integration preflight.

Only fixed WP-CLI option reads and Docker inspect are permitted here. This
module never creates 305, claims 306, evaluates 312, or executes 313.
"""
from __future__ import annotations
import json
import subprocess
from typing import Callable
from kernel import FCPError
from broker_journal import observe
from native_target import inspect

CONTAINER = "etg-commerce-staging-php"
WP = ["/usr/bin/docker", "exec", "-u", "www-data", CONTAINER,
      "wp", "--path=/var/www/html", "--skip-themes", "option", "get"]
REGISTRY_OPTION = "mad4b_tourism_company_site_registry_v1"
PROFILE_OPTION = "mad4b_scp_site_profile_v2"


def read_option(option: str) -> dict:
    if option not in (REGISTRY_OPTION, PROFILE_OPTION):
        raise FCPError("WP_OPTION_NOT_ALLOWED")
    proc = subprocess.run(
        WP + [option, "--format=json"], capture_output=True, text=True,
        check=False, timeout=40,
    )
    if proc.returncode or len(proc.stdout)>2_000_000:
        raise FCPError("WORDPRESS_OPTION_UNAVAILABLE")
    try:
        value=json.loads(proc.stdout)
    except json.JSONDecodeError:
        raise FCPError("WORDPRESS_OPTION_MALFORMED")
    if not isinstance(value,dict):
        raise FCPError("WORDPRESS_OPTION_MALFORMED")
    return value


def check_company(company: str, get_option: Callable[[str],dict] = read_option,
                  native: Callable = inspect, broker: Callable = observe) -> dict:
    profile=get_option(PROFILE_OPTION)
    registry=get_option(REGISTRY_OPTION)
    result={
        "contract":"mad4b.faore.live-integration-preflight.v1",
        "company_id":company,"read_only":True,"mutation_performed":False,
        "new_grants_issued":False,"stage_313_verified":False,
        "checks":[],
    }
    if (profile.get("contract")!="mad4b.site-profile.v2"
        or profile.get("environment")!="staging"
        or profile.get("canonical_origin")!="https://tours.dev.mad4b.com"):
        result["checks"].append("CENTRAL_SITE_PROFILE_MISMATCH")
        result["state"]="BLOCKED"
        return result
    result["central_site_uuid"]=profile.get("site_uuid","")
    row=registry.get(company)
    if (not isinstance(row,dict) or row.get("company_code")!=company
        or row.get("host_binding_verified") is not True):
        result["checks"].append("COMPANY_REGISTRY_BINDING_UNVERIFIED")
        result["state"]="BLOCKED"
        return result
    environment=row.get("environment")
    uuid=row.get("company_site_uuid")
    origin=row.get("canonical_origin")
    if not all(isinstance(v,str) for v in (environment,uuid,origin)):
        result["checks"].append("COMPANY_REGISTRY_SCOPE_INVALID")
        result["state"]="BLOCKED"
        return result
    result["company_environment"]=environment
    result["company_site_uuid"]=uuid
    original=native(company,environment,uuid,origin)
    result["native_target_state"]=original["state"]
    result["native_target_reason"]=original["reason"]
    result["native_runtime_discovery"]=("dynamic_coolify_site_profile"
        if original.get("discovered_dynamically") is True else "not_verified")
    result["coolify_service_uuid"]=original.get("coolify_service_uuid","")
    result["native_database_state"]=original.get("database_state","UNVERIFIED")
    journal=broker(company)
    result["broker_event_count"]=journal["event_count"]
    result["broker_evidence_state"]=journal["evidence_state"]
    if original["state"]!="TARGET_OBSERVED":
        result["checks"].append(original["reason"])
    if original.get("database_state","UNVERIFIED") != "DATABASE_OBSERVED":
        result["checks"].append("NATIVE_DATABASE_TARGET_UNVERIFIED")
    if journal["evidence_state"]!="Observed":
        result["checks"].append("ORIGINAL_BROKER_EVENT_UNVERIFIED")
    result["checks"].append("NATIVE_313_INDEPENDENT_READBACK_REQUIRED")
    result["state"]="BLOCKED"
    return result


def main():
    import argparse
    parser=argparse.ArgumentParser()
    parser.add_argument("--company",required=True)
    args=parser.parse_args()
    print(json.dumps(check_company(args.company),sort_keys=True))


if __name__=="__main__":
    main()
