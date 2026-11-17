#!/usr/bin/env python3
"""Five pinned remote WordPress site profiles against original Central Registry.

Read-only external Hostinger SSH, WordPress WP-CLI readback, no --user.
Does NOT enroll Site Profiles, create grants, activate transports, authorize
306/312/313 or mutate tenant content. Facts only, under the old lifecycle timer.
"""
from __future__ import annotations
import argparse
import datetime as dt
import json
import os
from pathlib import Path
import re
import shlex
import stat
import subprocess
import tempfile

POLICY=Path("/opt/mad4b-company-provisioning/policies/company-hostinger-site-binding-map-v1.json")
INFRA=Path("/opt/mad4b-company-transport/registry-remote-v1/isolated-module-smoke/infrastructure-registry.json")
IDENTITIES=Path("/opt/hostinger-shell-mcp/external-targets")
REPORT=Path("/opt/mad4b-company-provisioning/reports/company-native-external-enrollment-diagnostics-current.json")
CENTRAL="php-wkgtkzta35wkkgouwt6ruljc"
CODE=re.compile(r"^[a-z][a-z0-9-]{2,47}$")
UUID=re.compile(r"^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$")
EMAIL_HASH=re.compile(r"^[a-f0-9]{64}$")
HOSTING_ID=re.compile(r"^hosting-[123]$")
CENTRAL_READ=r'''
if(DB_NAME!=='etg_commerce_staging'
 ||wp_get_environment_type()!=='staging'
 ||untrailingslashit(home_url('/'))!=='https://tours.dev.mad4b.com')
 WP_CLI::error('ORIGINAL_ENROLLMENT_EXACT_CENTRAL_ONLY');
$manager=MAD4B_Company_Operation_Admission_V1::manager_id();
$members=get_option(MAD4B_Company_Portfolio_Manager_V1::MEMBERS,[]);
$assignments=get_option(MAD4B_Company_Portfolio_Manager_V1::ASSIGNMENTS,[]);
$readOnlyIdentities=get_option('mad4b_tourism_company_site_registry_v1',[]);
$readOnlyIdentities=is_array($readOnlyIdentities)?$readOnlyIdentities:[];
$out=[];
foreach(MAD4B_Company_Environment_Registry_V2::discover() as $co=>$company) {
 foreach((array)($company['environments']??[]) as $environment=>$row) {
  if(!in_array($environment,['staging','production'],true))continue;
  $slot=get_option('mad4b_company_native_wpcli_slot_v1_'.$co.'_'.$environment,false);
  $transport=is_array($slot)?get_option(MAD4B_Central_SSH_Command_Intake_V1::TRANSPORT_PREFIX.($slot['transport_id']??''),false):false;
  $grant=is_array($slot)?get_option(MAD4B_Central_SSH_Command_Intake_V1::PREFIX.($slot['grant_id']??''),false):false;
  $eligible=[];
  foreach((array)$members as $m) {
   if(!is_array($m)||($m['manager_id']??null)!==$manager
      ||($m['state']??'')!=='active')continue;
   $id=(int)($m['user_id']??0);$u=get_user_by('id',$id);
   $delegated=$environment==='production'
      ? MAD4B_Company_Portfolio_Manager_V1::has_production_delegation($manager,$co,'operations_manage',$id)
      : (class_exists('MAD4B_Staging_Manager_Admission_Bridge_V1',false)
         &&MAD4B_Staging_Manager_Admission_Bridge_V1::has_delegation($manager,$co,'operations_manage',$id));
   if($u&&user_can($u,'manage_options')&&user_can($u,'install_plugins')
      &&$delegated)
    $eligible[]=hash('sha256',strtolower(trim((string)$u->user_email)));
  }
  $unique=array_values(array_unique($eligible));
  $assignment=$assignments[$manager.'|'.$co]??null;
  $production_assignment=$environment==='production'
    ? MAD4B_Company_Portfolio_Manager_V1::production_assignment_status($manager,$co):null;
  $production_assignment=is_array($production_assignment)?$production_assignment:null;
  $key=$co.':'.$environment;
  $out[$key]=[
   'company'=>$co,'environment'=>$environment,
   'site_uuid'=>$row['site_uuid']??null,
   'canonical_origin'=>$row['canonical_origin']??null,
   'role'=>$row['role']??null,
   'central_manager_assignment_active'=>is_array($assignment)
       &&($assignment['state']??'')==='active',
   // Distinguish a valid prior Staging delegation from this EXACT target.
   // A company-level active assignment cannot silently transfer the signed
   // binding SHA / owner-approved rights to another environment or UUID.
   'central_manager_assignment_site_uuid'=>is_array($assignment)
       ? ($assignment['company_site_uuid']??null):null,
   'central_manager_assignment_exact_site_uuid'=>is_array($assignment)
       &&($assignment['state']??'')==='active'
       &&($assignment['company_site_uuid']??null)===($row['site_uuid']??null)
       &&is_string($row['site_uuid']??null)
       &&wp_is_uuid($row['site_uuid']),
   'production_environment_assignment_state'=>$production_assignment['state']??null,
   'production_environment_assignment_uuid'=>$production_assignment['company_site_uuid']??($production_assignment['site_uuid']??null),
   'production_environment_assignment_revision'=>$production_assignment['revision']??null,
   'production_environment_assignment_binding_current'=>$production_assignment['binding_current']??false,
   'production_environment_owner_operations_authorized'=>count($unique)===1 && $environment==='production',
   'central_manager_eligible_actor_count'=>count($unique),
   'central_manager_email_sha256'=>count($unique)===1?$unique[0]:null,
   // Native pinned-SSH Site Profile already verified in Central. This is
   // identity evidence ONLY, never a WordPress-actor, 305 or 313 grant.
   'central_native_readonly_identity_verified'=>
     $environment==='production'
     &&is_array($readOnlyIdentities[$co]??null)
     &&($readOnlyIdentities[$co]['registration_mode']??null)==='local_vps_verified_readback'
     &&($readOnlyIdentities[$co]['identity_only']??false)===true
     &&($readOnlyIdentities[$co]['native_write_authorized']??true)===false
     &&($readOnlyIdentities[$co]['preferred_identity_provisioner']??null)==='central_native_pinned_ssh'
     &&($readOnlyIdentities[$co]['company_site_uuid']??null)===($row['site_uuid']??null)
     &&($readOnlyIdentities[$co]['native_site_profile_uuid']??null)===($row['site_uuid']??null)
     &&($readOnlyIdentities[$co]['canonical_origin']??null)===($row['canonical_origin']??null),
   'standing_grant_exists'=>is_array($grant),
   'standing_grant_state'=>$grant['state']??null,
   'standing_grant_target_host_id'=>$grant['target_host_id']??null,
   'standing_grant_target_ssh_principal'=>$grant['target_ssh_principal']??null,
   'standing_transport_exists'=>is_array($transport),
   'standing_transport_state'=>$transport['state']??null,
   'standing_transport_host_id'=>$transport['host_id']??null,
   'standing_transport_host_principal'=>$transport['host_principal']??null,
   'standing_transport_active'=>is_array($grant)&&is_array($transport)
       &&MAD4B_Original_Verified_WPCLI_Transport_V1::active($transport,$grant),
  ];
 }
}
ksort($out,SORT_STRING);
echo 'MAD4B_CENTRAL_ENROLLMENT:'.wp_json_encode([
 'contract'=>'mad4b.company-central-original-enrollment-read.v1',
 'scopes'=>$out,'write_authorized_by_readback'=>false
],JSON_UNESCAPED_SLASHES);
'''
REMOTE_READ=r'''
$profile=get_option('mad4b_scp_site_profile_v2',false);
$identity=get_option('mad4b_company_site_identity_v1',false);
$profile=is_array($profile)?$profile:[];
$identity=is_array($identity)?$identity:[];
$c='MAD4B_SCP_Site_Profile';
$a='MAD4B_SCP_Audit';
$plugin='mad4b-site-control-plane/mad4b-site-control-plane.php';
$hash=(string)getenv('MAD4B_ENROLLMENT_ADMIN_SHA256');
$matched=0;
if(preg_match('/^[a-f0-9]{64}$/D',$hash)){
 foreach(get_users(['role'=>'administrator','fields'=>['ID']]) as $u){
  $id=(int)$u->ID;$obj=get_user_by('id',$id);
  if($obj&&user_can($obj,'manage_options')&&user_can($obj,'install_plugins')
    &&hash_equals($hash,hash('sha256',strtolower(trim((string)$obj->user_email)))))
   $matched++;
 }
}
echo 'MAD4B_REMOTE_ENROLLMENT:'.wp_json_encode([
 'contract'=>'mad4b.company-remote-exact-site-read.v1',
 'home'=>untrailingslashit(home_url('/')),
 'siteurl'=>untrailingslashit(site_url('/')),
 'wordpress_environment'=>wp_get_environment_type(),
 'company_site_uuid'=>$identity['site_uuid']??null,
 'company_code'=>$identity['company_code']??null,
 'company_environment'=>$identity['environment']??null,
 'site_profile_uuid'=>$profile['site_uuid']??null,
 'site_profile_environment'=>$profile['environment']??null,
 'site_profile_origin'=>$profile['canonical_origin']??null,
 'site_profile_revision'=>$profile['revision']??null,
 'site_profile_installed'=>file_exists(WP_PLUGIN_DIR.'/'.$plugin),
 'site_profile_plugin_active'=>is_plugin_active($plugin),
 'site_profile_loaded'=>class_exists($c,false),
 'site_profile_audit_ready'=>class_exists($a,false)
      &&($a::storage_status()['ready']??false)===true,
 'central_delegated_admin_match_count'=>$matched,
 'site_profile_governed_write'=>($profile['features']['write']??false)===true,
 'observed_only'=>true,
],JSON_UNESCAPED_SLASHES);
'''

