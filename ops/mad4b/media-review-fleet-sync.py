#!/usr/bin/env python3
"""MAD4B host-authorized central media policy propagation and telemetry.

Central WordPress is authoritative for inherited defaults; company WordPress
retains its own local override. Every command resolves a bound Docker label.
This is never a source of media review evidence or automatic publication.
"""
import fcntl, json, os, subprocess, sys, tempfile, pathlib
from datetime import datetime, timezone
ROOT=pathlib.Path('/opt/mad4b-company-provisioning/reports/media-review-fleet-policy')
LOCK=pathlib.Path('/run/lock/mad4b-media-review-fleet-policy.lock')
CONTRACT='mad4b.media-review-policy-hierarchy.v1'
SITES={
    'central':{'resource':'tours-dev-existing-compose-adoption-draft',
      'uuid':'e5e64bca-ed62-48d2-b2b3-dff693cd702a','sweep':'toursdev'},
    'wayfatours':{'resource':'wayfatours-production',
      'uuid':'a3a7cc36-db7e-4a14-8456-b41857def05c','sweep':'wayfa'},
}
def utc():
    return datetime.now(timezone.utc).isoformat()
def call(*args,input=None,timeout=75):
    return subprocess.run(args,input=input,encoding='utf-8',
        capture_output=True,timeout=timeout,check=False)
def json_last(p):
    if p.returncode:
        raise RuntimeError('wpcli_failed: '+((p.stderr or p.stdout)[-250:]).replace('\n',' '))
    try:return json.loads(p.stdout.strip().splitlines()[-1])
    except Exception as e:raise RuntimeError('wpcli_invalid_json_response') from e
def resolve(label):
    p=call('docker','ps','--filter','label=coolify.resourceName='+label,
       '--filter','label=com.docker.compose.service=php','--format','{{.ID}}',timeout=20)
    ids=p.stdout.split()
    if p.returncode or len(ids)!=1:raise RuntimeError('php_container_unique_binding_failed')
    return ids[0]
def wp(container,operation,payload=None,timeout=70):
    args=['docker','exec']
    if payload is not None:args+=['-i']
    args+=['-u','www-data',container,'wp','mad4b','media-policy',operation,'--skip-themes']
    return json_last(call(*args,input=None if payload is None else json.dumps(
        payload,ensure_ascii=False,separators=(',',':')),timeout=timeout))
def atomic(path,data):
    path.parent.mkdir(parents=True,exist_ok=True,mode=0o750)
    fd,fname=tempfile.mkstemp(dir=path.parent,prefix='.media-policy-')
    try:
        with os.fdopen(fd,'w') as f:
            json.dump(data,f,ensure_ascii=False,indent=2,sort_keys=True)
            f.write('\n')
            f.flush()
            os.fsync(f.fileno())
        os.chmod(fname,0o640)
        os.replace(fname,path)
    finally:
        if os.path.exists(fname):os.unlink(fname)
def snapshot(site,expected_uuid):
    path=pathlib.Path('/opt/mad4b-company-provisioning/reports/tour-media-portfolio-sweep')/site/'state.json'
    record=json.loads(path.read_text())
    if(record.get('contract')!='mad4b.media-portfolio-sweep.v1'
       or record.get('site_uuid')!=expected_uuid
       or not isinstance(record.get('tour_results'),dict)):
        raise RuntimeError('inventory_site_binding_or_contract_invalid')
    cats=record.get('category_counts') or {}
    if sum(cats.values())!=len(record['tour_results']):
        raise RuntimeError('inventory_counts_do_not_match_registry')
    return record,cats
