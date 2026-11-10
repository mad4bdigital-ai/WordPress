#!/usr/bin/env python3
"""Bounded SSH transport for enrolled infrastructure targets; no private-key export."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shlex
import subprocess
import sys

BASE = Path("/opt/mad4b-company-transport")
ARTIFACTS = BASE / "managed-v1/artifacts"
ENROLLED = Path("/opt/hostinger-shell-mcp/external-targets")
TARGETS = {
    "hosting-3:staging": {
        "host": "82.198.227.227", "port": 65002,
        "user": "u783534439",
        "root": "/home/u783534439/domains/egypttourgates.com/public_html/staging",
        "site_uuid": "d745d81f-6fc4-5c6a-99dd-d953c92137bf",
    },
    "hosting-2:staging": {
        "host": "92.113.28.241", "port": 65002, "user": "u948953904",
        "root": "/home/u948953904/domains/allroyalegypt.com/public_html/staging",
        "site_uuid": "49c562d1-8f2f-456f-b454-26816c6ba4cb",
    },
}
ORIGINS = {
    "hosting-2:staging": "https://staging.allroyalegypt.com",
    "hosting-3:staging": "https://staging.egypttourgates.com",
}
# Runs on a pinned, already enrolled Staging SSH target and still works if
# WordPress MCP/OAuth or the Control Plane plugin cannot load.
# No user impersonation, database mutation, network writes or guessed paths.
DOCTOR_SCRIPT = r"""
import hashlib,json,os,re,subprocess,sys
from pathlib import Path
root=Path(sys.argv[1]); expected=sys.argv[2]; origin=sys.argv[3]
def wp(args,limit=24):
    try:
        cp=subprocess.run(['wp','--path='+str(root)]+args,capture_output=True,text=True,timeout=limit)
        return cp.returncode,cp.stdout
    except (OSError,subprocess.TimeoutExpired):
        return 75,''
profile_code=(
  "$p=get_option('mad4b_scp_site_profile_v2',[]);"
  "if(!is_array($p))$p=[];"
  "echo 'MAD4B_RECOVERY_ID:'.wp_json_encode(["
  "'siteurl'=>get_option('siteurl'),'home'=>get_option('home'),"
  "'site_uuid'=>$p['site_uuid']??'',"
  "'environment'=>$p['environment']??'',"
  "'wp_environment'=>wp_get_environment_type()]);"
)
status,raw=wp(['--skip-plugins','--skip-themes','eval',profile_code])
markers=[x.split('MAD4B_RECOVERY_ID:',1)[1] for x in raw.splitlines()
         if x.startswith('MAD4B_RECOVERY_ID:')]
facts={}
if status==0 and len(markers)==1:
    try:facts=json.loads(markers[0])
    except ValueError:facts={}
matched=(isinstance(facts,dict) and facts.get('site_uuid')==expected
         and facts.get('environment')=='staging'
         and facts.get('wp_environment')=='staging'
         and facts.get('siteurl')==origin and facts.get('home')==origin)
plugins={}
for name,main in [('mad4b-site-control-plane','mad4b-site-control-plane.php'),
                  ('mcp-adapter','mcp-adapter.php')]:
    base=root/'wp-content/plugins'/name
    mainfile=base/main
    content=mainfile.read_text(errors='replace')[:12000] if mainfile.is_file() else ''
    version=re.search(r'(?mi)^\s*(?:\*\s*)?Version:\s*(\S+)',content)
    entry={'present':mainfile.is_file(),'version':version.group(1) if version else None}
    if mainfile.is_file():
        try:
            cp=subprocess.run(['php','-l',str(mainfile)],capture_output=True,timeout=12)
            entry['main_php_lint_pass']=cp.returncode==0
        except (OSError,subprocess.TimeoutExpired):
            entry['main_php_lint_pass']=False
    if name=='mad4b-site-control-plane' and base.is_dir():
        provenance=base/'MAD4B-BUILD-PROVENANCE.json'
        if provenance.is_file():
            try:
                package=json.loads(provenance.read_text())
                yes=0;missing=0;drift=0
                for record in package.get('package_files',[]):
                    candidate=base/record['path']
                    if not candidate.is_file():missing+=1;continue
                    if (candidate.stat().st_size==record['bytes'] and
                        hashlib.sha256(candidate.read_bytes()).hexdigest()==record['sha256']):
                        yes+=1
                    else:drift+=1
                entry.update({'manifest_version':package.get('control_plane_version'),
                              'manifest_files_matching':yes,
                              'manifest_files_modified':drift,
                              'manifest_files_missing':missing})
            except (OSError,ValueError,KeyError,TypeError):
                entry['manifest_error']='CORRUPT_PROVENANCE'
    plugins[name]=entry