def root_json(path:Path,maxbytes=50000):
    if path.is_symlink():
        raise RuntimeError("EXTERNAL_ENROLLMENT_UNTRUSTED_SYMLINK")
    st=path.stat()
    if (not stat.S_ISREG(st.st_mode) or st.st_uid!=0
        or st.st_mode&0o022 or not 10<=st.st_size<=maxbytes):
        raise RuntimeError("EXTERNAL_ENROLLMENT_UNTRUSTED_SOURCE")
    return json.loads(path.read_bytes())

def policy_routes():
    policy=root_json(POLICY)
    inventory=root_json(INFRA)
    if (policy.get("contract")!="mad4b.company-hostinger-site-binding-map.v1"
        or not isinstance(inventory.get("targets"),list)):
        raise RuntimeError("EXTERNAL_ENROLLMENT_REGISTRY_CONTRACT_DRIFT")
    hosts={}
    for h in inventory["targets"]:
        if h.get("id") in ("hosting-1","hosting-2","hosting-3"):
            if (not re.fullmatch(r"\d{1,3}(?:\.\d{1,3}){3}",h.get("host",""))
                or not re.fullmatch(r"u\d{9}",h.get("username",""))
                or h.get("port")!=65002):
                raise RuntimeError("EXTERNAL_ENROLLMENT_HOST_INVENTORY_INVALID")
            hosts[h["id"]]=h
    rows=policy.get("sites")
    if not isinstance(rows,list) or not 1<=len(rows)<=30:
        raise RuntimeError("EXTERNAL_ENROLLMENT_SITE_POLICY_SHAPE_INVALID")
    routes=[]
    for x in rows:
        company=x.get("company_id")
        environment=x.get("environment")
        ident=x.get("ssh_target")
        user=x.get("ssh_username")
        origin=x.get("origin")
        root=x.get("ssh_root")
        if (not isinstance(company,str) or not CODE.fullmatch(company)
            or environment not in ("staging","production")
            or ident not in hosts or not HOSTING_ID.fullmatch(ident)
            or user!=hosts[ident]["username"]
            or not isinstance(origin,str) or not origin.startswith("https://")
            or not isinstance(root,str) or not root.startswith("/home/"+user+"/domains/")
            or ".." in root.split("/") or "\n" in root):
            raise RuntimeError("EXTERNAL_ENROLLMENT_SITE_POLICY_INVALID")
        row=dict(x);row["host"]=hosts[ident]["host"]
        row["port"]=65002
        routes.append(row)
    if len({(r["company_id"],r["environment"]) for r in routes})!=len(routes):
        raise RuntimeError("EXTERNAL_ENROLLMENT_DUPLICATE_COMPANY_SCOPE")
    return routes