def main():
    if os.geteuid()!=0 or len(sys.argv)!=1:
        raise RuntimeError('root_host_fleet_executor_required')
    ROOT.mkdir(parents=True,exist_ok=True,mode=0o750)
    LOCK.parent.mkdir(parents=True,exist_ok=True)
    with LOCK.open('a+') as lk:
        try:fcntl.flock(lk,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError:
            print(json.dumps({'contract':CONTRACT,'status':'SKIPPED_LOCKED'}))
            return
        containers={name:resolve(data['resource']) for name,data in SITES.items()}
        fleet=wp(containers['central'],'export')
        if(fleet.get('contract')!=CONTRACT or
           fleet.get('central_site_uuid')!=SITES['central']['uuid'] or
           not isinstance(fleet.get('central_revision'),int) or
           fleet['central_revision']<1 or
           fleet.get('central_default_mode') not in ('human_only','human_and_ai','ai_only')):
            raise RuntimeError('central_policy_export_contract_or_identity_invalid')
        outcomes=[]
        company=fleet['companies'].get('wayfatours')
        if(not isinstance(company,dict)
           or company.get('site_uuid')!=SITES['wayfatours']['uuid']
           or company.get('canonical_origin')!='https://wayfatours.com'
           or company.get('central_company_default') not in ('inherit','human_only','human_and_ai','ai_only')):
            raise RuntimeError('company_registry_exact_identity_or_origin_invalid')
        payload={
            'contract':CONTRACT,'source':'bound_host_wpcli_fleet_sync',
            'site_uuid':company['site_uuid'],
            'central_site_uuid':fleet['central_site_uuid'],
            'central_revision':fleet['central_revision'],
            'company_code':'wayfatours',
            'default_mode':fleet['central_default_mode'],
            'company_default_mode':company['central_company_default'],
        }
        effective=wp(containers['wayfatours'],'sync',payload,timeout=90)
        if(effective.get('site_uuid')!=company['site_uuid'] or
           not effective.get('policy_ready') or
           effective.get('central_revision')!=fleet['central_revision']):
            raise RuntimeError('site_policy_sync_readback_mismatch')
        for code in ('central','wayfatours'):
            site=SITES[code]
            state=wp(containers[code],'status')
            if(state.get('site_uuid')!=site['uuid'] or not state.get('policy_ready')):
                raise RuntimeError('company_policy_readback_not_ready:'+code)
            inv,categories=snapshot(site['sweep'],site['uuid'])
            applied=state.get('media_apply_progress') or {}
            applied_ids=applied.get('applied_tour_ids')
            if not applied.get('ready') or not isinstance(applied_ids,list) or len(applied_ids)>300:
                raise RuntimeError('applied_media_receipt_index_not_ready:'+code)
            if any(type(x) is not int or str(x) not in inv['tour_results'] for x in applied_ids):
                raise RuntimeError('applied_media_receipt_id_not_in_portfolio:'+code)
            applied_set=set(applied_ids)
            waiting_unapplied=sum(
                1 for post,v in inv['tour_results'].items()
                if v['category']=='READY_FOR_VISUAL_AND_PROVENANCE_REVIEW'
                and int(post) not in applied_set)
            telemetry={
                'contract':CONTRACT,'source':'host_wpcli_verified_readback',
                'company_code':code,
                'site_uuid':site['uuid'],
                'canonical_tours':len(inv['tour_results']),
                'inventory_complete':bool(inv['complete']),
                'missing_source_images':int(categories.get('MISSING_SOURCE_BOUND_IMAGES',0)),
                'waiting_visual_review':waiting_unapplied,
                'applied_tour_families':applied['applied_tour_families'],
                'content_jobs_waiting_review':state.get('media_content_jobs',{}).get('media_jobs_waiting_review'),
                'effective_mode':state['mode'],
                'effective_source':state['source'],
                'central_revision':state['central_revision'],
                'company_local_override':state['company_local_override'],
            }
            receipt=wp(containers['central'],'telemetry',telemetry)
            if(receipt.get('site_uuid')!=site['uuid'] or
               receipt.get('effective_mode')!=state['mode']):
                raise RuntimeError('fleet_telemetry_write_readback_mismatch:'+code)
            outcomes.append({
                'company':code,'site_uuid':site['uuid'],
                'effective_mode':state['mode'],
                'policy_source':state['source'],
                'central_revision':state['central_revision'],
                'company_override':state['company_local_override'],
                'inventory_complete':bool(inv['complete']),
                'canonical_tours':len(inv['tour_results']),
                'waiting_visual_review':telemetry['waiting_visual_review'],
                'previously_waiting_but_now_applied':int(
                    categories.get('READY_FOR_VISUAL_AND_PROVENANCE_REVIEW',0))
                    -waiting_unapplied,
                'applied_tour_families':telemetry['applied_tour_families'],
                'content_jobs_waiting_review':telemetry['content_jobs_waiting_review'],
                'missing_source_images':telemetry['missing_source_images'],
                'central_receipt_readback':True,
                'posts_modified':False,
                'visual_review_performed':False
            })
        result={
            'contract':CONTRACT,'status':'SYNCED_AND_TELEMETRY_VERIFIED',
            'at_utc':utc(),'central_revision':fleet['central_revision'],
            'central_default_mode':fleet['central_default_mode'],
            'companies':outcomes,
            'transport':'exact_enrolled_host_wpcli_no_wp_user_impersonation',
            'production_media_modified':False,
            'site_brand_context_review_policy_changed':False
        }
        atomic(ROOT/'latest.json',result)
        print(json.dumps(result,ensure_ascii=False,separators=(',',':')))
if __name__=='__main__':
    try:main()
    except Exception as e:
        print(json.dumps({'contract':CONTRACT,'status':'BLOCKED','reason':str(e)[:320]}),
              file=sys.stderr)
        sys.exit(2)