boot_code="echo 'MAD4B_RECOVERY_BOOT:' . (class_exists('MAD4B_SCP_Servers',false)?'1':'0');"
boot_status,boot_output=wp(['--skip-themes','eval',boot_code],22) if matched else (75,'')
markers=[v for v in boot_output.splitlines() if v.startswith('MAD4B_RECOVERY_BOOT:')]
boot=(boot_status==0 and markers==['MAD4B_RECOVERY_BOOT:1'])
state=('IDENTITY_NOT_VERIFIED' if not matched else
       'CONTROL_PLANE_BOOT_FAILED' if not boot else
       'MISSING_PLUGIN_FILE' if not all(p['present'] for p in plugins.values()) else
       'LIVE_HOTFIX_DRIFT' if plugins['mad4b-site-control-plane'].get('manifest_files_modified',0)>0
       else 'STAGING_BOOT_VERIFIED')
print(json.dumps({'contract':'mad4b.cross-ssh-out-of-band-doctor.v1',
    'state':state,'identity_verified':bool(matched),
    'wpcli_without_plugins':status==0,'control_plane_boot_verified':boot,
    'target_site_uuid_expected':expected,'target_site_uuid_observed':facts.get('site_uuid'),
    'canonical_origin':origin,'wordpress_user_required':False,
    'plugins':plugins,'write_authorized':False,'mutation_performed':False},sort_keys=True))