def parse_marked(data:str,marker:str)->dict:
    lines=[line.split(marker,1)[1] for line in data.splitlines()
           if line.startswith(marker)]
    if len(lines)!=1:
        raise RuntimeError("EXTERNAL_ENROLLMENT_READBACK_NOT_UNIQUE")
    try:r=json.loads(lines[0])
    except ValueError as exc:
        raise RuntimeError("EXTERNAL_ENROLLMENT_READBACK_NOT_JSON") from exc
    if not isinstance(r,dict):
        raise RuntimeError("EXTERNAL_ENROLLMENT_READBACK_INVALID")
    return r

def central_scope_read():
    cmd=["/usr/bin/docker","exec","-u","33:33",CENTRAL,
         "/usr/bin/timeout","--signal=TERM","--kill-after=4s","53s",
         "/usr/local/bin/wp","eval",CENTRAL_READ,
         "--path=/var/www/html","--skip-themes"]
    p=subprocess.run(cmd,capture_output=True,text=True,timeout=65)
    if p.returncode:
        raise RuntimeError("EXTERNAL_ENROLLMENT_CENTRAL_READ_REFUSED")
    r=parse_marked(p.stdout,"MAD4B_CENTRAL_ENROLLMENT:")
    if (r.get("contract")!="mad4b.company-central-original-enrollment-read.v1"
        or r.get("write_authorized_by_readback") is not False
        or not isinstance(r.get("scopes"),dict)
        or len(r["scopes"])>50):
        raise RuntimeError("EXTERNAL_ENROLLMENT_CENTRAL_CONTRACT_INVALID")
    return r["scopes"]

