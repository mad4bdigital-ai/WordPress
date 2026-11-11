#!/usr/bin/env python3
"""Read-only exact source provenance for Commerce source (not runtime authority)."""
from pathlib import Path
import argparse, hashlib, json, re
ROOT=Path(__file__).resolve().parents[1]
DOC=ROOT/'docs/release-coverage/mad4b-commerce-live-source-snapshot-20261111.json'
ROOT_NAME='wp-content/plugins/mad4b-commerce'
ALLOW_EXT={'.php','.js','.css'}
class Blocked(ValueError):
    pass

def digest(data):
    return hashlib.sha256(data).hexdigest()

def blob(data):
    return hashlib.sha1((f'blob {len(data)}\0').encode()+data).hexdigest()

def verify(data, root=ROOT, live_root=None):
    if (not isinstance(data,dict)
        or data.get('contract')!='mad4b.local-commerce-runtime-source-recovery.v1'
        or data.get('source_root')!=ROOT_NAME
        or data.get('source_only') is not True
        or data.get('company_runtime_mutated') is not False
        or data.get('commerce_runtime_activated') is not False
        or data.get('checkout_ready_authorized') is not False
        or data.get('payment_gateway_activated') is not False
        or data.get('full_platform_release_certified') is not False):
        raise Blocked('INVALID_RELEASE_SOURCE_CLAIM')
    overlays=data.get('approved_candidate_overlays')
    php_path=ROOT_NAME+'/modules/presentation/unified-date-presentation-v1.php'
    allowed_overlays={
        php_path:('read_only_and_intersection_fail_closed','offline_php_cases_passed',45),
        ROOT_NAME+'/modules/presentation/assets/unified-date-v0.3.5.js':
            ('clear_wpml_stale_date_and_empty_fluent_input','offline_js_cases_passed',23),
        ROOT_NAME+'/modules/presentation/assets/unified-date-v0.3.2.js':
            ('clear_wpml_stale_date_and_empty_fluent_input','offline_js_cases_passed',23),
    }
    if not isinstance(overlays,list) or len(overlays)!=3:
        raise Blocked('SEMANTIC_OVERLAY_COUNT_OR_SCOPE_DRIFT')
    sem_by_path={}
    for item in overlays:
        if not isinstance(item,dict):
            raise Blocked('INVALID_SEMANTIC_OVERLAY_RECORD')
        path=item.get('path')
        if path not in allowed_overlays or path in sem_by_path:
            raise Blocked('UNREVIEWED_OR_DUPLICATED_SEMANTIC_OVERLAY')
        policy,case_key,cases=allowed_overlays[path]
        fields={'path','original_snapshot_sha256','live_sha256',
                'candidate_sha256','candidate_blob_sha1','policy',
                case_key,'booking_authorized','deployment_authorized'}
        if (set(item)!=fields
            or item.get('policy')!=policy
            or item.get(case_key)!=cases
            or item.get('booking_authorized') is not False
            or item.get('deployment_authorized') is not False):
            raise Blocked('SEMANTIC_OVERLAY_POLICY_DRIFT')
        if (not all(isinstance(item.get(field),str) and
            re.fullmatch('[a-f0-9]{64}',item[field])
            for field in ('original_snapshot_sha256','live_sha256','candidate_sha256'))
            or not isinstance(item.get('candidate_blob_sha1'),str)
            or not re.fullmatch('[a-f0-9]{40}',item['candidate_blob_sha1'])):
            raise Blocked('SEMANTIC_OVERLAY_BAD_DIGEST')
        sem_by_path[path]=item
    if set(sem_by_path)!=set(allowed_overlays):
        raise Blocked('REQUIRED_SEMANTIC_OVERLAY_MISSING')
    items=data.get('files')
    if not isinstance(items,list) or len(items)!=286:
        raise Blocked('COMMERCE_SOURCE_COUNT_DRIFT')
    seen=set()
    groups={'.php':0,'.js':0,'.css':0}
    for row in items:
        if not isinstance(row,dict) or set(row)!={'path','bytes','sha256','git_blob_sha1','original_mode'}:
            raise Blocked('INVALID_RECORD')
        path=row.get('path')
        if (not isinstance(path,str) or not path.startswith(ROOT_NAME+'/')
            or path.startswith('/') or '\\' in path
            or any(c in ('.','..','') for c in path.split('/')) or path in seen):
            raise Blocked('SOURCE_PATH_UNSAFE_OR_DUPLICATE')
        seen.add(path)
        suffix=Path(path).suffix.lower()
        if suffix not in ALLOW_EXT:
            raise Blocked('UNKNOWN_EXECUTABLE_EXTENSION')
        groups[suffix]+=1
        if (not isinstance(row.get('bytes'),int)
            or row['bytes']<1 or row['bytes']>=200000
            or not isinstance(row.get('sha256'),str)
            or not re.fullmatch('[a-f0-9]{64}',row['sha256'])
            or not isinstance(row.get('git_blob_sha1'),str)
            or not re.fullmatch('[a-f0-9]{40}',row['git_blob_sha1'])):
            raise Blocked('UNBOUNDED_FILE_METADATA')
        for target_root in ((root,live_root) if live_root else (root,)):
            target=Path(target_root)/path
            if not target.is_file() or target.is_symlink():
                raise Blocked('COMMERCE_SOURCE_MISSING_OR_CHANGED')
            raw=target.read_bytes()
            semantic=sem_by_path.get(path)
            if semantic and row['sha256']!=semantic['live_sha256']:
                raise Blocked('SEMANTIC_OVERLAY_LIVE_SOURCE_MISMATCH')
            if semantic and Path(target_root)==Path(root):
                if (digest(raw)!=semantic['candidate_sha256']
                    or blob(raw)!=semantic['candidate_blob_sha1']):
                    raise Blocked('UNAPPROVED_CANDIDATE_SEMANTIC_PATCH')
            elif (len(raw)!=row['bytes']
                or digest(raw)!=row['sha256']
                or blob(raw)!=row['git_blob_sha1']):
                raise Blocked('COMMERCE_SOURCE_SHA_MISMATCH')
    if groups!={'.php':238,'.js':31,'.css':17}:
        raise Blocked('COMMERCE_CODE_TYPE_DRIFT')
    if (ROOT_NAME+'/mad4b-commerce.php' not in seen
        or ROOT_NAME+'/legacy-mu-tree/shared-product-core/adapters/rate-actions-styles.php' not in seen):
        raise Blocked('COMMERCE_RUNTIME_ENTRYPOINT_MISSING')
    extra=data.get('excluded_non_code')
    links=data.get('excluded_links')
    if not isinstance(extra,list) or len(extra)!=6 or not isinstance(links,list) or len(links)!=1:
        raise Blocked('UNREVIEWED_EXTRA_FILES_OR_LINKS')
    if (links[0].get('path')!='legacy-mu-tree/currency-data'
        or not isinstance(links[0].get('link_target'),str)):
        raise Blocked('CURRENCY_LINK_DRIFT')
    # The Git snapshot may not execute the live currency symlink implicitly.
    if (Path(root)/ROOT_NAME/links[0]['path']).exists() or (Path(root)/ROOT_NAME/links[0]['path']).is_symlink():
        raise Blocked('UNAPPROVED_CURRENCY_LINK_RECREATED')
    for name in extra:
        if not isinstance(name,str) or '/'==name or '..' in Path(name).parts:
            raise Blocked('EXCLUDED_FILE_PATH_UNSAFE')
        if (Path(root)/ROOT_NAME/name).exists():
            raise Blocked('UNREVIEWED_BACKUP_FILE_IMPORTED')
    return {
        'state':'COMMERCE_V035_SOURCE_WITH_THREE_BOUNDED_PHP_AND_JS_GUARDS__HOLD',
        'source_files_verified':len(seen),
        'semantic_overlays_verified':3,
        'live_files_byte_identical_to_candidate':len(seen)-3,
        'code_by_type':groups,
        'live_readback_checked':live_root is not None,
        'original_currency_symlink_not_replayed':True,
        'commerce_runtime_activated':False,
        'payment_gateway_activated':False,
        'release_authorized':False,
    }

if __name__=='__main__':
    cli=argparse.ArgumentParser()
    cli.add_argument('--live-root',type=Path)
    args=cli.parse_args()
    try:
        data=json.loads(DOC.read_text())
        print(json.dumps(verify(data,live_root=args.live_root),indent=2,sort_keys=True))
    except (OSError,ValueError) as e:
        print(json.dumps({'state':'BLOCKED','reason':str(e),'release_authorized':False}))
        raise SystemExit(2)