"""

SAFE_FILE = re.compile(r"^[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}[.]zip$")
TIMEOUT = 45
def ssh_base(name):
    item = TARGETS[name]
    keydir = ENROLLED / name.split(":")[0]
    key = keydir / "id_ed25519"
    pin = keydir / "known_hosts_verified"
    if not key.is_file() or not pin.is_file() or (key.stat().st_mode & 0o077):
        raise ValueError("registered_ssh_identity_unavailable")
    return (["ssh", "-F", "/dev/null", "-T", "-p", str(item["port"]), "-o", "BatchMode=yes",
        "-o", "IdentitiesOnly=yes", "-o", "StrictHostKeyChecking=yes",
        "-o", "UpdateHostKeys=no", "-o", "UserKnownHostsFile=" + str(pin),
        "-o", "ConnectTimeout=8", "-i", str(key)], item["user"] + "@" + item["host"], key, pin)
def run(argv, timeout=TIMEOUT):
    cp = subprocess.run(argv, capture_output=True, text=True, timeout=timeout, stdin=subprocess.DEVNULL)
    if cp.returncode:
        raise RuntimeError("remote_transport_failed:" + str(cp.returncode))
    return cp.stdout.strip()
def remote(name, cmd, timeout=TIMEOUT):
    argv, target, _, _ = ssh_base(name)
    return run(argv + [target, cmd], timeout=timeout)
def guard(name):
    root = TARGETS[name]["root"]
    checked = remote(name, "realpath " + shlex.quote(root + "/wp-content/uploads"))
    if checked != root + "/wp-content/uploads":
        raise ValueError("staging_scope_mismatch")
    return root
def digest(path):
    h = hashlib.sha256()
    with path.open("rb") as src:
        for chunk in iter(lambda: src.read(65536), b""):
            h.update(chunk)
    return h.hexdigest()
def artifact_path(filename):
    if not SAFE_FILE.fullmatch(filename):
        raise ValueError("invalid_artifact_name")
    p = ARTIFACTS / filename
    if not p.is_file() or p.is_symlink() or p.stat().st_size > 25 * 1024 * 1024:
        raise ValueError("artifact_missing_or_oversize")
    return p
def remote_file(name, filename):
    root = guard(name)
    return root + "/wp-content/uploads/mad4b-central-transport/" + filename
def readback(name, filename, expected):
    target = remote_file(name, filename)
    received = remote(name, "sha256sum " + shlex.quote(target)).split()[0]
    if received != expected:
        raise ValueError("checksum_mismatch")
    return {"status": "READBACK_VERIFIED", "target": name, "remote_path": target,
            "sha256": received, "production_mutation": False}
def transfer(name, filename, expected):
    p = artifact_path(filename)
    actual = digest(p)
    if actual != expected:
        raise ValueError("source_checksum_mismatch")
    destination = remote_file(name, filename)
    directory = destination.rsplit("/", 1)[0]
    remote(name, "mkdir -p " + shlex.quote(directory))
    if remote(name, "realpath " + shlex.quote(directory)) != directory:
        raise ValueError("destination_scope_mismatch")
    argv, target, key, pin = ssh_base(name)
    tmp = destination + ".transfer"
    scp = ["scp", "-B", "-P", str(TARGETS[name]["port"]),
           "-o", "IdentitiesOnly=yes", "-o", "StrictHostKeyChecking=yes",
           "-o", "UserKnownHostsFile=" + str(pin), "-i", str(key),
           str(p), target + ":" + tmp]
    run(scp)
    if remote(name, "sha256sum " + shlex.quote(tmp)).split()[0] != expected:
        raise ValueError("uploaded_checksum_mismatch")
    remote(name, "mv " + shlex.quote(tmp) + " " + shlex.quote(destination))
    return readback(name, filename, expected)
def doctor(name):
    if name not in TARGETS or name not in ORIGINS:
        raise ValueError("DOCTOR_EXACT_STAGING_TARGET_REQUIRED")
    root = guard(name)
    command = "python3 -c " + shlex.quote(DOCTOR_SCRIPT) + " " + " ".join(
        shlex.quote(x) for x in (root, TARGETS[name]["site_uuid"], ORIGINS[name]))
    raw = remote(name, command)
    output = json.loads(raw.splitlines()[-1])
    if (output.get("contract") != "mad4b.cross-ssh-out-of-band-doctor.v1"
        or output.get("target_site_uuid_expected") != TARGETS[name]["site_uuid"]
        or output.get("canonical_origin") != ORIGINS[name]
        or output.get("mutation_performed") is not False
        or output.get("write_authorized") is not False):
        raise ValueError("DOCTOR_READBACK_CONTRACT_INVALID")
    output["target"] = name
    output["origin"] = ORIGINS[name]
    return output


# No generic shell, arbitrary SQL or FTP write surface. A repaired file must be
# an exact pre-existing MAD4B plugin file of the SAME package version, and the
# original bytes are retained outside the publicly served document root.
SOURCE_PLUGIN_ROOT = Path(
    "/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content/plugins")
RECOVERY_PLUGINS = {
    "mad4b-site-control-plane": "mad4b-site-control-plane.php",
    "mcp-adapter": "mcp-adapter.php",
}
RECOVERY_RELATIVE = re.compile(
    r"^(?:includes/(?:[a-zA-Z0-9_-]+/)*[a-zA-Z0-9_.-]+[.]php|"
    r"config/[a-zA-Z0-9_.-]+[.]json)$")
REPAIR_SCRIPT = r"""
import hashlib,json,os,shutil,stat,subprocess,sys,time
from pathlib import Path
root=Path(sys.argv[1]);slug=sys.argv[2];rel=sys.argv[3]
expected_old=sys.argv[4];expected_new=sys.argv[5]
staged=Path(sys.argv[6]); backup_root=Path(sys.argv[7])
target=root/'wp-content/plugins'/slug/rel
for part in [root/'wp-content',root/'wp-content/plugins',
             root/'wp-content/plugins'/slug,*list(target.parents)[:2]]:
    if part.is_symlink():raise RuntimeError('RECOVERY_SYMLINKED_PLUGIN_PATH_DENIED')
