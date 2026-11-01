#!/usr/bin/env python3
"""Bounded, *reconciliation-only* worker for historical Native VPS receipts.

Scans only expired previously claimed vps_native/tours-central/staging Jobs.
Delegates each exact original signed receipt to vps-late-native-reconcile.py.
No command execution, job issuance, grant issuance or JWT/user impersonation.
"""
import argparse
import json
import os
import re
import subprocess
import sys
from pathlib import Path

HOST="srv2030908"
ROOT=Path("/opt/mad4b-company-transport")
RECONCILER=ROOT/"broker-v1/vps-late-native-reconcile.py"
RECEIPTS=ROOT/"native-operator-v1/receipts"
WP=["/usr/bin/docker","exec","-u","www-data","etg-commerce-staging-php","wp","--path=/var/www/html","--skip-themes"]
CODE=r"""
global $wpdb;
$prefix=MAD4B_Central_VPS_Broker_V1::JOB;
$names=$wpdb->get_col($wpdb->prepare(
 "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT 300",
 $wpdb->esc_like($prefix)."%"));
$jobs=[];
foreach ((array)$names as $key) {
 $j=get_option($key,false);
 if(!is_array($j) || ($j["state"]??null)!=="BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY"
    || ($j["executed"]??null)!==false
    || ($j["principal_type"]??null)!=="vps_native"
    || ($j["company"]??null)!=="tours-central"
    || ($j["environment"]??null)!=="staging"
    || ($j["broker_host_id"]??null)!=="srv2030908"
    || !is_int($j["expires_epoch"]??null)
    || $j["expires_epoch"]>=time()
    || $j["expires_epoch"]<time()-7*86400)continue;
 if(!preg_match("/^[a-f0-9]{32}$/D",(string)($j["job_id"]??"")))continue;
 if(!preg_match("/^[A-Za-z0-9_.-]{8,90}$/D",(string)($j["correlation_id"]??"")))continue;
 $jobs[]=["job_id"=>$j["job_id"],"correlation_id"=>$j["correlation_id"]];
 if(count($jobs)>=15)break;
}
echo wp_json_encode(["site"=>home_url(),"eligible"=>$jobs]);
"""

def scan():
    p=subprocess.run(WP+["eval",CODE],capture_output=True,text=True,timeout=30,check=True)
    result=json.loads(p.stdout.strip().splitlines()[-1])
    if result.get("site")!="https://tours.dev.mad4b.com" or not isinstance(result.get("eligible"),list):
        raise RuntimeError("CENTRAL_SITE_OR_SCAN_SHAPE_MISMATCH")
    return result["eligible"]

def main():
    a=argparse.ArgumentParser(description=__doc__)
    a.add_argument("--apply",action="store_true",help="Sign and reconcile verified historical evidence, never run Shell")
    args=a.parse_args()
    if os.geteuid()!=0:
        raise RuntimeError("ROOT_ONLY_RECONCILIATION")
    ids=scan();report={"contract":"mad4b.native-late-sweep.v1",
       "scanned":len(ids),"reconciled":0,"no_native_receipt":0,"denied":0,
       "execution_performed":False}
    for j in ids:
        job_id=j["job_id"];correlation=j["correlation_id"]
        if not re.fullmatch(r"[0-9a-f]{32}",job_id) or not re.fullmatch(r"[A-Za-z0-9_.-]{8,90}",correlation):
            report["denied"]+=1;continue
        receipt=RECEIPTS/(correlation+".json")
        if not receipt.is_file() or receipt.is_symlink():
            report["no_native_receipt"]+=1;continue
        cmd=[sys.executable,str(RECONCILER),"--job",job_id]
        if args.apply:cmd.append("--apply")
        r=subprocess.run(cmd,capture_output=True,text=True,timeout=75)
        if r.returncode:
            report["denied"]+=1
            # Error details intentionally withheld; caller receives counts only.
            continue
        response=json.loads(r.stdout.strip().splitlines()[-1])
        if args.apply:
            if response.get("state")=="GENERAL_SHELL_HISTORICAL_EXECUTION_RECONCILED" and response.get("central_completion_recorded") is True:
                report["reconciled"]+=1
            else:report["denied"]+=1
    print(json.dumps(report,sort_keys=True))
    if report["denied"]:
        raise SystemExit(2)

if __name__=="__main__":
    try:main()
    except (RuntimeError,ValueError,OSError,subprocess.SubprocessError) as exc:
        print("BOUNDED_HISTORICAL_SCAN_FAILED: "+str(exc),file=sys.stderr)
        raise SystemExit(2)
