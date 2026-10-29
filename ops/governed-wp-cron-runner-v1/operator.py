#!/usr/bin/env python3
"""Bounded, fail-closed, per-tenant WordPress Cron runner.

No general WP-Cron invocation. All hooks require a private, exact runtime
callback + source hash approval. Only approved due instance/hook executions.
"""
import argparse
import datetime as dt
import fcntl
import hashlib
import json
import os
import pathlib
import subprocess
import sys
import tempfile

EXPECTED_PATH = "/var/www/html"
CONTRACT = "mad4b.governed-wp-cron-runner.v1"

PROBE_PHP = r'''
require "/var/www/html/wp-load.php";
if(wp_get_environment_type()!=="staging" ||
  !defined("DISABLE_WP_CRON") || DISABLE_WP_CRON!==true)exit(10);
$now=time();$hooks=[];$byhook=[];
foreach((array)_get_cron_array() as $ts=>$events) {
 foreach((array)$events as $hook=>$jobs) {
  if(!isset($byhook[$hook]))$byhook[$hook]=["due"=>0,"old24"=>0,"next"=>null,"arg_free"=>true];
  if($byhook[$hook]["next"]===null || (int)$ts<$byhook[$hook]["next"])$byhook[$hook]["next"]=(int)$ts;
  foreach((array)$jobs as $job) {
   if(!empty($job["args"]))$byhook[$hook]["arg_free"]=false;
   if((int)$ts<=$now) {
    $byhook[$hook]["due"]++;
    if((int)$ts<=$now-86400)$byhook[$hook]["old24"]++;
   }
  }
 }
}
global $wp_filter;
foreach($byhook as $hook=>$state) {
 $callbacks=[];
 if(isset($wp_filter[$hook]))foreach($wp_filter[$hook]->callbacks as $prio=>$items)
 foreach($items as $item) {
  $cb=$item["function"];
  try {
   if(is_string($cb)){$reflection=new ReflectionFunction($cb);$name=$cb;}
   elseif(is_array($cb)){$reflection=new ReflectionMethod($cb[0],$cb[1]);$name=(is_object($cb[0])?get_class($cb[0]):$cb[0])."::".$cb[1];}
   else{$name="unrecognized";$reflection=null;}
   $path=$reflection?$reflection->getFileName():false;
   $source=($path && is_file($path))?hash_file("sha256",$path):null;
   if($path && str_starts_with($path,ABSPATH))$path=substr($path,strlen(ABSPATH));
   $callbacks[]=["name"=>$name,"priority"=>(int)$prio,"source_relpath"=>$path?:null,"source_sha256"=>$source];
  }catch(Throwable $e){$callbacks[]=["name"=>"unrecognized"];}
 }
 $hooks[$hook]=array_merge($state,["callbacks"=>$callbacks]);
}
echo "MAD4B_CRON_PROBE_BEGIN\n".wp_json_encode([
 "contract"=>"mad4b.wp-cron-readiness-probe.v1",
 "environment"=>wp_get_environment_type(),
 "home_host"=>wp_parse_url(home_url(),PHP_URL_HOST),
 "wp_cron_disabled"=>DISABLE_WP_CRON,
 "now"=>$now,"hooks"=>$hooks
],JSON_UNESCAPED_SLASHES)."\nMAD4B_CRON_PROBE_END\n";
'''

def invoke(args,timeout):
    return subprocess.run(args,capture_output=True,text=True,timeout=timeout,check=False)

def probe(container):
    ret=invoke(["docker","exec","-u","33:33",container,"php","-d","display_errors=0","-r",PROBE_PHP],55)
    if ret.returncode: raise RuntimeError("WP cron probe failed rc="+str(ret.returncode))
    out=ret.stdout
    if out.count("MAD4B_CRON_PROBE_BEGIN\n")!=1 or out.count("\nMAD4B_CRON_PROBE_END")!=1:
        raise RuntimeError("WP cron probe ambiguous")
    payload=json.loads(out.split("MAD4B_CRON_PROBE_BEGIN\n")[1].split("\nMAD4B_CRON_PROBE_END")[0])
    if payload.get("contract")!="mad4b.wp-cron-readiness-probe.v1" or payload.get("environment")!="staging" or payload.get("wp_cron_disabled") is not True:
        raise RuntimeError("WP Cron staging boundary failed")
    return payload

def save(data,state):
    fd,tmp=tempfile.mkstemp(prefix=".cron-state-",dir=state.parent)
    try:
        os.fchmod(fd,0o600)
        with os.fdopen(fd,"w") as f:
            json.dump(data,f,indent=2,sort_keys=True)
            f.write("\n")
            f.flush()
            os.fsync(f.fileno())
        os.replace(tmp,state)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)

