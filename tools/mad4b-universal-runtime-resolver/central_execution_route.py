"""Current, read-only central Host route health; no historical receipt dependency."""
import json
from pathlib import Path
import stat
import subprocess

ROOT=Path("/opt/mad4b-company-transport")
POLICY=ROOT/"native-operator-v1/host-authority.json"
OPERATOR=ROOT/"vps-native-operator.py"
TARGET="tours-central:staging"
ORIGIN="https://tours.dev.mad4b.com"
UUID="e5e64bca-ed62-48d2-b2b3-dff693cd702a"
DATABASE="etg_commerce_staging"
PHP=('echo wp_json_encode(array("site_uuid"=>MAD4B_SCP_Site_Profile::site_uuid(),'
     '"origin"=>rtrim(home_url(),"/"),'
     '"environment"=>MAD4B_SCP_Site_Profile::current_environment(),'
     '"wordpress_environment"=>wp_get_environment_type(),'
     '"database"=>DB_NAME));')
READ_SITE=("docker","exec","-u","www-data","etg-commerce-staging-php","wp",
           "eval",PHP,"--path=/var/www/html","--skip-themes")

def secure_data_file(path):
    st=path.lstat()
    if not stat.S_ISREG(st.st_mode) or st.st_uid!=0 or st.st_mode & 0o077:
        raise ValueError("root_owned_private_policy_required")
    return path.read_bytes()

def original_operator_present():
    st=OPERATOR.lstat()
    return (stat.S_ISREG(st.st_mode) and st.st_uid==0
            and not st.st_mode & 0o022 and bool(st.st_mode & 0o100))

def read_live_site():
    p=subprocess.run(READ_SITE,check=False,capture_output=True,text=True,timeout=30)
    if p.returncode!=0:
        raise RuntimeError("readback_command_failed")
    out=json.loads(p.stdout.strip())
    if not isinstance(out,dict):raise ValueError("readback_invalid")
    return out

def check(scope):
    if (scope.get("site_uuid")!=UUID
            or scope.get("canonical_origin")!=ORIGIN
            or scope.get("environment")!="staging"
            or scope.get("central_execution_adapter")!="root_owned_host_operator_v1"):
        return ("review_required","central_host_site_identity_scope_mismatch",{})
    try:
        native=json.loads(secure_data_file(POLICY))
        record=native["scopes"][TARGET]
        operator_ok=original_operator_present()
    except (OSError,ValueError,KeyError,TypeError):
        return ("awaiting_provider","original_host_scope_unavailable",{})
    if (native.get("contract")!="mad4b.native-vps-operator.v1"
            or native.get("revoked") is not False
            or native.get("allow_general_shell") is not True
            or not operator_ok or record.get("allow_operator") is not True
            or record.get("allow_mutation") is not True
            or record.get("allow_production_mutation") is not False
            or record.get("site_uuid")!=UUID
            or record.get("canonical_origin")!=ORIGIN
            or record.get("site_environment")!="staging"):
        return ("blocked_authority","central_original_host_scope_not_authorized",{})
    try:
        observed=read_live_site()
    except (OSError,ValueError,RuntimeError,subprocess.TimeoutExpired):
        return ("retry","central_wpcli_readback_unavailable",{})
    if (observed.get("site_uuid")!=UUID
            or observed.get("origin")!=ORIGIN
            or observed.get("environment")!="staging"
            or observed.get("wordpress_environment")!="staging"
            or observed.get("database")!=DATABASE):
        return ("review_required","live_central_site_identity_mismatch",{})
    return ("verified","live_host_scope_and_wpcli_site_verified",{
        "site_uuid":UUID,
        "selected_lane":"local_root_ssh_host_operator",
        "fresh_site_readback":True,
        "historic_receipt_is_authority":False,
        "native_owner_plan_is_not_central_blocker":True,
        "new_operation_requires_its_own_host_preflight":True,
        "wordpress_grants_created":False,
        "wordpress_queue_shell_granted":False,
        "operation_executed":False,
        "production_mutation":False})
