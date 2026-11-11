#!/usr/bin/env python3
"""Scope-bound, same-VPS WordPress CLI readback to central service catalog.

No HTTP credentials, SSH trust changes, signing keys, grants, customer records,
remote company modifications or Original 313 receipt creation.
"""
import argparse
import base64
import datetime as dt
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import subprocess
import sys
import time

CONTRACT = "mad4b.company.service.native-wpcli-observation.v1"
SITE_CONTRACT = "mad4b.service-activation-live-readback.v1"
OPTION_PREFIX = "mad4b_company_service_native_wpcli_readback_v1_"
ALLOWED_COMPANY = "wayfatours"
CONTAINER_PATTERN = re.compile(r"^php-[a-z0-9]{15,40}$")
SHA256 = re.compile(r"^[0-9a-f]{64}$")
CHALLENGE = re.compile(r"^[0-9a-f]{32}$")
MAX_SNAPSHOT_BYTES = 24000
EXPIRES_IN = 360


class Refused(RuntimeError):
    pass


def limited_eval(container: str, code: str) -> str:
    if not CONTAINER_PATTERN.fullmatch(container):
        raise Refused("unregistered_php_container")
    args = [
        "docker", "exec", "-u", "www-data", container,
        "php", "/usr/local/bin/wp", "--path=/var/www/html", "eval", code
    ]
    run = subprocess.run(args, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                         text=True, timeout=35, check=False)
    if run.returncode != 0:
        raise Refused("wpcli_readback_unavailable")
    text = run.stdout.strip()
    if not text or len(text.encode("utf-8")) > MAX_SNAPSHOT_BYTES:
        raise Refused("wpcli_readback_size_invalid")
    return text


def json_dict(payload: str) -> dict:
    try:
        row = json.loads(payload)
    except (json.JSONDecodeError, UnicodeDecodeError):
        raise Refused("wpcli_non_json_result")
    if not isinstance(row, dict):
        raise Refused("wpcli_non_object_result")
    return row


def timestamp(value: str) -> int:
    try:
        return int(dt.datetime.fromisoformat(value.replace("Z", "+00:00")).timestamp())
    except (ValueError, TypeError, AttributeError):
        raise Refused("invalid_wordpress_utc")


def validate_config(cfg: dict) -> dict:
    needed = {"contract","company","site_uuid","origin",
              "central_origin","center_container","tenant_container"}
    if set(cfg) != needed or cfg["contract"] != CONTRACT:
        raise Refused("invalid_host_scope")
    if cfg["company"] != ALLOWED_COMPANY or not re.fullmatch(
        r"[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}", cfg["site_uuid"]
    ):
        raise Refused("company_site_scope_invalid")
    if cfg["origin"] != "https://wayfatours.com" or cfg["central_origin"] != "https://tours.dev.mad4b.com":
        raise Refused("site_origin_mismatch")
    for name in ("center_container","tenant_container"):
        if not isinstance(cfg[name], str) or not CONTAINER_PATTERN.fullmatch(cfg[name]):
            raise Refused("container_scope_invalid")
    if cfg["center_container"] == cfg["tenant_container"]:
        raise Refused("cross_site_container_collision")
    return cfg


def central_preflight(cfg: dict) -> dict:
    code = json.dumps(cfg["company"])
    php = r'''
$code=__COMPANY__;
if(!class_exists("MAD4B_Company_Service_Catalog_V1",false)
 ||!MAD4B_Company_Service_Catalog_V1::observation_enabled($code)
 ||!class_exists("MAD4B_Company_Environment_Registry_V2",false)){
 echo wp_json_encode(["eligible"=>false,"reason"=>"central_catalog_or_scope_missing"]);return;
}
$b=MAD4B_Company_Portfolio_Manager_V1::company_binding($code);
$t=MAD4B_Company_Environment_Registry_V2::target($code,"production");
if(is_wp_error($b)||!is_array($b)||!is_array($t)){
 echo wp_json_encode(["eligible"=>false,"reason"=>"registered_binding_missing"]);return;
}
echo wp_json_encode([
 "eligible"=>true,
 "center_origin"=>untrailingslashit(home_url("/")),
 "central_environment"=>wp_get_environment_type(),
 "company"=>$code,
 "binding_sha256"=>$b["binding_sha256"]??null,
 "source_readback_module_sha256"=>hash_file("sha256",WP_CONTENT_DIR."/mu-plugins/mad4b-service-activation-live-readback-v1.php"),
 "source_schema_fields"=>MAD4B_Service_Activation_Live_Readback_V1::snapshot()["schema"]["declared_count"]??null,
 "site_uuid"=>$b["company_site_uuid"]??null,
 "registered_uuid"=>$t["site_uuid"]??null,
 "registered_origin"=>$t["canonical_origin"]??null,
 "registered_environment"=>$t["environment"]??null,
 "company_id"=>$t["company_id"]??null
],JSON_UNESCAPED_SLASHES);
'''.replace("__COMPANY__", code)
    row = json_dict(limited_eval(cfg["center_container"], php))
    if (row.get("eligible") is not True
        or row.get("center_origin") != cfg["central_origin"]
        or row.get("central_environment") != "staging"
        or row.get("company") != cfg["company"]
        or row.get("site_uuid") != cfg["site_uuid"]
        or row.get("registered_uuid") != cfg["site_uuid"]
        or row.get("registered_origin") != cfg["origin"]
        or row.get("registered_environment") != "production"
        or row.get("company_id") != cfg["company"]
        or not isinstance(row.get("binding_sha256"), str)
        or not SHA256.fullmatch(row["binding_sha256"])
        or not isinstance(row.get("source_readback_module_sha256"), str)
        or not SHA256.fullmatch(row["source_readback_module_sha256"])
        or not isinstance(row.get("source_schema_fields"), int)
        or row["source_schema_fields"] < 4):
        raise Refused("central_registry_binding_or_scope_invalid")
    return row


