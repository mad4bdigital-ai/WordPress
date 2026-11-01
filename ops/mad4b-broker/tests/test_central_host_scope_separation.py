#!/usr/bin/env python3
"""Fail-closed source contracts for central host versus customer companies."""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
files = {
    n: (ROOT / "central-mu" / f"{n}-{suffix}.php").read_text()
    for n, suffix in {
        "305": "mad4b-central-ssh-command-intake",
        "306": "mad4b-central-vps-broker",
        "312": "mad4b-broker-exact-ssh-authority",
        "319": "mad4b-central-host-scope",
    }.items()
}
host = files["319"]
assert "'central_native_host'" in host
assert "'company_target'=>false" in host
assert "'CENTRAL_HOST_IDENTITY_VERIFIED'" in host
assert "MAD4B_SCP_Site_Profile::origin_enrolled()" in host
assert "MAD4B_SCP_Site_Profile::site_urls_match_enrollment()" in host
assert "MAD4B_SCP_Site_Profile::user_is_enrolled" in host
assert "'host_grant_issued'=>false" in host
for forbidden in ("add_option(", "update_option(", "delete_option(", "shell_exec(", "system(", "exec("):
    assert forbidden not in host, forbidden
intake = files["305"]
assert "$company === 'tours-central'" in intake
assert "MAD4B_Central_Host_Scope_V1::current_owner()" in intake
assert "central_host_exact_local_transport_required" in intake
assert "central_host_persistent_local_grant_required" in intake
assert "central_host_command_target_scope_invalid" in intake
assert "vault:registry/local-srv2030908" in intake
assert "(!class_exists('MAD4B_Company_Operation_Admission_V1'" in intake
broker = files["306"]
assert "MAD4B_Central_Host_Scope_V1::matches(" in broker
assert "central_host_binding_changed" in broker
assert "MAD4B_Manager_Auto_Write_V1::company_target($company)" in broker
preflight = files["312"]
assert "['hosting-1','hosting-2','hosting-3','srv2030908']" in preflight
assert "LOCAL_TARGET_SHELL_ONLY" in preflight
assert "MAD4B_Central_Host_Scope_V1::matches(" in preflight
assert "ORIGINAL_LOCAL_GRANT_CLAIM_MISSING" in preflight
assert "'company.remote.wpcli.full'" in preflight
assert "BROKER_SIGNED_PREFLIGHT_REQUIRED" in preflight
native=(ROOT / "central-mu" / "320-mad4b-central-native-auto-intake.php").read_text()
assert "public static function original_auth(" in native
assert "MAD4B_Central_SSH_Command_Intake_V1::permitted($request)" in native
assert "MAD4B_Central_Host_Scope_V1::current_owner()" in native
assert "MAD4B_Central_SSH_Command_Intake_V1::accept_command(" in native
assert "MAD4B_Central_VPS_Broker_V1::dispatch(" in native
assert "AWAITING_ORIGINAL_OWNER_STANDING_GRANT" in native
assert "array_key_exists('expires_epoch',$g)" in native
assert "$g['expires_epoch']!==null" in native
assert "'operation']??'')!=='maintenance.shell'" in native
assert "vault:registry/local-srv2030908" in native
assert "'target_scope'=>'host'" in native
assert "correlation_conflict" in native
assert "command_bytes_persisted" not in native
for forbidden in ("shell_exec(", "system(", "exec(", "add_option(", "update_option(", "delete_option("):
    assert forbidden not in native, f"auto-intake host mutation forbidden: {forbidden}"
print("PASS central host/company separation, exact local enrollment, authenticated auto-dispatch, original grants and remote compatibility")