def main():
    p=argparse.ArgumentParser()
    p.add_argument("--execute",action="store_true")
    p.add_argument("--policy",type=pathlib.Path,required=True)
    args=p.parse_args()
    policy_path=args.policy.resolve()
    state_file=policy_path.parent / "latest.json"
    lock=policy_path.parent / "lock"
    if not policy_path.is_file():
        raise RuntimeError("Missing root-controlled policy")
    policy=json.loads(policy_path.read_text())
    if policy.get("contract")!=CONTRACT or policy.get("environment")!="staging" or not isinstance(policy.get("site_host"),str):
        raise RuntimeError("Wrong policy contract or tenant scope")
    import re
    container=policy.get("site_php_container")
    if not isinstance(container,str) or not re.fullmatch(r"[a-z0-9][a-z0-9_-]{2,90}",container):
        raise RuntimeError("Invalid site PHP container")
    if not re.fullmatch(r"[a-z0-9.-]{4,150}",policy["site_host"]):
        raise RuntimeError("Invalid site host")
    if policy.get("max_executions_per_run",0) not in (0,1,2):
        raise RuntimeError("Unexpected execution budget")
    approved=policy.get("approved",[])
    if not isinstance(approved,list) or len(approved)>4:
        raise RuntimeError("Invalid allowlist")
    if any(set(item)!={"hook","callback","source_relpath","source_sha256","approved_effect_class"} for item in approved):
        raise RuntimeError("Unexpected allowlist fields")
    lock.touch(mode=0o600,exist_ok=True)
    with lock.open("r+") as f:
        fcntl.flock(f,fcntl.LOCK_EX|fcntl.LOCK_NB)
        first=probe(container)
        if first.get("home_host")!=policy["site_host"]:
            raise RuntimeError("Wrong WordPress tenant host")
        summary={"contract":CONTRACT,"observed_at_utc":dt.datetime.now(dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
            "environment":"staging","host":first["home_host"],
            "mode":"execute_approved_due" if args.execute else "audit_only",
            "automatic_unreviewed_hook_execution":False,"business_hooks_executed":False,
            "full_cron_replay":False,"old24_before":sum(x["old24"] for x in first["hooks"].values()),
            "due_before":sum(x["due"] for x in first["hooks"].values()),
            "evaluations":[],"executed":[],"blocked_due_hooks":[]}
        for name,state in first["hooks"].items():
            if state["due"] and name not in [a["hook"] for a in approved]:
                summary["blocked_due_hooks"].append({"hook":name,"due":state["due"],"old24":state["old24"],
                    "reason":"requires_independent_effect_review"})
        budget=policy["max_executions_per_run"] if args.execute else 0
        for item in approved:
            hook=item["hook"]
            state=first["hooks"].get(hook,{})
            observed=state.get("callbacks",[])
            expected=[{"name":item["callback"],"priority":10,"source_relpath":item["source_relpath"],
                "source_sha256":item["source_sha256"]}]
            ok=observed==expected and state.get("arg_free") is True and state.get("due",0)>0
            summary["evaluations"].append({"hook":hook,"approved_callback_exact":observed==expected,
                "args_empty":state.get("arg_free"),"due":state.get("due",0),
                "allowed_now":bool(ok and budget>0)})
            if not (ok and budget>0):
                continue
            ret=invoke(["docker","exec","-u","33:33",container,"wp","--path="+EXPECTED_PATH,
                "cron","event","run",hook,"--due-now"],80)
            # stdout may include WP-CLI notices, don't emit it in logs.
            summary["executed"].append({"hook":hook,"exit_code":ret.returncode,
                "stdout_sha256":hashlib.sha256(ret.stdout.encode()).hexdigest(),
                "stderr_sha256":hashlib.sha256(ret.stderr.encode()).hexdigest()})
            budget-=1
            if ret.returncode!=0:
                break
        last=probe(container)
        summary["old24_after"]=sum(x["old24"] for x in last["hooks"].values())
        summary["due_after"]=sum(x["due"] for x in last["hooks"].values())
        summary["executions_successful"]=sum(x["exit_code"]==0 for x in summary["executed"])
        save(summary,state_file)
        print(json.dumps({k:summary[k] for k in
            ("mode","old24_before","old24_after","due_before","due_after","executions_successful","executed")}))
        if any(x["exit_code"]!=0 for x in summary["executed"]):
            raise RuntimeError("One or more individually approved Cron executions failed")

if __name__=="__main__":
    try:main()
    except Exception as e:
        print("GUARDED_CRON_STOP: "+str(e),file=sys.stderr)
        sys.exit(2)