def verified_pin(folder:Path,ip:str,port:int):
    for suffix in ("id_ed25519","known_hosts_verified"):
        file=folder/suffix
        if file.is_symlink() or not file.is_file() or file.stat().st_uid!=0:
            raise RuntimeError("EXTERNAL_ENROLLMENT_HOST_PIN_NOT_TRUSTED")
        if file.stat().st_mode&0o077:
            raise RuntimeError("EXTERNAL_ENROLLMENT_SSH_FILE_MODE_INVALID")
    pins=(folder/"known_hosts_verified").read_text().splitlines()
    required=f"[{ip}]:{port}"
    if not any(required in x.split()[0].split(",")
               for x in pins if x.strip() and not x.startswith("#")):
        raise RuntimeError("EXTERNAL_ENROLLMENT_EXACT_HOST_PIN_MISSING")

def remote_site(row,actor_sha):
    folder=IDENTITIES/row["ssh_target"]
    verified_pin(folder,row["host"],row["port"])
    if actor_sha is None:actor_sha="0"*64
    if not EMAIL_HASH.fullmatch(actor_sha):
        raise RuntimeError("EXTERNAL_ENROLLMENT_OWNER_IDENTITY_HASH_INVALID")
    cmd=["/usr/bin/ssh","-F","/dev/null","-T","-p",str(row["port"]),
         "-o","BatchMode=yes","-o","IdentitiesOnly=yes",
         "-o","PreferredAuthentications=publickey",
         "-o","PasswordAuthentication=no",
         "-o","KbdInteractiveAuthentication=no",
         "-o","StrictHostKeyChecking=yes","-o","UpdateHostKeys=no",
         "-o","HostKeyAlgorithms=ssh-ed25519",
         "-o","GlobalKnownHostsFile=/dev/null",
         "-o","UserKnownHostsFile="+str(folder/"known_hosts_verified"),
         "-o","ConnectTimeout=8","-o","ConnectionAttempts=1",
         "-i",str(folder/"id_ed25519"),
         row["ssh_username"]+"@"+row["host"],
         "MAD4B_ENROLLMENT_ADMIN_SHA256="+shlex.quote(actor_sha)+
         " wp --path="+shlex.quote(row["ssh_root"])+
         " --skip-themes eval "+shlex.quote(REMOTE_READ)]
    try:
        p=subprocess.run(cmd,capture_output=True,text=True,timeout=65)
    except subprocess.TimeoutExpired as exc:
        raise RuntimeError("EXTERNAL_ENROLLMENT_REMOTE_READ_TIMEOUT") from exc
    if p.returncode:
        raise RuntimeError("EXTERNAL_ENROLLMENT_SSH_OR_WPCLI_READ_DENIED")
    result=parse_marked(p.stdout,"MAD4B_REMOTE_ENROLLMENT:")
    if (result.get("contract")!="mad4b.company-remote-exact-site-read.v1"
        or result.get("observed_only") is not True
        or result.get("home")!=row["origin"]
        or result.get("siteurl")!=row["origin"]
        or result.get("wordpress_environment")!=row["environment"]):
        raise RuntimeError("EXTERNAL_ENROLLMENT_REMOTE_SCOPE_MISMATCH")
    return result

