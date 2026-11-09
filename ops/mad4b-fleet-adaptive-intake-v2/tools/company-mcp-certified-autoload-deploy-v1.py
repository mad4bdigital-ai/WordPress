#!/usr/bin/env python3
"""Bound Staging-only pinned SSH deployment of a certified MCP MU boot fix.

Atomic same-directory replacement with immutable precondition, private backup,
PHP lint and postcondition SHA; no grants, users or adapter vendor edits.
"""
import argparse,hashlib,importlib.util,json,shlex,subprocess
from pathlib import Path
BASE=Path('/opt/mad4b-company-provisioning')
SOURCE=BASE/'reports/mcp-hostinger-autoload-fix-20261108/etg-mu-candidate.php'
FILE='wp-content/mu-plugins/000-mad4b-mcp-adapter-bootstrap.php'
OLD='4514997e25765f55bb46c04d1a2af426f278369d1a87679bfc6088b710c38f32'
def sha(b):return hashlib.sha256(b).hexdigest()
def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--site',required=True,help='Exact enrolled Staging company_id from site binding policy')
    ap.add_argument('--mode',choices=['plan','apply'],default='plan')
    a=ap.parse_args()
    sites=json.load(open(BASE/'policies/company-hostinger-site-binding-map-v1.json'))['sites']
    entry=next((r for r in sites if r['company_id']==a.site and r['environment']=='staging'),None)
    if not entry:raise RuntimeError('EXACT_STAGING_SITE_PROFILE_NOT_ENROLLED')
    data=SOURCE.read_bytes()
    new=sha(data)
    spec=importlib.util.spec_from_file_location('r',str(BASE/'bin/company-hostinger-two-route-binding-v1.py'))
    r=importlib.util.module_from_spec(spec);spec.loader.exec_module(r)
    path=entry['ssh_root']+'/'+FILE
    remote=r.ssh_cmd(entry['ssh_target'])
    cmd=['python3','-c',REMOTE,path,OLD,new,a.mode]
    p=subprocess.run(remote+[shlex.join(cmd)],input=data,capture_output=True,timeout=50)
    if p.returncode:
        print(json.dumps({'site':a.site,'state':'REJECTED','exit':p.returncode,'stderr_tail':p.stderr[-220:].decode(errors='replace')}))
        raise SystemExit(2)
    out=json.loads(p.stdout.decode().strip())
    out.update({'site':a.site,'source_sha256':OLD,'target_sha256':new,'production_changed':False,'grants_created':False})
    print(json.dumps(out,sort_keys=True))
REMOTE=r"""
import sys,hashlib,json,os,pathlib,stat,tempfile,subprocess,shutil
target=pathlib.Path(sys.argv[1]);old=sys.argv[2];new=sys.argv[3];mode=sys.argv[4]
data=sys.stdin.buffer.read()
hashb=lambda b:hashlib.sha256(b).hexdigest()
if hashb(data)!=new:raise RuntimeError('INCOMING_SHA_REJECTED')
if not target.is_file() or target.is_symlink():raise RuntimeError('TARGET_NOT_REGULAR_FILE')
current=target.read_bytes()
if hashb(current)!=old and hashb(current)!=new:raise RuntimeError('LIVE_BASE_CHANGED')
home=pathlib.Path.home()
backup=home/'.mad4b-recovery'/('mcp-mu-boot-autoload-before-'+new[:16]+'.php')
if not backup.parent.is_dir() or backup.parent.is_symlink():raise RuntimeError('BACKUP_DIR_NOT_PRESENT')
if mode=='plan':
 print(json.dumps({'state':'VERIFIED_PLAN','current':hashb(current),'matches_original':hashb(current)==old}))
 sys.exit(0)
if hashb(current)==new:
 print(json.dumps({'state':'ALREADY_MATCHES','current':new}))
 sys.exit(0)
if backup.exists():raise RuntimeError('BACKUP_COLLISION')
fd,tmp=tempfile.mkstemp(prefix='.mad4b-mcp-autoload-',suffix='.php',dir=target.parent)
try:
 with os.fdopen(fd,'wb') as f:
  f.write(data);f.flush();os.fsync(f.fileno())
 os.chmod(tmp,stat.S_IMODE(target.stat().st_mode))
 syntax=subprocess.run(['php','-l',tmp],capture_output=True,text=True,timeout=16)
 if syntax.returncode:raise RuntimeError('REMOTE_PHP_LINT_FAILED')
 bfd=os.open(str(backup),os.O_CREAT|os.O_EXCL|os.O_WRONLY,0o600)
 with os.fdopen(bfd,'wb') as f:
  f.write(current);f.flush();os.fsync(f.fileno())
 if hashb(target.read_bytes())!=old:raise RuntimeError('RACE_BEFORE_REPLACE')
 os.replace(tmp,target)
 if hashb(target.read_bytes())!=new:raise RuntimeError('POST_REPLACE_READBACK_FAILED')
 print(json.dumps({'state':'DEPLOYED_WITH_BACKUP','backup':str(backup),'sha256':new,'php_lint':'PASS'}))
finally:
 if os.path.exists(tmp):os.unlink(tmp)
"""
if __name__=='__main__':main()
