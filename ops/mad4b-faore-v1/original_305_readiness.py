"""Bounded, redacted Original 305 readiness using existing active company grants.

This module never issues or renews grants, logs in as an owner, admits a
command, signs a claim, creates a Broker job, or certifies Original 313.
"""
from __future__ import annotations
import argparse
import json
import re
import subprocess
import sys
import time
from typing import Callable

from stdio_scope import central_target

COMPANY = re.compile(r"^[a-z][a-z0-9-]{2,47}$")
SCRIPT = r"""
$db=$GLOBALS['wpdb'];
$prefix=MAD4B_Central_SSH_Command_Intake_V1::TRANSPORT_PREFIX;
$get_names=static function($key) use($db) {
 $q=$db->prepare("SELECT option_name FROM {$db->options} WHERE option_name LIKE %s LIMIT 151",
     $db->esc_like($key).'%');
 return $db->get_col($q);
};
$transport_names=$get_names($prefix);
$request_names=$get_names(MAD4B_Central_SSH_Command_Intake_V1::REQUEST_PREFIX);
if(count($transport_names)>150 || count($request_names)>150) {
 echo wp_json_encode(['state'=>'ORIGINAL_305_INVENTORY_UNBOUNDED']);return;
}
$target=class_exists('MAD4B_Manager_Auto_Write_V1',false)
 ? MAD4B_Manager_Auto_Write_V1::company_target($company):[];
$target_ready=is_array($target) && ($target['state']??'')==='REGISTERED'
 && !empty($target['site_uuid']) && !empty($target['canonical_origin'])
 && in_array(($target['environment']??''),['staging','production'],true);
$transports=0;$ready_pairs=0;$old_requests=0;$current_requests=0;
foreach($transport_names as $name) {
 $t=get_option($name,false);
 if(!is_array($t)||($t['company']??'')!==$company)continue;
 $transports++;
 $grant_id=$t['full_wpcli_grant_id']??null;
 if(!is_string($grant_id)||!preg_match('/^[a-f0-9]{32}$/D',$grant_id))continue;
 $g=get_option(MAD4B_Central_SSH_Command_Intake_V1::PREFIX.$grant_id,false);
 if(!is_array($g)||($g['company']??'')!==$company
     ||($g['operation']??'')!=='company.remote.wpcli.full'
     ||($g['state']??'')!=='CENTRAL_OPERATION_SCOPE_GRANTED'
     ||($g['grant_mode']??'')!=='persistent')continue;
 if(!$target_ready || ($t['site_uuid']??'')!==$target['site_uuid']
    ||($t['canonical_origin']??'')!==$target['canonical_origin']
    ||($t['environment']??'')!==$target['environment']
    ||($g['site_uuid']??'')!==$target['site_uuid']
    ||($g['canonical_origin']??'')!==$target['canonical_origin']
    ||($g['environment']??'')!==$target['environment'])continue;
 if(!class_exists('MAD4B_Original_Verified_WPCLI_Transport_V1',false)
    ||!MAD4B_Original_Verified_WPCLI_Transport_V1::active($t,$g))continue;
 $ready_pairs++;
}
foreach($request_names as $name) {
 $r=get_option($name,false);
 if(!is_array($r)||($r['company']??'')!==$company
   ||($r['operation']??'')!=='company.remote.wpcli.full')continue;
 if((int)($r['expires_epoch']??0)>time()
    && ($r['status']??'')==='ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY')
   $current_requests++;
 else $old_requests++;
}
echo wp_json_encode([
 'contract'=>'mad4b.faore.original-305-readiness.v1',
 'company'=>$company,
 'central_site_profile_bound'=>class_exists('MAD4B_SCP_Site_Profile',false)
   && (MAD4B_SCP_Site_Profile::status()['configured']??false)===true,
 'company_target_registered'=>$target_ready,
 'matching_transports_seen'=>$transports,
 'verified_grant_transport_pairs'=>$ready_pairs,
 'current_unexpired_request_count'=>$current_requests,
 'previous_or_expired_request_count'=>$old_requests,
 'transport_and_grant_ready'=>$target_ready&&$ready_pairs===1,
 'ambiguous_verified_pairs'=>$ready_pairs>1,
 'new_authenticated_305_request_required'=>$target_ready&&$ready_pairs===1&&$current_requests===0,
 'authenticated_owner_session_proved'=>false,
 'job_created'=>false,'grant_issued'=>false,'original_313_certified'=>false,
 'mutation_performed'=>false
]);
"""


