#!/usr/bin/env python3
"""MAD4B source-backed tour media audit, bounded and resumable.

Read-only across two enrolled Company sites. It *never* applies images or
claims visual/photo provenance. Atomic cursor with site/host binding,
backoff for WP-CLI concurrency, and daily resumability.
"""
import argparse
import fcntl
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
from datetime import datetime, timezone, timedelta

ROOT=Path('/opt/mad4b-company-provisioning/reports/tour-media-portfolio-sweep')
CONTRACT='mad4b.media-portfolio-sweep.v1'
SITE={
  'wayfa':{
    'label':'wayfatours-production',
    'site_url':'https://wayfatours.com',
    'site_uuid':'a3a7cc36-db7e-4a14-8456-b41857def05c',
    'expected_review':'human_only',
  },
  'toursdev':{
    'label':'tours-dev-existing-compose-adoption-draft',
    'site_url':'https://tours.dev.mad4b.com',
    'site_uuid':'e5e64bca-ed62-48d2-b2b3-dff693cd702a',
    'expected_review':'ai_only',
  }
}
def utc():
    return datetime.now(timezone.utc).isoformat()

def write_atomic(path,body):
    path.parent.mkdir(parents=True,exist_ok=True,mode=0o750)
    fd,name=tempfile.mkstemp(prefix='.mad4b-',dir=str(path.parent))
    try:
        with os.fdopen(fd,'w') as f:
            json.dump(body,f,ensure_ascii=False,indent=2,sort_keys=True)
            f.write('\n')
            f.flush()
            os.fsync(f.fileno())
        os.chmod(name,0o640)
        os.replace(name,path)
    finally:
        if os.path.exists(name):os.unlink(name)

