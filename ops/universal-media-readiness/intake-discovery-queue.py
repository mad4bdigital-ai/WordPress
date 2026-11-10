#!/usr/bin/env python3
"""Trusted host Media Intake discovery for all registered WP types; never imports/publishes."""
from pathlib import Path
from datetime import datetime,timezone
import fcntl,hashlib,json,os,re,subprocess,time
FLEET=Path('/opt/mad4b-company-provisioning/releases/configuration-jobs-v1/fleet-sites.json')
ROOT=Path('/opt/mad4b-company-provisioning/reports/media-universal-20261110/intake')
CONTRACT='mad4b.universal-media-intake-discovery.v2'
RESOLVER_VERSION='semantic-candidate-precision-20261110-v2'
UUID=re.compile(r'^[a-f0-9-]{36}$')

def run(container,args,timeout=60):
    result=subprocess.run(['docker','exec','-u','www-data',container,'wp','--path=/var/www/html','mad4b','universal-media']+args,
        capture_output=True,text=True,timeout=timeout)
    if result.returncode:raise RuntimeError('wpcli_failed_'+str(result.returncode))
    lines=[json.loads(x) for x in result.stdout.splitlines() if x.strip().startswith('{')]
    if len(lines)!=1:raise RuntimeError('unexpected_wpcli_response_count')
    return lines[0]

def main():
    ROOT.mkdir(parents=True,exist_ok=True,mode=0o700)
    with open(ROOT/'intake.lock','a+') as lock:
        try:fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError:print('INTAKE_ALREADY_RUNNING');return
        sites=json.loads(FLEET.read_text())
        if sites.get('contract')!='mad4b.configuration-host-collector-sources.v1':raise RuntimeError('untrusted_fleet_registry')
        result=[]
        for site in sites['local_sites']:
            company=site['company_code'];env=site['environment'];container=site['container'];uuid=site['site_uuid']
            if not UUID.fullmatch(uuid) or not re.fullmatch(r'[a-z0-9-]{2,40}',company) or not re.fullmatch(r'php-[a-z0-9]+',container):raise RuntimeError('invalid_fleet_identity')
            path=ROOT/(company+'-'+env);path.mkdir(exist_ok=True,mode=0o700)
            scan=run(container,['scan'],timeout=100)
            if not scan.get('complete') or scan.get('contract')!='mad4b.universal-media-readiness.v1':raise RuntimeError('media_scan_incomplete_'+company)
            pending=sorted(scan['items'],key=lambda x:({'P0':0,'P1':1,'P2':2,'P3':3}.get(x['priority'],4),x['kind'],x['type'],x['id']))
            processed=0;reused=0;retry_later=0;attempted=0
            for obj in pending:
                if attempted>=4:break
                kind=obj['kind'];typ=obj['type'];oid=int(obj['id']);priority=obj['priority']
                if kind not in ('post','term') or not re.fullmatch(r'[a-z0-9_-]{2,64}',typ) or oid<=0:continue
                key=f'{kind}-{typ}-{oid}-{priority}-{RESOLVER_VERSION}';dest=path/(key+'.json')
                # Reuse same-priority evidence for at most 24 h; a newly
                # eligible P0 immediately gets a NEW intake artifact.
                if dest.exists() and time.time()-dest.stat().st_mtime<86400:
                    reused+=1;continue
                attempted+=1
                try:
                    local=run(container,['candidates',kind,typ,str(oid),'--limit=12'],timeout=65)
                except (RuntimeError,subprocess.TimeoutExpired):
                    retry_later+=1;continue  # Never claim absence after incomplete local search.
                if local.get('contract')!='mad4b.universal-media-readiness.v1' or local.get('object',{}).get('id')!=oid:raise RuntimeError('local_candidate_identity_mismatch')
                external={'status':'not_needed_or_deferred','candidates':[],'external_scraping_performed':False}
                if not local.get('candidates'):
                    try:
                        external=run(container,['external',kind,typ,str(oid),'--limit=5'],timeout=45)
                    except (RuntimeError,subprocess.TimeoutExpired):
                        retry_later+=1
                        # The local search was successful. Preserve its result,
                        # but do NOT mark external discovery complete or claim
                        # that no licensed image exists.
                        continue
                    if external.get('contract')!='mad4b.universal-media-readiness.v1' or external.get('object',{}).get('id')!=oid:raise RuntimeError('external_candidate_identity_mismatch')
                evidence={'contract':CONTRACT,'site_uuid':uuid,'company':company,'environment':env,'at':datetime.now(timezone.utc).isoformat(),
                    'object':obj,'resolver_version':RESOLVER_VERSION,'local_discovery':local,'external_discovery':external,
                    'status':'review_required' if local.get('candidates') or external.get('candidates') else 'asset_request_required',
                    'photo_rights_verified':False,'visual_subject_verified':False,
                    'attachment_created':False,'wordPress_media_mutated':False,'auto_apply_authorized':False}
                temp=path/(key+'.tmp');temp.write_text(json.dumps(evidence,ensure_ascii=False,indent=2));os.chmod(temp,0o600);temp.replace(dest)
                processed+=1
            result.append({'site':company+':'+env,'uuid':uuid,'pending_objects':len(pending),'new_intake_artifacts':processed,'reused_recent':reused,'remaining_queue':max(0,len(pending)-processed-reused),'retry_later':retry_later,'attempted':attempted})
        print(json.dumps({'contract':CONTRACT,'results':result,'publication_performed':False,'at':datetime.now(timezone.utc).isoformat()}))
if __name__=='__main__':main()
