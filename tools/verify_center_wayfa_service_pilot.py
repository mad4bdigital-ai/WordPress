#!/usr/bin/env python3
"""Exact-file, fail-closed local pilot readback. Does not issue a central release receipt."""
from pathlib import Path
import argparse,hashlib,json,re
ROOT=Path(__file__).resolve().parents[1]
DOC=ROOT/'docs/release-coverage/center-wayfa-service-pilot-20261111.json'
class Blocked(ValueError):pass
def verify(document,root=ROOT,central=None,wayfa=None):
    if document.get('contract')!='mad4b.center-wayfa-service-pilot.v1':
        raise Blocked('CONTRACT_MISMATCH')
    if document.get('sites')!={'central':'tours.dev.mad4b.com','wayfa':'wayfatours.com'}:
        raise Blocked('SITE_SCOPE_MISMATCH')
    for key in ('other_companies_deferred','remote_public_observation_does_not_certify_deployment',
                'wayfa_inquiry_only'):
        if document.get(key) is not True:raise Blocked('FALSE_SCOPE_ASSERTION')
    for key in ('central_composite_release_authorized','company_plan_applied',
                'booking_payment_activated','stored_service_activation_modified'):
        if document.get(key) is not False:raise Blocked('UNAUTHORIZED_ASSERTION')
    if document.get('observation_scope_option')!='mad4b_service_public_observation_pilot_v1':
        raise Blocked('PILOT_OPTION_DRIFT')
    if document.get('observation_pilot_codes')!=['wayfatours']:
        raise Blocked('PILOT_SCOPE_DRIFT')
    if document.get('wayfa_schema_count')!=31 or document.get('wayfa_schema_gap_count')!=0:
        raise Blocked('SCHEMA_PARITY_NOT_VERIFIED')
    records=document.get('files')
    if not isinstance(records,list) or len(records)!=7:raise Blocked('FILE_COUNT')
    source_paths=set()
    for item in records:
        if set(item)!={'repo_path','sha256','bytes','git_blob_sha1','live'}:
            raise Blocked('FILE_FIELD_DRIFT')
        path=item['repo_path']
        if not isinstance(path,str) or path in source_paths or not path.startswith('wp-content/'):
            raise Blocked('FILE_IDENTITY')
        source_paths.add(path)
        if not isinstance(item['bytes'],int) or not 0<item['bytes']<150000:
            raise Blocked('FILE_SIZE')
        for name,n in [('sha256',64),('git_blob_sha1',40)]:
            if not isinstance(item[name],str) or re.fullmatch('[0-9a-f]{'+str(n)+'}',item[name]) is None:
                raise Blocked('HASH_FORMAT')
        mapping=item['live']
        if not isinstance(mapping,dict) or not mapping or any(k not in ('central','wayfa') for k in mapping):
            raise Blocked('LIVE_SCOPE')
        checks=[Path(root)/path]
        for name,base in (('central',central),('wayfa',wayfa)):
            if name in mapping:
                loc=mapping[name]
                if not isinstance(loc,str) or not loc.startswith('wp-content/') or '..' in loc.split('/'):
                    raise Blocked('PATH_ESCAPE')
                if base is not None:checks.append(Path(base)/loc)
        for file in checks:
            if not file.is_file() or file.is_symlink():raise Blocked('MISSING_FILE')
            content=file.read_bytes()
            if len(content)!=item['bytes'] or hashlib.sha256(content).hexdigest()!=item['sha256']:
                raise Blocked('LIVE_FILE_DRIFT')
            git=hashlib.sha1(f'blob {len(content)}\0'.encode()+content).hexdigest()
            if git!=item['git_blob_sha1']:raise Blocked('GIT_SOURCE_DRIFT')
    catalog=(Path(root)/'wp-content/plugins/mad4b-company-portfolio-manager/includes/class-mad4b-company-service-catalog.php').read_text()
    for token in ['observation_enabled','company_pending_reconciliation_or_full_clone',
                  'company_plan_applied','plan_to_public_disagreements']:
        if token not in catalog:raise Blocked('CENTRAL_PILOT_GATE_MISSING')
    return {'state':'CENTER_WAYFA_PILOT_SOURCE_AND_LIVE_VERIFIED__SIGNED_RELEASE_HOLD',
      'source_files':len(records),'central_verified':central is not None,
      'wayfa_verified':wayfa is not None,'other_companies_deferred':True,
      'central_composite_release_authorized':False}
if __name__=='__main__':
    p=argparse.ArgumentParser()
    p.add_argument('--central-root',type=Path)
    p.add_argument('--wayfa-root',type=Path)
    x=p.parse_args()
    try:print(json.dumps(verify(json.loads(DOC.read_text()),central=x.central_root,wayfa=x.wayfa_root),indent=2))
    except (ValueError,OSError) as e:
        print(json.dumps({'state':'BLOCKED','reason':str(e),'release_authorized':False}))
        raise SystemExit(2)
