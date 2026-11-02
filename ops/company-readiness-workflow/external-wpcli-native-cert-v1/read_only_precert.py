#!/usr/bin/env python3
"""Original-scope read-only external WPCLI pre-certification. Never authorizes 313."""
import hashlib,json,os,pathlib,re,stat,subprocess,sys,tempfile,time
ROOT=pathlib.Path("/opt/mad4b-company-transport")
POLICY=ROOT/"native-operator-v1/host-authority.json"
INVENTORY=ROOT/"broker-v1/external-site-ssh-inventory.v1.json"
OUT=pathlib.Path("/opt/mad4b-company-provisioning/reports/native-external-readonly-precert-current.json")
CENTRAL=["docker","exec","-u","33:33","etg-commerce-staging-php","/usr/local/bin/wp"]
ARGS=["--path=/var/www/html","--skip-themes"]
COMPANIES=("all-royal-egypt","egypt-tour-gates")
PHP='echo wp_json_encode(["site_uuid"=>MAD4B_SCP_Site_Profile::site_uuid(),"origin"=>untrailingslashit(home_url("/")),"environment"=>wp_get_environment_type()]);'
def rootfile(p):
 s=p.lstat()
 if not stat.S_ISREG(s.st_mode) or s.st_uid!=0 or (s.st_mode&0o077):
  raise RuntimeError("UNTRUSTED_ROOT_INPUT")
 return p.read_bytes()
def command(argv,timeout=50):
 p=subprocess.run(argv,check=False,capture_output=True,text=True,timeout=timeout)
 if p.returncode or len(p.stdout)>16384:raise RuntimeError("EXACT_READ_ONLY_COMMAND_REJECTED")
 return p.stdout.strip()
def ssh(cfg,site,argv):
 r=cfg["registry_binding"]
 if not re.fullmatch(r"hosting-[0-9]{1,3}:staging",r):raise RuntimeError("HOST_SCOPE_INVALID")
 registry=r.split(":")[0];account=site["username"];host=site["hostname"];port=site["port"]
 if not re.fullmatch(r"u[0-9]{6,12}",account) or not re.fullmatch(r"[0-9.]{7,15}",host) or type(port)!=int:raise RuntimeError("SSH_TARGET_INVALID")
 path=site["wp_root"]
 if not re.fullmatch(r"/home/u[0-9]{6,12}/domains/[a-z0-9.-]+/public_html/staging",path) or not path.startswith("/home/"+account+"/"):
  raise RuntimeError("WP_ROOT_INVALID")
 key=pathlib.Path("/opt/hostinger-shell-mcp/external-targets")/registry/"id_ed25519"
 known=key.parent/"known_hosts_verified"
 rootfile(key);rootfile(known)
 import shlex
 remote="cd "+shlex.quote(path)+" && wp "+ " ".join(shlex.quote(x) for x in argv)
 return command(["/usr/bin/ssh","-F","/dev/null","-T","-o","BatchMode=yes",
  "-o","IdentitiesOnly=yes","-o","StrictHostKeyChecking=yes",
  "-o","UserKnownHostsFile="+str(known),"-o","GlobalKnownHostsFile=/dev/null",
  "-o","ClearAllForwardings=yes","-o","ProxyCommand=none",
  "-o","ConnectTimeout=8","-o","LogLevel=ERROR",
  "-i",str(key),"-p",str(port),account+"@"+host,remote],timeout=65)
def check(company,policy,inventory):
 key=company+":staging";scope=policy["scopes"].get(key)
 if not isinstance(scope,dict) or scope.get("allow_operator") is not True or scope.get("allow_mutation") is not True or scope.get("allow_production_mutation") is not False:
  raise RuntimeError("ORIGINAL_STAGING_SCOPE_NOT_ADMITTED")
 reg=scope.get("registry_binding","")
 site=inventory["targets"].get(reg.split(":")[0])
 if not isinstance(site,dict) or site.get("company")!=company or site.get("environment")!="staging" or site.get("origin")!=scope.get("canonical_origin") or site.get("wp_root")!=scope.get("expected_root"):
  raise RuntimeError("ROOT_POLICY_INVENTORY_SCOPE_MISMATCH")
 grant=json.loads(command(CENTRAL+["mad4b","company-native-wpcli","status","--company="+company,"--environment=staging",*ARGS]))
 proof=json.loads(command(CENTRAL+["mad4b","company-native-host-proof","status","--company="+company,"--environment=staging",*ARGS]))
 if grant.get("state")!="ORIGINAL_305_PERSISTENT_PAIR_ENROLLED" or grant.get("site_uuid")!=scope.get("site_uuid") or proof.get("verified") is not True or proof.get("state")!="HOST_PROOF_VERIFIED_NATIVE_EXECUTOR_PENDING":
  raise RuntimeError("ORIGINAL_305_OR_HOST_PROOF_NO_LONGER_ELIGIBLE")
 home=ssh(scope,site,["option","get","home","--skip-plugins","--skip-themes"])
 detail=ssh(scope,site,["eval",PHP,"--skip-themes"])
 try: values=json.loads(detail)
 except ValueError:raise RuntimeError("WORDPRESS_JSON_READBACK_INVALID")
 if home!=scope["canonical_origin"] or values.get("origin")!=home or values.get("environment")!="staging" or values.get("site_uuid")!=scope["site_uuid"]:
  raise RuntimeError("INDEPENDENT_CROSS_SSH_READBACK_DRIFT")
 return {"company":company,"environment":"staging","site_uuid":values["site_uuid"],
  "origin":home,"original_305_verified":True,"original_signed_host_proof_verified":True,
  "read_only_ssh_execution_verified":True,
  "independent_site_profile_readback_verified":True,
  "home_sha256":hashlib.sha256((home+"\n").encode()).hexdigest(),
  "profile_readback_sha256":hashlib.sha256(detail.encode()).hexdigest(),
  "native_executor_certified":False,"original_313_certified":False}
def main():
 if os.geteuid()!=0:raise RuntimeError("ROOT_READ_ONLY_PRECERT_REQUIRED")
 policy=json.loads(rootfile(POLICY));inventory=json.loads(rootfile(INVENTORY))
 if policy.get("contract")!="mad4b.native-vps-operator.v1" or policy.get("revoked") is not False or inventory.get("contract")!="mad4b.external-company-ssh-inventory.v1":
  raise RuntimeError("ORIGINAL_ROOT_POLICY_NOT_VERIFIED")
 results=[check(c,policy,inventory) for c in COMPANIES]
 report={"contract":"mad4b.external-site-wpcli-readonly-precert.v1",
  "checked_epoch":int(time.time()),"targets":results,
  "original_313_certified":False,"full_wpcli_active":False,
  "job_created":False,"new_grants_created":False,"production_mutated":False}
 OUT.parent.mkdir(parents=True,exist_ok=True)
 fd,tmp=tempfile.mkstemp(dir=OUT.parent,prefix=".readonly-site-precert-")
 try:
  with os.fdopen(fd,"w") as f:
   os.fchmod(f.fileno(),0o600)
   json.dump(report,f,sort_keys=True)
   f.write("\n");f.flush();os.fsync(f.fileno())
  os.replace(tmp,OUT)
 finally:
  if os.path.exists(tmp):os.unlink(tmp)
 print(json.dumps(report,sort_keys=True))
if __name__=="__main__":
 try:main()
 except (RuntimeError,OSError,ValueError,subprocess.SubprocessError) as e:
  print("READONLY_PRECERT_DENIED:"+str(e)[:180],file=sys.stderr);sys.exit(2)
