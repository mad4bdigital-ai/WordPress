#!/usr/bin/env python3
"""Exact-file local pilot verifier; cannot substitute for first-party signed 318/313."""
import hashlib,json,os,re
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
DOC=ROOT/'docs/release-coverage/center-wayfa-native-service-readback-20261111.json'
class Invalid(Exception):pass
def verify(doc,installed=True):
    if doc.get('contract')!='mad4b.center-wayfa.native-service-readback.v1':
        raise Invalid('CONTRACT_INVALID')
    if doc.get('scope')!=['tours.dev.mad4b.com','wayfatours.com'] or doc.get('pilots')!=['wayfatours']:
        raise Invalid('TARGET_SCOPE_DRIFT')
    for key in ('central_admin_rest_only','site_module_exact_source_parity_required',
                'native_wpcli_live_readback_passed'):
        if doc.get(key) is not True:raise Invalid('REQUIRED_PROOF_FLAG_MISSING')
    for key in ('company_site_modified_by_reader','catalog_plan_applied',
                'booking_or_payment_authority_changed','original_318_signed_release_completed',
                'original_313_signed_receipt_verified','original_313_operation_registered_for_company_code_release',
                'other_companies_included'):
        if doc.get(key) is not False:raise Invalid('UNAUTHORIZED_AUTHORITY_CLAIM')
    if doc.get('native_receipt_max_age_seconds')!=360 or doc.get('pilot_timer_cadence_seconds')!=240:
        raise Invalid('FRESHNESS_POLICY_DRIFT')
    rows=doc.get('files')
    if not isinstance(rows,list) or len(rows)!=5:raise Invalid('FILE_SET_INCOMPLETE')
    seen=set()
    for row in rows:
        if set(row)!={'source','installed_path','sha256','bytes'}:raise Invalid('FILE_FIELDS_CHANGED')
        src=row['source'];target=row['installed_path']
        if src in seen or not isinstance(src,str) or '..' in src.split('/'):
            raise Invalid('SOURCE_PATH_DRIFT')
        seen.add(src)
        if not isinstance(row['bytes'],int) or row['bytes']<20 or row['bytes']>150000:
            raise Invalid('FILE_LENGTH_INVALID')
        if not re.fullmatch('[a-f0-9]{64}',row['sha256']):raise Invalid('HASH_NOT_VALID')
        for path in [ROOT/src]+([Path(target)] if installed else []):
            if not path.is_file() or path.is_symlink():
                raise Invalid('DEPLOYMENT_FILE_MISSING')
            content=path.read_bytes()
            if len(content)!=row['bytes'] or hashlib.sha256(content).hexdigest()!=row['sha256']:
                raise Invalid('DEPLOYMENT_FILE_DIGEST_DRIFT')
    return {'state':'CENTER_WAYFA_NATIVE_WPCLI_SOURCE_AND_RUNTIME_FILES_VERIFIED__ORIGINAL_318_313_HOLD',
            'source_files':5,'installed_files_checked':installed,
            'signed_318':False,'signed_313':False,'other_companies_deferred':True}
if __name__=='__main__':
    try: print(json.dumps(verify(json.loads(DOC.read_text())),indent=2))
    except (Invalid,ValueError,OSError) as exc:
        print(json.dumps({'state':'BLOCKED','reason':str(exc),'signed_release':False}))
        raise SystemExit(2)
