#!/usr/bin/env python3
"""MAD4B native UAEL Home CSS proof lifecycle (off-request, no auto CSS release).

Reuses the original Wayfa MU394 graph builder and independent readback.
A changed template/asset produces *candidate.json*; never alters production CSS.
Only an otherwise identical, still-active proof may be automatically renewed
before its 7-day TTL expires, under exact-byte compare-and-swap.
"""
import argparse,datetime,fcntl,hashlib,json,os,pathlib,subprocess,sys,tempfile,time
P=pathlib.Path('/opt/wayfa-rebuild-20261102/site-copy/wp-content')
M=P/'mad4b-uael-home-v1/manifest.json'
C=P/'mad4b-uael-home-v1/candidate.json'
B=P/'mu-plugins/mad4b-uael-home-selective/builder.php'
RB='/var/www/html/wp-content/mu-plugins/tools/mad4b-uael-home-readback-v1.php'
CONTAINER='php-guxhp9y6vgh7oouuw8plxrlr'
LOCK='/run/lock/mad4b-uael-wayfatours-proof-v1.lock'
REPORT=pathlib.Path('/opt/mad4b-company-provisioning/reports/mobile-coverage-audit-20261114/uael-home-lifecycle-last.json')
CURRENT_SHA='ff78a7d4aa3cdc1d144dfac844a91a6699be15bae7fc11d2f720132c5fed8ca5'
SHA=lambda data:hashlib.sha256(data).hexdigest()

def read_json(p):
    if p.is_symlink() or not p.is_file() or p.stat().st_size>65536:raise ValueError('PROOF_UNREADABLE:'+p.name)
    return json.loads(p.read_text())

def run(argv,timeout=65):
    r=subprocess.run(argv,capture_output=True,text=True,timeout=timeout,check=False)
    if r.returncode:raise RuntimeError('CHILD_FAILED:'+str(r.returncode)+':'+(r.stdout+r.stderr)[-440:])
    for line in reversed(r.stdout.splitlines()):
        try:
            v=json.loads(line)
            if isinstance(v,dict):return v
        except json.JSONDecodeError:pass
    raise RuntimeError('CHILD_MISSING_JSON')

def result(state,**kw):
    data={'contract':'mad4b.uael.home.candidate-lifecycle.v1','state':state,'generated_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),**kw}
    if not REPORT.parent.is_dir():raise RuntimeError('REPORT_DIRECTORY_MISSING')
    fd,tmp=tempfile.mkstemp(dir=REPORT.parent,prefix='.uael-cycle-')
    try:
        with os.fdopen(fd,'w') as f:json.dump(data,f,sort_keys=True,indent=2);f.write('\n');f.flush();os.fsync(f.fileno())
        os.chmod(tmp,0o600);os.replace(tmp,REPORT)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)
    print(json.dumps(data,sort_keys=True))
    return 0