def tenant_read(cfg: dict, challenge: str) -> tuple[str, dict]:
    php = r'''
if(!class_exists("MAD4B_Service_Activation_Live_Readback_V1",false)
 ||!class_exists("MAD4B_SCP_Site_Profile",false)
 ||!MAD4B_SCP_Site_Profile::configured()){
 echo wp_json_encode(["readback_error"=>"site_native_state_unavailable"]);return;
}
echo wp_json_encode([
 "challenge"=>__CHALLENGE__,
 "site"=>untrailingslashit(home_url("/")),
 "site_uuid"=>MAD4B_SCP_Site_Profile::site_uuid(),
 "site_environment"=>wp_get_environment_type(),
 "readback_module_sha256"=>hash_file("sha256",WP_CONTENT_DIR."/mu-plugins/mad4b-service-activation-live-readback-v1.php"),
 "snapshot"=>MAD4B_Service_Activation_Live_Readback_V1::snapshot()
],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
'''.replace("__CHALLENGE__", json.dumps(challenge))
    raw = limited_eval(cfg["tenant_container"], php)
    row = json_dict(raw)
    snap = row.get("snapshot")
    if (set(row) != {"challenge","site","site_uuid","site_environment","readback_module_sha256","snapshot"}
        or row.get("challenge") != challenge
        or row.get("site") != cfg["origin"]
        or row.get("site_uuid") != cfg["site_uuid"]
        or row.get("site_environment") != "production"
        or not isinstance(snap, dict)
        or snap.get("contract") != SITE_CONTRACT
        or snap.get("site_host") != "wayfatours.com"
        or set(snap.get("families", {})) != {"tours","properties","transportations","visas"}
        or not isinstance(snap.get("schema", {}).get("declared_count"), int)
        or snap.get("schema", {}).get("declared_count") < 4
        or snap.get("schema", {}).get("runtime_schema_gaps") != []
        or not isinstance(row.get("readback_module_sha256"),str)
        or not SHA256.fullmatch(row["readback_module_sha256"])
        or snap.get("public_guard_loaded") is not True
        or snap.get("unified_fulfillment_loaded") is not True
        or not isinstance(snap.get("stripe_enabled"),bool)
        or not isinstance(snap.get("early_payment_benefits_enabled"),bool)):
        raise Refused("wayfa_native_runtime_identity_or_policy_drift")
    age = int(time.time())-timestamp(snap.get("observed_utc"))
    if abs(age) > 90:
        raise Refused("wayfa_snapshot_clock_or_freshness_invalid")
    if len(raw.encode("utf-8")) > MAX_SNAPSHOT_BYTES:
        raise Refused("native_payload_too_large")
    return raw, row


def bounded_host_record(cfg: dict, binding: dict, raw: str, epoch: int) -> dict:
    row = {
        "contract": CONTRACT,
        "company_code": cfg["company"],
        "site_uuid": cfg["site_uuid"],
        "canonical_origin": cfg["origin"],
        "binding_sha256": binding["binding_sha256"],
        "transport": "same_host_scoped_wpcli",
        "observed_epoch": epoch,
        "expires_epoch": epoch + EXPIRES_IN,
        "snapshot_json": raw,
        "snapshot_sha256": hashlib.sha256(raw.encode("utf-8")).hexdigest(),
        "issuer_signed": False
    }
    return row