if backup_root.is_symlink():raise RuntimeError('RECOVERY_SYMLINKED_BACKUP_DENIED')
def digest(p):
    h=hashlib.sha256()
    with p.open('rb') as f:
        for data in iter(lambda:f.read(65536),b''):h.update(data)
    return h.hexdigest()
if (not target.is_file() or target.is_symlink() or not staged.is_file()
    or staged.is_symlink() or not target.resolve().is_relative_to(
        (root/'wp-content/plugins'/slug).resolve())):
    raise RuntimeError('EXACT_EXISTING_PLUGIN_TARGET_REQUIRED')
if digest(target)!=expected_old or digest(staged)!=expected_new:
    raise RuntimeError('RECOVERY_CAS_DIGEST_MISMATCH')
if staged.stat().st_size>2_000_000:
    raise RuntimeError('RECOVERY_SOURCE_TOO_LARGE')
if rel.endswith('.php'):
    lint=subprocess.run(['php','-l',str(staged)],capture_output=True,timeout=20)
    if lint.returncode:raise RuntimeError('REPLACEMENT_PHP_SYNTAX_INVALID')
backup_root.mkdir(mode=0o700,parents=True,exist_ok=True)
os.chmod(backup_root,0o700)
stamp=str(int(time.time()))+'-'+expected_old[:12]
backup=backup_root/(slug+'-'+stamp+'.bak')
if backup.exists():raise RuntimeError('RECOVERY_BACKUP_ALREADY_EXISTS')
shutil.copy2(target,backup)
os.chmod(backup,0o600)
temp=target.with_name('.'+target.name+'.mad4b-recovery-'+stamp)
try:
    flags=os.O_CREAT|os.O_EXCL|os.O_WRONLY
    if hasattr(os,'O_NOFOLLOW'):flags|=os.O_NOFOLLOW
    fd=os.open(temp,flags,0o600)
    with os.fdopen(fd,'wb') as dst,staged.open('rb') as src:
        shutil.copyfileobj(src,dst)
        dst.flush();os.fsync(dst.fileno())
    os.chmod(temp,stat.S_IMODE(target.stat().st_mode))
    os.replace(temp,target)
    if digest(target)!=expected_new:raise RuntimeError('REPLACEMENT_READBACK_MISMATCH')
    wp=subprocess.run(['wp','--path='+str(root),'--skip-themes','eval',
        "echo 'MAD4B_RECOVERY_BOOT:' . (class_exists('MAD4B_SCP_Servers',false)?'1':'0');"],
        capture_output=True,text=True,timeout=35)
    verified=(wp.returncode==0 and
        'MAD4B_RECOVERY_BOOT:1' in wp.stdout.splitlines())
    if not verified:raise RuntimeError('AFTER_REPAIR_WORDPRESS_BOOT_FAILED')
    print(json.dumps({'contract':'mad4b.cross-ssh-recovery-file.v1',
        'state':'EXACT_STAGING_FILE_REPLACED_AND_BOOT_VERIFIED',
        'new_sha256':digest(target),'old_sha256':expected_old,
        'backup_preserved':True,'rollback_path':str(backup),
        'site_mutated':True,'grant_issued':False,'wp_user_required':False}))
