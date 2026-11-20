#!/usr/bin/env python3
"""Authorized host worker. Reads admin source requests; reconciles, then optionally signs.

No untrusted request can select the execution command, repository or site root.
Every signed receipt is still nonauthorizing and expires in at most 15 minutes.
"""
from __future__ import annotations
import argparse
import base64
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import time

import source_preflight as pre

HOST_CONFIG=Path('/opt/mad4b-company-provisioning/config/release-source-host-config.json')
ROOT=Path('/opt/mad4b-company-provisioning')
PRIVATE=ROOT/'secrets/release-source-preflight-rsa3072-private.pem'
CONTAINERS={
    'toursdev':'php-wkgtkzta35wkkgouwt6ruljc',
    'wayfa':'php-guxhp9y6vgh7oouuw8plxrlr',
}
ARTIFACT_REL='wp-content/mu-plugins/mad4b-source-preflight'

class WorkerBlocked(Exception):
    pass

def run(argv,timeout=90):
    result=subprocess.run(argv,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=timeout,check=False)
    if result.returncode:
        raise WorkerBlocked('command_failed:'+argv[0]+':'+str(result.returncode))
    return result.stdout

def request_for(container):
    php=("$r=get_option('mad4b_release_source_request_v1',array());"
         "$p=class_exists('MAD4B_SCP_Site_Profile')?MAD4B_SCP_Site_Profile::status():array();"
         "echo 'REQUEST='.wp_json_encode(array('request'=>$r,'site_uuid'=>MAD4B_SCP_Site_Profile::site_uuid(),"
         "'profile_digest'=>MAD4B_SCP_Site_Profile::profile_digest(),'origin'=>untrailingslashit(home_url()),"
         "'enrolled'=>MAD4B_SCP_Site_Profile::origin_enrolled(),'profile_environment'=>MAD4B_SCP_Site_Profile::current_environment()),JSON_UNESCAPED_SLASHES).chr(10);")
    out=run(['docker','exec',container,'wp','eval',php,'--allow-root'],timeout=35).decode()
    lines=[line[len('REQUEST='):] for line in out.splitlines() if line.startswith('REQUEST=')]
    if len(lines)!=1:raise WorkerBlocked('wp_source_request_unavailable')
    data=json.loads(lines[0]);req=data.get('request')
    if not isinstance(req,dict) or req.get('contract')!='mad4b.release-source-request.v1':
        return None
    if data.get('enrolled') is not True or data.get('site_uuid')!=req.get('site_uuid') or data.get('profile_digest')!=req.get('profile_digest'):
        raise WorkerBlocked('source_request_site_identity_mismatch')
    if req.get('authorizing') is not False or not isinstance(req.get('requested_at'),int):
        raise WorkerBlocked('source_request_contract_mismatch')
    if req['requested_at']>int(time.time())+40:
        raise WorkerBlocked('source_request_from_future')
    kind=req.get('type');ref=req.get('reference')
    if kind not in pre.SOURCE_KINDS or not isinstance(ref,str):
        raise WorkerBlocked('unknown_source_request')
    if kind in ('local_integration','github_candidate'):
        pre.branch_name(ref)
    elif ref!='':
        raise WorkerBlocked('unexpected_source_reference')
    return data

def publish(container,base,data,name):
    tmpdir=Path(tempfile.mkdtemp(prefix='mad4b-preflight-publish-'))
    try:
        f=tmpdir/name
        f.write_bytes(pre.canonical(data))
        f.chmod(0o600)
        run(['docker','exec','-u','root',container,'mkdir','-p','/var/www/html/'+ARTIFACT_REL],timeout=12)
        run(['docker','cp',str(f),container+':/var/www/html/'+ARTIFACT_REL+'/'+name+'.new'],timeout=16)
        run(['docker','exec','-u','root',container,'chmod','0644','/var/www/html/'+ARTIFACT_REL+'/'+name+'.new'],timeout=12)
        run(['docker','exec','-u','root',container,'mv','-f','/var/www/html/'+ARTIFACT_REL+'/'+name+'.new',
             '/var/www/html/'+ARTIFACT_REL+'/'+name],timeout=12)
    finally:
        for p in tmpdir.iterdir():p.unlink()
        tmpdir.rmdir()

def remove_attestation(container):
    run(['docker','exec','-u','root',container,'rm','-f','/var/www/html/'+ARTIFACT_REL+'/attestation.json'],timeout=12)