def apply_to_central(cfg: dict, record: dict) -> dict:
    option = OPTION_PREFIX+cfg["company"]
    payload = base64.b64encode(json.dumps(record, separators=(",", ":"), ensure_ascii=False).encode()).decode()
    php = r'''
$raw=base64_decode((string)getenv("MAD4B_NATIVE_SERVICE_RECORD"),true);
$row=is_string($raw)?json_decode($raw,true):null;
$opt=__OPTION__;
if(!defined("WP_CLI")||!WP_CLI
 ||!class_exists("MAD4B_Company_Service_Catalog_V1",false)
 ||!MAD4B_Company_Service_Catalog_V1::observation_enabled("__COMPANY__")
 ||!is_array($row)
 ||($row["company_code"]??"")!=="__COMPANY__"
 ||($row["issuer_signed"]??null)!==false
 ||($row["transport"]??"")!=="same_host_scoped_wpcli"
 ||!hash_equals(hash("sha256",(string)($row["snapshot_json"]??"")),
   (string)($row["snapshot_sha256"]??""))) {
 echo wp_json_encode(["stored"=>false,"reason"=>"host_payload_not_eligible"]);return;
}
$ok=update_option($opt,$row,false);
$after=get_option($opt,null);
echo wp_json_encode(["stored"=>is_array($after)&&$after===$row,
 "write_returned"=>$ok,"match"=>is_array($after)
 &&($after["snapshot_sha256"]??"")===$row["snapshot_sha256"],
 "signed"=>false],JSON_UNESCAPED_SLASHES);
'''.replace("__OPTION__", json.dumps(option)).replace("__COMPANY__", cfg["company"])
    args = [
        "docker","exec","-e","MAD4B_NATIVE_SERVICE_RECORD="+payload,
        "-u","www-data",cfg["center_container"],"php","/usr/local/bin/wp",
        "--path=/var/www/html","eval",php
    ]
    run = subprocess.run(args, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                         text=True,timeout=35,check=False)
    if run.returncode:
        raise Refused("central_native_record_store_failed")
    result = json_dict(run.stdout.strip())
    if result.get("stored") is not True or result.get("match") is not True or result.get("signed") is not False:
        raise Refused("central_independent_option_readback_failed")
    return result


def run_once(config: dict, apply: bool) -> dict:
    config = validate_config(config)
    lock_file = Path("/run/lock/mad4b-company-service-live-readback.lock")
    with lock_file.open("a+") as handle:
        try:
            fcntl.flock(handle.fileno(),fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError:
            raise Refused("readback_worker_busy")
        binding = central_preflight(config)
        challenge = secrets.token_hex(16)
        raw, row = tenant_read(config, challenge)
        if (row["readback_module_sha256"]!=binding["source_readback_module_sha256"]
            or row["snapshot"]["schema"]["declared_count"]!=binding["source_schema_fields"]):
            raise Refused("source_target_readback_module_or_schema_parity_drift")
        epoch = int(time.time())
        receipt = bounded_host_record(config, binding, raw, epoch)
        result = {
            "contract":CONTRACT,"state":"dry_run_verified" if not apply else "native_observed_stored_unsigned",
            "company":config["company"],"site_uuid":config["site_uuid"],
            "site_host":"wayfatours.com","observed_epoch":epoch,
            "snapshot_sha256":receipt["snapshot_sha256"],
            "schema_fields":row["snapshot"]["schema"]["declared_count"],
            "service_modes":{k:v["effective_mode"] for k,v in row["snapshot"]["families"].items()},
            "stripe_active":row["snapshot"]["stripe_enabled"],
            "first_party_signed":False,"original_313_signed":False,
            "target_mutated":False,"central_option_updated":False
        }
        if apply:
            apply_to_central(config, receipt)
            result["central_option_updated"]=True
        return result


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument("--config",type=Path,required=True)
    parser.add_argument("--apply",action="store_true")
    args=parser.parse_args()
    try:
        cfg=json.loads(args.config.read_text(encoding="utf-8"))
        print(json.dumps(run_once(cfg,args.apply),sort_keys=True))
        return 0
    except (Refused,ValueError,OSError,subprocess.TimeoutExpired) as exc:
        reason=str(exc) if isinstance(exc,Refused) else "unavailable"
        print(json.dumps({"contract":CONTRACT,"state":"BLOCKED","reason":reason,
                          "central_option_updated":False,"target_mutated":False,
                          "original_313_signed":False}))
        return 2


if __name__=="__main__":
    sys.exit(main())