except Exception:
    shutil.copy2(backup,temp)
    os.replace(temp,target)
    if digest(target)!=expected_old:raise RuntimeError('RECOVERY_ROLLBACK_VERIFICATION_FAILED')
    print(json.dumps({'contract':'mad4b.cross-ssh-recovery-file.v1',
        'state':'REPAIR_REJECTED_AUTOMATIC_ROLLBACK_VERIFIED',
        'new_sha256':digest(target),'old_sha256':expected_old,
        'backup_preserved':True,'site_mutated':False,
        'grant_issued':False,'wp_user_required':False}))
finally:
    if temp.exists():temp.unlink()
    if staged.exists():staged.unlink()
"""


def _plugin_version(content):
    match = re.search(r"(?mi)^\s*(?:\*\s*)?Version:\s*(\S+)", content[:12000])
    if not match:
        raise ValueError("RECOVERY_PLUGIN_VERSION_UNAVAILABLE")
    return match.group(1)


def file_repair_plan(name, plugin, relative):
    if name not in TARGETS or plugin not in RECOVERY_PLUGINS:
        raise ValueError("RECOVERY_EXACT_STAGING_PLUGIN_REQUIRED")
    if not isinstance(relative, str) or not (RECOVERY_RELATIVE.fullmatch(relative) or relative == RECOVERY_PLUGINS[plugin]):
        raise ValueError("RECOVERY_EXACT_PLUGIN_RELATIVE_FILE_REQUIRED")
    site = doctor(name)
    if site.get("identity_verified") is not True or site.get("wpcli_without_plugins") is not True:
        raise ValueError("RECOVERY_HOST_SITE_SCOPE_NOT_VERIFIED")
    src = SOURCE_PLUGIN_ROOT / plugin / relative
    if not src.is_file() or src.is_symlink() or src.stat().st_size > 2_000_000:
        raise ValueError("RECOVERY_SOURCE_FILE_NOT_TRUSTED")
    source_version = _plugin_version((SOURCE_PLUGIN_ROOT / plugin
                                      / RECOVERY_PLUGINS[plugin]).read_text())
    remote_version = (site["plugins"][plugin].get("version")
                      or site["plugins"][plugin].get("manifest_version"))
    if remote_version != source_version:
        raise ValueError("RECOVERY_DIFFERENT_PLUGIN_VERSION_PACKAGE_REPLACEMENT_REQUIRED")
    root = guard(name)
    target = root + "/wp-content/plugins/" + plugin + "/" + relative
    current = remote(name, "sha256sum " + shlex.quote(target)).split()
    if len(current) < 2 or not re.fullmatch(r"[a-f0-9]{64}",current[0]):
        raise ValueError("RECOVERY_CURRENT_FILE_UNAVAILABLE")
    new = digest(src)
    plan = {
        "contract": "mad4b.cross-ssh-file-repair-plan.v1",
        "target": name, "site_uuid": TARGETS[name]["site_uuid"],
        "origin": ORIGINS[name], "environment": "staging",
        "plugin":plugin, "plugin_version":source_version, "relative":relative,
        "old_sha256":current[0], "replacement_sha256":new,
        "state":"ALREADY_CURRENT" if current[0]==new else "EXACT_STAGING_REPAIR_READY",
        "wordpress_user_required":False, "mcp_oauth_required":False,
        "rollback_required":True, "mutation_performed":False,
    }
    plan["plan_sha256"] = hashlib.sha256(
        json.dumps(plan,sort_keys=True,separators=(",",":")).encode()).hexdigest()
    return plan


def file_repair_apply(name, plugin, relative, expected_plan):
    plan = file_repair_plan(name,plugin,relative)
    if plan["plan_sha256"] != expected_plan or plan["state"] != "EXACT_STAGING_REPAIR_READY":
        raise ValueError("RECOVERY_EXACT_CURRENT_PLAN_AND_CHANGED_FILE_REQUIRED")
    # Same pinned registered SSH credentials used for normal cross-host WPCLI.
    src = SOURCE_PLUGIN_ROOT / plugin / relative
    _, endpoint, key, pin = ssh_base(name)
    token = plan["plan_sha256"][:18]
    user = TARGETS[name]["user"]
    backup_root = "/home/" + user + "/.mad4b-recovery"
    incoming = backup_root + "/.incoming-" + token
    remote(name, "mkdir -p -m 700 " + shlex.quote(backup_root))
    scp = ["scp","-B","-P",str(TARGETS[name]["port"]),
           "-o","BatchMode=yes","-o","IdentitiesOnly=yes",
           "-o","StrictHostKeyChecking=yes",
           "-o","UserKnownHostsFile="+str(pin),"-i",str(key),
           str(src),endpoint+":"+incoming]
    run(scp)
    command = "python3 -c " + shlex.quote(REPAIR_SCRIPT) + " " + " ".join(
        shlex.quote(x) for x in (
            TARGETS[name]["root"],plugin,relative,plan["old_sha256"],
            plan["replacement_sha256"],incoming,backup_root))
    output = json.loads(remote(name,command,timeout=95).splitlines()[-1])
    if (output.get("contract")!="mad4b.cross-ssh-recovery-file.v1"
        or output.get("state") not in ("EXACT_STAGING_FILE_REPLACED_AND_BOOT_VERIFIED",
                                       "REPAIR_REJECTED_AUTOMATIC_ROLLBACK_VERIFIED")
        or output.get("old_sha256")!=plan["old_sha256"]
        or output.get("grant_issued") is not False
        or output.get("wp_user_required") is not False):
        raise ValueError("RECOVERY_RECEIPT_INVALID")
    output["target"] = name
    output["plan_sha256"] = expected_plan
    return output


def main():
    p = argparse.ArgumentParser()
    p.add_argument("action", choices=("probe", "doctor", "repair-plan", "repair-apply", "remote-exec", "transfer", "readback"))
    p.add_argument("--target", choices=tuple(TARGETS), required=True)
    p.add_argument("--operation", choices=("site-root", "wp-cli-version"), default="site-root")
    p.add_argument("--filename", default="")
    p.add_argument("--sha256", default="")
    p.add_argument("--plugin", choices=tuple(RECOVERY_PLUGINS),default="")
    p.add_argument("--relative-file",default="")
    p.add_argument("--expected-plan-sha256",default="")

    a = p.parse_args()
    if a.action == "probe":
        output = {"state": "SSH_AUTHENTICATED", "root": guard(a.target), "target": a.target}
    elif a.action == "doctor":
        output = doctor(a.target)
    elif a.action == "repair-plan":
        output = file_repair_plan(a.target,a.plugin,a.relative_file)
    elif a.action == "repair-apply":
        if not re.fullmatch(r"[0-9a-f]{64}",a.expected_plan_sha256):
            raise ValueError("RECOVERY_EXACT_PLAN_SHA_REQUIRED")
        output = file_repair_apply(a.target,a.plugin,a.relative_file,a.expected_plan_sha256)
    elif a.action == "remote-exec":
        root = guard(a.target)
        if a.operation == "site-root":
            result = remote(a.target, "realpath " + shlex.quote(root))
        else:
            result = remote(a.target, "wp --info")
        output = {"state": "EXECUTION_VERIFIED", "operation": a.operation,
                  "result": result[:4096], "target": a.target}
    elif not re.fullmatch(r"[a-f0-9]{64}", a.sha256):
        raise ValueError("expected_sha256_required")
    elif a.action == "transfer":
        output = transfer(a.target, a.filename, a.sha256)
    else:
        output = readback(a.target, a.filename, a.sha256)
    print(json.dumps(output, ensure_ascii=False, sort_keys=True))
if __name__ == "__main__":
    try:
        main()
    except (OSError, subprocess.TimeoutExpired, ValueError, RuntimeError, KeyError) as e:
        print(json.dumps({"state": "FAIL_CLOSED", "reason": str(e)[:180]}))
        sys.exit(75)