def observe(company: str, *, discover: Callable = central_target,
            runner: Callable = subprocess.run, sleeper: Callable = time.sleep) -> dict:
    if not isinstance(company, str) or not COMPANY.fullmatch(company):
        raise ValueError("ORIGINAL_305_COMPANY_INVALID")
    target = discover("staging")
    if target["environment"] != "staging":
        raise RuntimeError("ORIGINAL_305_CENTRAL_ENVIRONMENT_INVALID")
    argv = [
        "/usr/bin/docker", "exec", "-u", "www-data", target["container"],
        "/usr/local/bin/wp", "--path=/var/www/html", "--skip-themes",
        "eval", "$company=" + json.dumps(company) + ";" + SCRIPT,
    ]
    for attempt in range(2):
        p = runner(argv, check=False, capture_output=True, text=True, timeout=45)
        if p.returncode == 0:
            break
        if attempt or "STAGING_WPCLI_BUSY" not in ((p.stderr or "")+(p.stdout or "")):
            raise RuntimeError("ORIGINAL_305_READBACK_UNAVAILABLE")
        sleeper(3)
    if len(p.stdout) > 16000:
        raise RuntimeError("ORIGINAL_305_READBACK_UNBOUNDED")
    try:
        data = json.loads(p.stdout.strip().splitlines()[-1])
    except (ValueError, TypeError, IndexError):
        raise RuntimeError("ORIGINAL_305_JSON_INVALID")
    if not isinstance(data, dict) or data.get("contract") != "mad4b.faore.original-305-readiness.v1":
        raise RuntimeError("ORIGINAL_305_CONTRACT_NOT_VERIFIED")
    if data.get("company") != company:
        raise RuntimeError("ORIGINAL_305_COMPANY_MISMATCH")
    if any(data.get(key) is not False for key in
           ("authenticated_owner_session_proved", "job_created", "grant_issued",
            "original_313_certified", "mutation_performed")):
        raise RuntimeError("ORIGINAL_305_READBACK_CLAIMED_UNAUTHORIZED_EFFECT")
    for key in ("verified_grant_transport_pairs", "current_unexpired_request_count",
                "previous_or_expired_request_count", "matching_transports_seen"):
        if type(data.get(key)) is not int or data[key] < 0 or data[key] > 150:
            raise RuntimeError("ORIGINAL_305_COUNTS_INVALID")
    for key in ("central_site_profile_bound", "company_target_registered",
                "transport_and_grant_ready", "ambiguous_verified_pairs",
                "new_authenticated_305_request_required"):
        if type(data.get(key)) is not bool:
            raise RuntimeError("ORIGINAL_305_STATUS_INVALID")
    ready = (data["company_target_registered"]
             and data["verified_grant_transport_pairs"] == 1)
    if (data["transport_and_grant_ready"] != ready
            or data["ambiguous_verified_pairs"] !=
                (data["verified_grant_transport_pairs"] > 1)
            or data["new_authenticated_305_request_required"] !=
                (ready and data["current_unexpired_request_count"] == 0)):
        raise RuntimeError("ORIGINAL_305_STATUS_INCONSISTENT")
    return data


if __name__ == "__main__":
    a = argparse.ArgumentParser()
    a.add_argument("--company", required=True)
    args = a.parse_args()
    try:
        print(json.dumps(observe(args.company), sort_keys=True))
    except (RuntimeError, ValueError, OSError, subprocess.TimeoutExpired) as exc:
        print(json.dumps({"state": "ORIGINAL_305_READINESS_UNAVAILABLE",
                          "reason": str(exc)[:100], "job_created": False,
                          "grant_issued": False, "mutation_performed": False,
                          "original_313_certified": False}, sort_keys=True))
        sys.exit(2)