def main():
    args=argparse.ArgumentParser();args.add_argument('--audit-only',action='store_true');args.add_argument('--force-candidate',action='store_true');v=args.parse_args()
    if B.is_symlink() or not B.is_file() or SHA(B.read_bytes())!=CURRENT_SHA:raise RuntimeError('BUILDER_SHA_DRIFT')
    with open(LOCK,'a+') as lock:
        try:fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError:return result('already_running')
        load=os.getloadavg()[0]
        avail=int(next(l.split()[1] for l in open('/proc/meminfo') if l.startswith('MemAvailable:')))/1048576
        if load>16 or avail<4:return result('resource_hold',load_1m=round(load,2),available_gib=round(avail,2))
        site=run(['docker','exec','-u','33:33',CONTAINER,'wp','--path=/var/www/html','--skip-themes','eval',
          '$m=WP_CONTENT_DIR."/mad4b-uael-home-v1/manifest.json";$d=is_file($m)?json_decode(file_get_contents($m),true):[];echo wp_json_encode(["host"=>home_url(),"mode"=>get_option("mad4b_uael_home_selective_v1","off"),"epoch"=>(string)get_option("mad4b_uael_home_tree_epoch_v1","0"),"uael"=>defined("UAEL_VER")?UAEL_VER:"","popup"=>(int)($d["popup_id"]??0)]).PHP_EOL;'],45)
        if site['host']!='https://wayfatours.com' or site['mode']!='on' or site['popup']<1 or not site['uael']:raise RuntimeError('COMPANY_POLICY_NOT_ADMITTED')
        live=read_json(M)
        if live.get('site_home')!=site['host'] or live.get('contract')!='mad4b.uael-home.manifest.v1':raise RuntimeError('LIVE_CONTRACT_DRIFT')
        epoch=site['epoch'];now=time.time();live_date=datetime.datetime.fromisoformat(live['generated_at']).timestamp()
        live_age=now-live_date
        candidate=read_json(C) if C.exists() else {}
        need=(v.force_candidate or not candidate or candidate.get('epoch')!=epoch
              or candidate.get('uael_version')!=site['uael']
              or (now-datetime.datetime.fromisoformat(candidate['generated_at']).timestamp()>5*86400))
        if live.get('epoch')==epoch and live_age<5*86400 and not need:return result('stable_current_proof',epoch=epoch,live_sha=SHA(M.read_bytes()))
        if v.audit_only:return result('audit_candidate_pending' if need else 'audit_candidate_ready',epoch=epoch,live_epoch=live.get('epoch'),live_age_days=round(live_age/86400,2),candidate_ready=bool(candidate.get('ready')),would_build=bool(need))
        if need:
            run(['docker','exec','-e','MAD4B_UAEL_MANIFEST_CANDIDATE=1','-u','33:33',CONTAINER,'wp','--path=/var/www/html','--skip-themes','eval-file','/var/www/html/wp-content/mu-plugins/mad4b-uael-home-selective/builder.php',str(site['popup'])],65)
            candidate=read_json(C)
        proof=run(['docker','exec','-u','33:33',CONTAINER,'wp','--path=/var/www/html','--skip-themes','eval-file',RB,'/var/www/html/wp-content/mad4b-uael-home-v1/candidate.json'],65)
        if proof.get('status')!='READY' or not candidate.get('ready') or candidate.get('issues') or candidate.get('epoch')!=epoch:raise RuntimeError('CANDIDATE_READBACK_FAILED')
        # Off-request: no automatic CSS source changes. Manual release requires
        # exact new LiteSpeed SHA + critical CSS/browser QA admitted externally.
        compare=lambda x:{k:a for k,a in x.items() if k!='generated_at'}
        same=compare(live)==compare(candidate)
        if not same or live.get('epoch')!=epoch:
            return result('candidate_ready_css_requalification_hold',site=site['host'],epoch=epoch,live_epoch=live.get('epoch'),template_count=proof.get('template_count'),candidate_sha=SHA(C.read_bytes()),live_sha=SHA(M.read_bytes()),public_css_mutated=False)
        if not (5*86400<=live_age<7*86400):
            return result('candidate_ready_no_safe_promotion',live_age_days=round(live_age/86400,2),same_graph=True,public_css_mutated=False)
        # Same graph, same CSS, same epoch, still valid runtime proof. Renewal
        # before TTL expiry does not cause effective CSS strategy transition.
        snap=M.read_bytes();updated=C.read_bytes();file_stat=M.stat()
        if SHA(M.read_bytes())!=SHA(snap):raise RuntimeError('MANIFEST_CAS_CHANGED')
        fd,tmp=tempfile.mkstemp(dir=M.parent,prefix='.uael-safe-ttl-')
        try:
            with os.fdopen(fd,'wb') as f:
                os.fchown(f.fileno(),file_stat.st_uid,file_stat.st_gid);os.fchmod(f.fileno(),file_stat.st_mode&0o777)
                f.write(updated);f.flush();os.fsync(f.fileno())
            if SHA(M.read_bytes())!=SHA(snap):raise RuntimeError('MANIFEST_CONCURRENT_EDIT')
            os.replace(tmp,M)
        finally:
            if os.path.exists(tmp):os.unlink(tmp)
        if SHA(M.read_bytes())!=SHA(updated):raise RuntimeError('MANIFEST_PROMOTION_READBACK')
        return result('ttl_proof_renewed_identical_graph',prior_sha=SHA(snap),new_sha=SHA(updated),epoch=epoch,public_css_mutated=False)

if __name__=='__main__':
 try:sys.exit(main())
 except Exception as e:
  print(json.dumps({'contract':'mad4b.uael.home.candidate-lifecycle.v1','state':'failure','reason':str(e)[:400]}));sys.exit(3)