def attest(report,site,request,site_data,config,key):
    status=report.get('preflight',{})
    source=report.get('source',{})
    target=report.get('targets',{}).get(site,{})
    digest_status=source.get('package_manifest',{})
    if status.get('reconciliation_complete') is not True or status.get('status')!='READY_FOR_CERTIFICATION_REVIEW':
        return None
    if not source.get('immutable_snapshot') or not re.fullmatch(r'[0-9a-f]{40}',source.get('immutable_git_sha','')):
        return None
    if digest_status.get('self_digest_valid') is not True or digest_status.get('missing_or_mismatched')!=0 or digest_status.get('source_commit_matches_pin') is not True:
        return None
    if target.get('reconciliation',{}).get('preflight_conflict_free') is not True:
        return None
    # Double-check mutable inputs immediately before issuing a short-lived proof.
    selected,files,_=pre.source_identity(request['type'],request['reference'],Path(config['repo']),json.loads((Path(__file__).parent/'policy.json').read_text()),Path(config['toursdev']))
    if selected.get('immutable_git_sha')!=source['immutable_git_sha'] or pre.inventory_digest(files)!=source['inventory_sha256']:
        raise WorkerBlocked('source_changed_before_host_attestation')
    target_files=pre.inventory_live(Path(config[site]),json.loads((Path(__file__).parent/'policy.json').read_text()))
    if pre.inventory_digest(target_files)!=target.get('site_inventory_sha256'):
        raise WorkerBlocked('target_changed_before_host_attestation')
    issued=int(time.time())
    payload={
        'contract':'mad4b.host-source-preflight.v1',
        'source_kind':request['type'], 'source_reference':request['reference'],
        'source_sha':source['immutable_git_sha'],
        'site_uuid':site_data['site_uuid'],'profile_digest':site_data['profile_digest'],
        'origin':site_data['origin'],
        'target_inventory_sha256':target['site_inventory_sha256'],
        'reconciliation_plan_sha256':target['reconciliation']['reconciliation_plan_sha256'],
        'reconciliation_ready':True,'source_manifest_ready':True,
        'issued_at':issued,'expires_at':issued+600,
        'authorizing':False,'mutation_performed':False,
    }
    body=pre.canonical(payload)
    with tempfile.TemporaryDirectory(prefix='mad4b-source-sign-') as td:
        raw=Path(td)/'payload';sig=Path(td)/'signature';raw.write_bytes(body)
        run(['openssl','dgst','-sha256','-sign',str(key),'-out',str(sig),str(raw)],timeout=12)
        signature=sig.read_bytes()
    return {'contract':'mad4b.host-source-preflight-signature.v1',
            'payload_b64':base64.b64encode(body).decode('ascii'),
            'signature_b64':base64.b64encode(signature).decode('ascii')}

def process(site, config, force=False):
    container=CONTAINERS[site]
    state=request_for(container)
    if state is None:
        return {'site':site,'state':'UNSELECTED','signed':False}
    request=state['request'];request_sha=pre.digest(pre.canonical(request))
    records=Path(config['reports_dir'])
    records.mkdir(parents=True,exist_ok=True,mode=0o700)
    marker=records/('last-'+site+'.json')
    previous=json.loads(marker.read_text()) if marker.exists() else {}
    now=int(time.time())
    if not force and previous.get('request_sha')==request_sha:
        age=now-int(previous.get('processed_at',0))
        if age<(500 if previous.get('signed') else 1800):
            return {'site':site,'state':'UNCHANGED','last_preflight':previous.get('status'),
                    'signed':bool(previous.get('signed'))}
    remove_attestation(container)
    cmd=['python3',str(Path(__file__).parent/'source_preflight.py'),
         '--source',request['type'],'--target',site]
    if request['reference']:cmd.extend(['--ref',request['reference']])
    output=run(cmd,timeout=110).decode()
    summary=json.loads(output.strip().splitlines()[-1])
    if summary.get('status')=='BLOCKED':
        info={'site':site,'request_sha':request_sha,'status':'BLOCKED','reason':summary.get('reason','preflight_blocked'),
              'processed_at':now,'signed':False,'authorizing':False}
    else:
        report_path=Path(summary['receipt'])
        if report_path.parent.resolve()!=records.resolve() or report_path.is_symlink():
            raise WorkerBlocked('preflight_report_untrusted')
        report=json.loads(report_path.read_text())
        if report.get('source',{}).get('type')!=request['type'] or report.get('source',{}).get('reference') not in (request['reference'],'live_snapshot','master'):
            raise WorkerBlocked('preflight_report_source_mismatch')
        signed=attest(report,site,request,state,config,PRIVATE)
        if signed is not None:publish(container,config,signed,'attestation.json')
        info={'site':site,'request_sha':request_sha,'status':report['preflight']['status'],
              'source_sha':report['source'].get('immutable_git_sha'),
              'reconciliation_plan_sha256':report['targets'][site]['reconciliation']['reconciliation_plan_sha256'],
              'conflicts':report['targets'][site]['reconciliation']['reconciliation_delta_count'],
              'report_sha256':pre.digest(report_path.read_bytes()),
              'processed_at':now,'signed':signed is not None,'authorizing':False,
              'source_identity_verified':True}
    marker.write_bytes(pre.canonical(info));marker.chmod(0o600)
    publish(container,config,info,'status.json')
    return {'site':site,'state':info['status'],'signed':info['signed'],'conflicts':info.get('conflicts')}

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--site',choices=['toursdev','wayfa','both'],default='both')
    ap.add_argument('--force',action='store_true')
    args=ap.parse_args()
    if os.geteuid()!=0:raise SystemExit('host_worker_requires_root')
    config=pre.load_host_config(HOST_CONFIG)
    if not PRIVATE.is_file() or PRIVATE.stat().st_uid!=0 or PRIVATE.stat().st_mode & 0o077:
        raise SystemExit('host_signing_key_is_not_root_private')
    result=[]
    for site in (['toursdev','wayfa'] if args.site=='both' else [args.site]):
        try:result.append(process(site,config,args.force))
        except Exception as exc:
            result.append({'site':site,'state':'BLOCKED','error':str(exc),'signed':False})
    print(json.dumps({'contract':'mad4b.host-source-preflight-worker.v1','results':result,
                      'release_mutation_performed':False,'source_code_written':False},sort_keys=True))
    return 0 if all(x.get('state')!='BLOCKED' for x in result) else 2

if __name__=='__main__':raise SystemExit(main())