def classify(row,central,remote):
    if remote is None:
        return "REMOTE_WPCLI_READ_NOT_VERIFIED","RETRY_PINNED_CROSS_SSH_READ"
    # Hostinger tenants are on different SSH hosts from the Central VPS.
    # A root-local Original 305 pair MUST NOT be treated as proof of remote
    # authorization or cured by merely signing a 328 receipt for local Docker.
    if central.get("standing_grant_exists") is True:
        grant_host=central.get("standing_grant_target_host_id")
        transport_host=central.get("standing_transport_host_id")
        grant_principal=central.get("standing_grant_target_ssh_principal")
        transport_principal=central.get("standing_transport_host_principal")
        remote_host=row["ssh_target"]
        remote_user=row["ssh_username"]
        if (not isinstance(grant_host,str)
            or not isinstance(transport_host,str)
            or not isinstance(grant_principal,str)
            or not isinstance(transport_principal,str)):
            return ("ORIGINAL_305_HOST_BINDING_NOT_VERIFIED",
                    "VERIFY_ORIGINAL_305_GRANT_AND_TRANSPORT_HOST")
        if (grant_host!=remote_host or transport_host!=remote_host
            or grant_principal!=remote_user or transport_principal!=remote_user):
            return ("ORIGINAL_305_LOCAL_PAIR_INCOMPATIBLE_WITH_REMOTE_HOST",
                    "SIGNED_REMOTE_305_PROVIDER_AND_PAIR_REISSUE_REQUIRED")
    if central.get("standing_transport_active") is True:
        return "ORIGINAL_TRANSPORT_ACTIVE","VERIFY_SIGNED_312_PER_OPERATION"
    if row["environment"]=="production":
        if not remote.get("site_profile_installed") or not remote.get("site_profile_plugin_active"):
            return "PRODUCTION_SITE_PROFILE_PLUGIN_NOT_ACTIVE","REVIEW_OFFICIAL_PLUGIN_ACTIVATION"
        if not remote.get("site_profile_uuid") or not UUID.fullmatch(
            str(remote.get("site_profile_uuid"))):
            if remote.get("central_delegated_admin_match_count")!=1:
                return "PRODUCTION_SITE_PROFILE_MISSING_AND_MANAGER_NOT_BOUND","COMPANY_OWNER_PRINCIPAL_LINK_REQUIRED"
            if remote.get("site_profile_audit_ready") is not True:
                return "PRODUCTION_SITE_PROFILE_AUDIT_NOT_READY","RESTORE_APPEND_ONLY_AUDIT"
            return "PRODUCTION_SITE_PROFILE_OFFICIAL_SAVE_REQUIRED","USE_AUTHENTICATED_SITE_PROFILE_ADMIN_ACTION"
        if not UUID.fullmatch(str(central.get("site_uuid") or "")):
            return "PRODUCTION_SITE_PROFILE_EXISTS_CENTRAL_SIGNED_BINDING_MISSING","SIGN_CENTRAL_EXACT_SITE_IDENTITY_V4"
    if remote.get("site_profile_uuid")!=central.get("site_uuid"):
        return "SITE_PROFILE_UUID_MISMATCH","RECONCILE_NATIVE_SIGNED_SITE_IDENTITY"
    if (remote.get("company_code")!=row["company_id"]
        or remote.get("company_site_uuid")!=central.get("site_uuid")):
        # Do not require a duplicate remote identity option just to recognize
        # a read-only Site Profile that Central already independently enrolled.
        # This does NOT count as the separate Original305/312/313 write grant.
        if (
            row["environment"]=="production"
            and central.get("central_native_readonly_identity_verified") is True
            and central.get("production_environment_assignment_state")=="active"
            and central.get("production_environment_assignment_binding_current") is True
            and central.get("production_environment_assignment_uuid")==central.get("site_uuid")
            and central.get("production_environment_owner_operations_authorized") is True
            and remote.get("site_profile_audit_ready") is True
            and remote.get("site_profile_governed_write") is False
            and remote.get("home")==row["origin"]
            and remote.get("siteurl")==row["origin"]
            and remote.get("site_profile_origin")==row["origin"]
            and remote.get("site_profile_environment")=="production"
            and remote.get("wordpress_environment")=="production"
            and type(remote.get("site_profile_revision")) is int
            and remote["site_profile_revision"]>=1
            and central.get("canonical_origin")==row["origin"]
        ):
            return (
                "CENTRAL_SSH_NATIVE_IDENTITY_AND_OWNER_VERIFIED_WRITE_GRANT_MISSING",
                "ORIGINAL_SIGNED_JOB_SCOPED_305_312_313_REQUIRED",
            )
        return "REMOTE_COMPANY_IDENTITY_NOT_BOUND","ENROLL_ORIGINAL_REMOTE_SITE_IDENTITY"
    if row["environment"]=="production" and central.get("standing_grant_exists") is not True:
        environment_state=central.get("production_environment_assignment_state")
        if environment_state=="not_assigned":
            return ("PRODUCTION_ENVIRONMENT_OWNER_DELEGATION_NOT_PROPOSED",
                    "PROPOSE_ORIGINAL_PRODUCTION_ENVIRONMENT_DELEGATION")
        if environment_state in ("proposed","approved","suspended","revoked"):
            return ("PRODUCTION_ENVIRONMENT_OWNER_DELEGATION_PENDING_ACTIVATION",
                    "APPROVE_AND_ACTIVATE_ORIGINAL_PRODUCTION_ENVIRONMENT_DELEGATION")
        if environment_state=="active" and central.get("production_environment_owner_operations_authorized") is not True:
            return ("PRODUCTION_ENVIRONMENT_OWNER_RIGHTS_NOT_AUTHORIZED",
                    "REVIEW_PRODUCTION_OWNER_DELEGATION_RIGHTS")
        if (environment_state is None
            and central.get("central_manager_assignment_active") is True
            and central.get("central_manager_assignment_exact_site_uuid") is False):
            return ("PRODUCTION_MANAGER_ASSIGNMENT_BOUND_TO_OTHER_SITE_UUID",
                    "REAPPROVE_PRODUCTION_EXACT_SITE_OWNER_DELEGATION_BEFORE_305")
    if (central.get("standing_grant_exists") is True
        and central.get("standing_transport_state")=="PENDING_INDEPENDENT_SSH_VERIFICATION"):
        return "ORIGINAL_305_PAIR_PENDING_REMOTE_HOST_VERIFICATION","ORIGINAL_SIGNED_REMOTE_328_HOST_PROOF_REQUIRED"
    return "ORIGINAL_GRANT_OR_TRANSPORT_NOT_ENROLLED","USE_ORIGINAL_AUTHORIZED_305_ENROLLMENT"

