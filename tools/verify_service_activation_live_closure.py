#!/usr/bin/env python3
"""Exact-source attestations for the public-entry, UI-readback and CTA correction.
Does not certify central rollout or carry credentials.
"""
from pathlib import Path
import argparse,hashlib,json,re
ROOT=Path(__file__).resolve().parents[1]
MANIFEST=ROOT/'docs/release-coverage/service-activation-live-closure-20261111.json'
PATHS={
 'wp-content/mu-plugins/mad4b-service-public-entry-guard-v1.php',
 'wp-content/mu-plugins/mad4b-service-activation-live-readback-v1.php',
 'wp-content/mu-plugins/mad4b-service-inquiry-cta-v1.php',
 'wp-content/mu-plugins/489-mad4b-booking-fulfillment-policy-v1.php',
 'wp-content/mu-plugins/490-mad4b-unified-booking-fulfillment-v2.php',
 'wp-content/mu-plugins/mad4b-tour-form6-submission-mode-v1.php',
 'wp-content/mu-plugins/assets/mad4b-tour-form6-submission-mode-v1.js',
}
class Blocked(ValueError):pass
def verify(document,root=ROOT,live=None):
    if document.get('contract')!='mad4b.service-activation-live-closure.v1':
        raise Blocked('INVALID_CONTRACT')
    expected_false=['central_composite_release_authorized','company_plan_remote_activation_performed',
        'service_activation_options_changed','stripes_or_payments_activated','fluent_form6_submission_behavior_changed',
        'wayfa_schema_full_parity']
    expected_true=['public_rest_tour_rate_denied','admin_rest_rate_editor_preserved',
        'readback_status_endpoint_admin_only','tour_form_cta_now_explicit_inquiry',
        'form6_locale_fallback_verified','form6_server_source_guard_reconciled',
        'form6_source_js_in_git']
    if any(document.get(k) is not False for k in expected_false):
        raise Blocked('UNAUTHORIZED_RELEASE_OR_FALSE_ASSERTION')
    if any(document.get(k) is not True for k in expected_true):
        raise Blocked('REQUIRED_EFFECTIVE_STATE_MISSING')
    if document.get('wayfa_remaining_schema_gap')!='shared_tour_presentation_mode':
        raise Blocked('DRIFT_GAP_NOT_REPORTED')
    files=document.get('files')
    if not isinstance(files,list) or len(files)!=len(PATHS) or {i.get('path') for i in files if isinstance(i,dict)}!=PATHS:
        raise Blocked('FILE_SET_CHANGED')
    for item in files:
        if set(item)!={'path','sha256','git_blob_sha1','bytes'}:
            raise Blocked('PROVENANCE_FIELDS_INVALID')
        if not isinstance(item['bytes'],int) or not 0<item['bytes']<120000:
            raise Blocked('UNTRUSTED_SOURCE_LENGTH')
        for key,length in [('sha256',64),('git_blob_sha1',40)]:
            if not isinstance(item[key],str) or not re.fullmatch('[0-9a-f]{'+str(length)+'}',item[key]):
                raise Blocked('INVALID_SOURCE_DIGEST')
        for base in [root]+([live] if live is not None else []):
            p=Path(base)/item['path']
            if not p.is_file() or p.is_symlink():
                raise Blocked('SOURCE_FILE_MISSING')
            data=p.read_bytes()
            if len(data)!=item['bytes'] or hashlib.sha256(data).hexdigest()!=item['sha256']:
                raise Blocked('SOURCE_HASH_DRIFT')
            if hashlib.sha1(f'blob {len(data)}\0'.encode()+data).hexdigest()!=item['git_blob_sha1']:
                raise Blocked('SOURCE_BLOB_DRIFT')
    snippets={p:(Path(root)/p).read_text() for p in PATHS}
    public=snippets['wp-content/mu-plugins/mad4b-service-public-entry-guard-v1.php']
    readback=snippets['wp-content/mu-plugins/mad4b-service-activation-live-readback-v1.php']
    cta=snippets['wp-content/mu-plugins/mad4b-service-inquiry-cta-v1.php']
    unified=snippets['wp-content/mu-plugins/490-mad4b-unified-booking-fulfillment-v2.php']
    form6=snippets['wp-content/mu-plugins/mad4b-tour-form6-submission-mode-v1.php']
    form6js=snippets['wp-content/mu-plugins/assets/mad4b-tour-form6-submission-mode-v1.js']
    requirements={
        'public':['rest_pre_dispatch','template_redirect','tour-rates','rank_math/sitemap/exclude_post_type','current_user_can'],
        'readback':['wp_ajax_mad4b_service_live_probe','wp_safe_remote_get','current_user_can','check_ajax_referer',
                    'service-activation/live-status','Cache-Control','no-store','runtime_schema_gaps'],
        'cta':['#fluentform_6','fluentform/rendering_form','Send Travel Request','Request This Tour'],
        'unified':['MAD4B_Unified_Booking_Fulfillment_V2','checkout_authority','payment_authority',
                   'intent_execution_ready','jet-engine/options-pages/raw-fields'],
        'form6':['travelerLabels','_request_source_id','_request_collection_state',"'labels'=>self::travelerLabels"],
        'form6js':['cfg.labels','fallbackLabels','labels={...fallbackLabels}'],
    }
    for name,source in [('public',public),('readback',readback),('cta',cta),
                        ('unified',unified),('form6',form6),('form6js',form6js)]:
        for marker in requirements[name]:
            if marker not in source:
                raise Blocked('CONTRACT_MARKER_MISSING_'+name)
    for name,source in [('public',public),('readback',readback),('cta',cta)]:
        if any(tok in source for tok in ['wp_mail(', 'update_option(', 'wp_insert_post(',
                                     'wp_delete_post(', 'wp_create_user(', 'wc_create_order(']):
            raise Blocked('NEW_SIDE_EFFECT_DISPATCHER_'+name)
    return {'state':'SITE_SERVICE_ACTIVATION_P0_VERIFIED__COMPOSITE_RELEASE_HOLD',
            'verified_source_files':len(files),'live_readback':live is not None,
            'settings_changed':False,'central_release_authorized':False,
            'remaining_wayfa_schema_gap':document['wayfa_remaining_schema_gap']}
if __name__=='__main__':
    parser=argparse.ArgumentParser()
    parser.add_argument('--live-root',type=Path)
    args=parser.parse_args()
    try:print(json.dumps(verify(json.loads(MANIFEST.read_text()),live=args.live_root),indent=2))
    except (ValueError,OSError) as exc:
        print(json.dumps({'state':'BLOCKED','reason':str(exc),'release_authorized':False}))
        raise SystemExit(2)
