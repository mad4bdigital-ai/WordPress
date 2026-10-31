"""Read-only central Host Operator route observation, independent of Native Owner.

Only the original root-owned host authority and a prior exact successful
diagnostic receipt prove this administrative transport exists. A receipt does
not authorize a *new* mutation or grant any WordPress ability.
"""
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import re
import stat

ROOT=Path("/opt/mad4b-company-transport")
POLICY=ROOT/"native-operator-v1/host-authority.json"
RECEIPT=ROOT/"native-operator-v1/receipts/tours-central-direct-authority-20261031-check1.json"
COMMAND=ROOT/"native-operator-v1/central-direct-authority-canary.cmd"
READBACK=ROOT/"native-operator-v1/central-direct-authority-readback.cmd"
TARGET="tours-central:staging"
ORIGIN="https://tours.dev.mad4b.com"
MAX_RECEIPT_AGE_SECONDS=86400

def secure_data_file(path):
    st=path.lstat()
    if not stat.S_ISREG(st.st_mode) or st.st_uid!=0 or st.st_mode&0o077:
        raise ValueError("root_owned_exact_file_required")
    return path.read_bytes()

def check(scope):
    if scope.get("site_uuid")!="e5e64bca-ed62-48d2-b2b3-dff693cd702a" or \
       scope.get("environment")!="staging" or \
       scope.get("canonical_origin")!=ORIGIN or \
       scope.get("central_execution_adapter")!="root_owned_host_operator_v1":
        return ("review_required","central_host_site_identity_scope_mismatch",{})
    try:
        native=json.loads(secure_data_file(POLICY))
        record=native["scopes"][TARGET]
    except (OSError,ValueError,TypeError,KeyError):
        return ("awaiting_provider","original_host_scope_unavailable",{})
    if (native.get("contract")!="mad4b.native-vps-operator.v1"
        or native.get("revoked") is not False
        or native.get("allow_general_shell") is not True
        or record.get("allow_operator") is not True
        or record.get("allow_mutation") is not True
        or record.get("site_uuid")!=scope["site_uuid"]
        or record.get("canonical_origin")!=scope["canonical_origin"]
        or record.get("site_environment")!="staging"):
        return ("blocked_authority","central_original_host_scope_not_authorized",{})
    try:
        receipt=json.loads(secure_data_file(RECEIPT))
        command_sha=hashlib.sha256(secure_data_file(COMMAND)).hexdigest()
        readback_command_sha=hashlib.sha256(secure_data_file(READBACK)).hexdigest()
        at=dt.datetime.fromisoformat(receipt["finished_at"])
        age=(dt.datetime.now(dt.timezone.utc)-at).total_seconds()
    except (OSError,ValueError,KeyError,TypeError):
        return ("awaiting_provider","root_owned_host_receipt_not_available",{})
    if (receipt.get("contract")!="mad4b.native-vps-operator.v1"
        or receipt.get("state")!="VERIFIED"
        or receipt.get("company")!="tours-central"
        or receipt.get("environment")!="staging"
        or receipt.get("lane")!="vps_ssh"
        or receipt.get("intent")!="diagnostic"
        or receipt.get("kind")!="shell"
        or receipt.get("execution_performed") is not True
        or receipt.get("mutating") is not False
        or receipt.get("mutation_performed") is not False
        or receipt.get("readback_matches_expected") is not True
        or receipt.get("production_mutation_authorized") is not False
        or receipt.get("command_sha256")!=command_sha
        or receipt.get("readback_command_sha256")!=readback_command_sha
        or receipt.get("readback_sha256")!=receipt.get("expected_readback_sha256")
        or not isinstance(receipt.get("readback_sha256"),str)
        or not re.fullmatch(r"[a-f0-9]{64}",receipt["readback_sha256"])):
        return ("review_required","original_host_receipt_or_scope_conflict",{})
    if age<0 or age>MAX_RECEIPT_AGE_SECONDS:
        return ("awaiting_provider","previous_host_receipt_expired",{})
    return ("verified","central_native_host_route_independently_available",{
        "site_uuid":scope["site_uuid"],
        "selected_lane":"local_root_ssh_host_operator",
        "native_owner_is_optional_for_host_maintenance":True,
        "native_owner_plan_is_not_central_blocker":True,
        "readback_receipt_sha256":receipt["readback_sha256"],
        "new_operation_requires_its_own_host_preflight":True,
        "wordpress_grants_created":False,
        "production_mutation":False,
        "readback_only":True})