def run(args,timeout=80):
    return subprocess.run(args,capture_output=True,text=True,timeout=timeout,check=False)

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--site',required=True,choices=SITE)
    parser.add_argument('--batches',type=int,default=2)
    parser.add_argument('--limit',type=int,default=5)
    parser.add_argument('--restart',action='store_true')
    p=parser.parse_args()
    if os.geteuid()!=0 or not (1<=p.batches<=4) or not (1<=p.limit<=6):
        raise RuntimeError('host_root_and_bounded_batch_required')
    config=SITE[p.site]
    root=ROOT/p.site
    root.mkdir(mode=0o750,parents=True,exist_ok=True)
    lock=root/'.sweep.lock'
    with lock.open('a+') as lk:
        try:fcntl.flock(lk,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError:
            print(json.dumps({'contract':CONTRACT,'site':p.site,'status':'SKIPPED_LOCKED'}))
            return
        labels=run([
           'docker','ps','--filter','label=coolify.resourceName='+config['label'],
           '--filter','label=com.docker.compose.service=php','--format','{{.ID}}'],timeout=20)
        containers=labels.stdout.split()
        if labels.returncode or len(containers)!=1:
            raise RuntimeError('enrolled_php_container_not_unique_for_'+p.site)
        container=containers[0]
        readback=run(['docker','exec','-u','www-data',container,'wp',
            'mad4b','media-policy','status','--skip-themes'],timeout=75)
        if readback.returncode:
            if 'WPCLI_BUSY' in (readback.stderr+readback.stdout):
                print(json.dumps({'contract':CONTRACT,'site':p.site,
                    'status':'MEDIA_POLICY_READBACK_DEFERRED_WPCLI_BUSY'}))
                return
            raise RuntimeError('site_media_policy_readback_failed')
        media=json.loads(readback.stdout.strip().splitlines()[-1])
        if(media.get('contract')!='mad4b.media-review-policy-hierarchy.v1'
            or media.get('site_uuid')!=config['site_uuid']
            or not media.get('policy_ready')
            or media.get('mode') not in ('human_only','human_and_ai','ai_only')):
            raise RuntimeError('site_media_policy_identity_or_mode_invalid')
        config['expected_review']=media['mode']
        state_path=root/'state.json'
        state=json.loads(state_path.read_text()) if state_path.exists() else {}
        if state and (state.get('contract')!=CONTRACT or
                      state.get('site_uuid')!=config['site_uuid']):
            raise RuntimeError('persisted_company_identity_mismatch')
        complete=state.get('complete',False)
        finished_at=state.get('finished_at')
        if complete and not p.restart:
            try:
                finished=datetime.fromisoformat(finished_at)
                if datetime.now(timezone.utc)-finished<timedelta(hours=24):
                    # Inventory remains complete; refresh live policy metadata
                    # even when a fresh all-tour enumeration is not yet due.
                    state['review_policy']=config['expected_review']
                    state['review_policy_source']=media['source']
                    state['review_policy_central_revision']=media['central_revision']
                    state['media_policy_readback_at']=utc()
                    write_atomic(state_path,state)
                    summary_path=root/'summary.json'
                    if summary_path.exists():
                        summary=json.loads(summary_path.read_text())
                        summary['review_policy']=state['review_policy']
                        summary['review_policy_source']=state['review_policy_source']
                        summary['review_policy_central_revision']=state['review_policy_central_revision']
                        summary['media_policy_readback_at']=state['media_policy_readback_at']
                        write_atomic(summary_path,summary)
                    print(json.dumps({'contract':CONTRACT,'site':p.site,
                                      'status':'COMPLETE_POLICY_READBACK_REFRESHED',
                                      'review_mode':media['mode'],
                                      'checked':len(state.get('tour_results',{})),
                                      'finished_at':finished_at}))
                    return
            except (ValueError,TypeError):pass
        if p.restart or complete:
            state={}
        if not state:
            state={
                'contract':CONTRACT,'site':p.site,
                'site_url':config['site_url'],
                'site_uuid':config['site_uuid'],
                'review_policy':config['expected_review'],
                'started_at':utc(),
                'cursor':0,'complete':False,'batches':0,
                'tour_results':{},'errors':[],
                'media_applied':False,'review_approved':False
            }
        attempts=[]
        for batch in range(p.batches):
            after=state['cursor']
            args=[
              'docker','exec','-u','www-data',container,
              'wp','mad4b','media-reconcile','--batch=1',
              '--limit='+str(p.limit),'--after='+str(after),
              '--skip-themes'
            ]
            output=run(args,timeout=115)
            if output.returncode:
                msg=((output.stderr or '')+'\n'+(output.stdout or ''))[-400:]
                if 'WPCLI_BUSY' in msg or 'STAGING_WPCLI_BUSY' in msg:
                    attempts.append({'status':'WPCLI_BUSY_BACKOFF','after':after})
                    break
                attempts.append({'status':'ERROR','after':after,
                                 'error':msg.replace('\n',' ')[:300]})
                state['errors'].append({'at':utc(),'cursor':after,'error':msg[:300]})
                state['errors']=state['errors'][-20:]
                break
            try:
                content=json.loads(output.stdout.strip().splitlines()[-1])
            except Exception as exc:
                raise RuntimeError('malformed_native_media_batch_output') from exc
            if (content.get('contract')!='mad4b.media-reconcile-batch.v1'
                or content.get('read_only') is not True or
                content.get('changes_made') is not False
                or content.get('limit')!=p.limit
                or not isinstance(content.get('items'),list)):
                raise RuntimeError('wrong_media_batch_contract_or_writer')
            items=content['items']
            last=(int(content.get('last_post_id') or 0))
            if (len(items)>p.limit or last<after
                or (items and last<=after)):
                raise RuntimeError('non_monotonic_cursor_or_unbounded_items')
            for entry in items:
                tid=int(entry['post_id'])
                if tid<=after or tid>last:
                    raise RuntimeError('noncanonical_media_post_id')
                if entry.get('no_publication') is not True:
                    raise RuntimeError('unsafe_media_batch_result')
                status=entry.get('status','UNKNOWN')
                count=int(entry.get('candidates') or 0)
                if status!='WAITING_MEDIA_EVIDENCE_AND_REVIEW':
                    kind='BLOCKED_'+str(status)
                elif count==0:
                    kind='MISSING_SOURCE_BOUND_IMAGES'
                elif entry.get('source_gallery_foreign'):
                    kind='READY_FOR_VISUAL_AND_PROVENANCE_REVIEW'
                else:
                    kind='RECONCILE_AND_VERIFY_PAGE_PRESENTATION'
                state['tour_results'][str(tid)]={
                    'post_id':tid,
                    'category':kind,
                    'status':status,
                    'candidate_count_bounded':count,
                    'source_gallery_foreign':entry.get('source_gallery_foreign'),
                    'plan_fingerprint':entry.get('plan_fingerprint'),
                    'checked_at':utc(),
                    'applied':False,
                    'source_photo_authenticity_verified':False,
                    'image_usage_rights_verified':False,
                }
            state['cursor']=last
            state['batches']+=1
            attempts.append({
                'status':'BATCH_OK','after':after,'last_post_id':last,
                'results':len(items),'total_unique':len(state['tour_results']),
            })
            if not items:
                state['complete']=True
                state['finished_at']=utc()
                break
        state['updated_at']=utc()
        state['category_counts']={}
        for item in state['tour_results'].values():
            key=item['category']
            state['category_counts'][key]=state['category_counts'].get(key,0)+1
        state['site_scope']='enrolled_and_verified_by_docker_labels'
        write_atomic(state_path,state)
        summary={k:v for k,v in state.items() if k not in ('tour_results','errors')}
        summary['last_attempts']=attempts
        summary['blocked_source_ids']=[i['post_id'] for i in state['tour_results'].values()
            if i['category']=='MISSING_SOURCE_BOUND_IMAGES'][:120]
        summary['source_tours_needing_photo_review']=[
            i['post_id'] for i in state['tour_results'].values()
            if i['category']=='READY_FOR_VISUAL_AND_PROVENANCE_REVIEW'][:120]
        summary['last_error']=state['errors'][-1] if state['errors'] else None
        write_atomic(root/'summary.json',summary)
        print(json.dumps({
            'contract':CONTRACT,'site':p.site,'complete':state['complete'],
            'scanned':len(state['tour_results']),
            'cursor':state['cursor'],'categories':state['category_counts'],
            'attempts':attempts,
            'source_media_changed':False,
            'content_job_approval_issued':False,
            'report':str(root/'summary.json')
        },ensure_ascii=False,separators=(',',':')))

if __name__=='__main__':
    try:main()
    except Exception as exc:
        print(json.dumps({'contract':CONTRACT,'status':'ERROR',
            'reason':str(exc)[:400]},ensure_ascii=False),file=sys.stderr)
        sys.exit(1)