def save_report(data:dict):
    out=REPORT
    if out.is_symlink():
        raise RuntimeError("EXTERNAL_ENROLLMENT_REPORT_SYMLINK_DENIED")
    out.parent.mkdir(exist_ok=True)
    if out.parent.is_symlink() or out.parent.stat().st_uid!=0:
        raise RuntimeError("EXTERNAL_ENROLLMENT_REPORT_DIR_UNTRUSTED")
    fd,temp=tempfile.mkstemp(prefix=".cross-ssh-enrollment-",dir=out.parent)
    try:
        with os.fdopen(fd,"w") as stream:
            os.fchmod(stream.fileno(),0o600)
            json.dump(data,stream,sort_keys=True,ensure_ascii=False)
            stream.write("\n");stream.flush();os.fsync(stream.fileno())
        os.replace(temp,out)
    finally:
        if os.path.exists(temp):os.unlink(temp)

def run(*,persist=True,remote_reader=None,source_routes=None,
        source_central=None)->dict:
    if os.geteuid()!=0:
        raise RuntimeError("EXTERNAL_ENROLLMENT_ROOT_OPERATOR_REQUIRED")
    routes=source_routes if source_routes is not None else policy_routes()
    central=source_central if source_central is not None else central_scope_read()
    if not isinstance(central,dict):
        raise RuntimeError("EXTERNAL_ENROLLMENT_CENTRAL_INVALID")
    seen=set()
    doctor=remote_reader or remote_site
    outcomes=[]
    for site in routes:
        scope=site["company_id"]+":"+site["environment"]
        if scope not in central or scope in seen:
            raise RuntimeError("EXTERNAL_ENROLLMENT_SCOPE_NOT_IN_CENTRAL")
        seen.add(scope)
        c=central[scope]
        if (c.get("company")!=site["company_id"]
            or c.get("environment")!=site["environment"]
            or c.get("canonical_origin")!=site["origin"]):
            raise RuntimeError("EXTERNAL_ENROLLMENT_CENTRAL_COMPANY_SCOPE_DRIFT")
        try:
            remote=doctor(site,c.get("central_manager_email_sha256"))
            auth=True;reason=None
        except (RuntimeError,OSError,ValueError,subprocess.SubprocessError) as ex:
            remote=None;auth=False;reason=str(ex)
            if not re.fullmatch(r"[A-Z][A-Z0-9_]{4,105}",reason):
                reason="EXTERNAL_ENROLLMENT_REMOTE_READ_NOT_VERIFIED"
        state,next_action=classify(site,c,remote)
        row={
            "company_code":site["company_id"],"environment":site["environment"],
            "canonical_origin":site["origin"],
            "central_site_uuid":c.get("site_uuid"),
            "remote_site_uuid":remote.get("site_profile_uuid") if remote else None,
            "remote_company_site_uuid":remote.get("company_site_uuid") if remote else None,
            "original_grant_present":c.get("standing_grant_exists") is True,
            "original_grant_target_host_id":c.get("standing_grant_target_host_id"),
            "original_transport_host_id":c.get("standing_transport_host_id"),
            "remote_target_host_id":site["ssh_target"],
            "original_transport_state":c.get("standing_transport_state"),
            "authenticated_pinned_ssh_read":auth,
            "wordpress_origin_environment_verified":auth,
            "site_profile_plugin_active":remote.get("site_profile_plugin_active") if remote else None,
            "site_profile_audit_ready":remote.get("site_profile_audit_ready") if remote else None,
            "central_owner_delegation_active":c.get("central_manager_assignment_active") is True,
            "central_assignment_matches_target_site_uuid":c.get("central_manager_assignment_exact_site_uuid") is True,
            "central_assignment_previous_site_uuid":c.get("central_manager_assignment_site_uuid"),
            "production_environment_assignment_state":c.get("production_environment_assignment_state"),
            "production_environment_assignment_uuid":c.get("production_environment_assignment_uuid"),
            "production_environment_assignment_revision":c.get("production_environment_assignment_revision"),
            "production_environment_owner_operations_authorized":c.get("production_environment_owner_operations_authorized") is True,
            "central_admin_has_exact_tenant_identity":(
                remote.get("central_delegated_admin_match_count")==1 if remote else False),
            "state":state,"next_action":next_action,
            "read_error":reason,"mutated":False,
        }
        outcomes.append(row)
    now=dt.datetime.now(dt.timezone.utc)
    result={
        "contract":"mad4b.company-native-external-enrollment-doctor.v1",
        "observed_utc":now.isoformat(),
        "central_origin":"https://tours.dev.mad4b.com",
        "remote_site_count":len(outcomes),"remote_sites":outcomes,
        "read_only":True,
        "no_new_site_profiles":True,"new_grants_issued":0,
        "transports_activated":0,"tenant_write_executed":False,
        "original_312_ready_inferred":False,
        "remote_ssh_authentication_is_not_original_328_proof":True,
    }
    if persist:save_report(result)
    return result

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument("--observe-only",action="store_true",default=True)
    args=ap.parse_args()
    result=run()
    print(json.dumps({
        "contract":result["contract"],
        "remote_site_count":result["remote_site_count"],
        "states":{
           r["company_code"]+":"+r["environment"]:r["state"]
           for r in result["remote_sites"]},
        "mutated":False,"grants_issued":0,
    },sort_keys=True))

if __name__=="__main__":
    try:main()
    except (RuntimeError,OSError,ValueError,TypeError,KeyError,
            subprocess.SubprocessError) as ex:
        reason=str(ex)
        if not re.fullmatch(r"[A-Z][A-Z0-9_]{4,105}",reason):
            reason="EXTERNAL_ENROLLMENT_DIAGNOSTIC_REFUSED"
        print(json.dumps({"state":"HELD","reason":reason,
                          "tenant_write_executed":False}))
        raise SystemExit(2)
