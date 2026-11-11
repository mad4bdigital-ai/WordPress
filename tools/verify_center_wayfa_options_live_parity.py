#!/usr/bin/env python3
"""Source-to-installed-file attestation. NOT an Original 318/313 signed release."""
import argparse, hashlib, json, re
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
SOURCE=Path('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy')
WAYFA=Path('/opt/wayfa-rebuild-20261102/site-copy')
DOC=ROOT/'docs/release-coverage/center-wayfa-options-live-parity-20261111.json'
class Refused(ValueError):pass
SOURCES={
'wp-content/mu-plugins/mad4b-options-live-readback-v1.php',
'wp-content/mu-plugins/mad4b-tenant-pricing-options-parity-v1.php',
'wp-content/mu-plugins/mad4b-service-activation-live-readback-v1.php',
'wp-content/plugins/mad4b-company-portfolio-manager/includes/class-mad4b-company-service-catalog.php',
'ops/deployment-overlays/wayfatours/wp-content/plugins/destinations-compatibility/includes/activation.php'
}
def verify(doc,live=False,root=ROOT):
    if doc.get('contract')!='mad4b.center-wayfa.options-live-parity.v1':
        raise Refused('contract_not_expected')
    if doc.get('site_scope')!=['tours.dev.mad4b.com','wayfatours.com']:
        raise Refused('site_scope_widened')
    if doc.get('target_site_uuid')!='a3a7cc36-db7e-4a14-8456-b41857def05c':
        raise Refused('target_identity_drift')
    if doc.get('data_scope')!='wayfa pricing configuration options only':
        raise Refused('data_scope_widened')
    if doc.get('wayfa_options_changed_count')!=10:
        raise Refused('mutation_count_drift')
    for key in ('original_318_313_certified','company_native_readback_signed',
       'central_company_plan_applied','published_tour_rate_records_modified',
       'customer_order_or_payment_modified','other_companies_touched',
       'stripe_activated','early_payment_benefits_activated',
       'pricing_engine_enabled','live_booking_enabled'):
        if doc.get(key) is not False:raise Refused('unauthorized_status_claim_'+key)
    if doc.get('booking_fulfillment_mode')!='inquiry_only':
        raise Refused('checkout_gate_changed')
    for key,value in (
        ('pricing_controls_source_fields',37),('pricing_controls_wayfa_fields',37),
        ('financial_mode_policy_rows_complete',4),('tour_cpt_schema_field_count',91),
        ('dimension_available_source',19),('dimension_available_wayfa',17),
        ('selling_units_count',5),('source_draft_currency_count',148),
        ('destination_translation_or_hierarchy_errors',0)):
        if doc.get(key)!=value:raise Refused('proof_field_drift_'+key)
    if doc.get('dimension_count_divergence_is_data_derived') is not True:
        raise Refused('schema_vs_content_not_distinguished')
    if doc.get('destination_groups_selected')!=[2,4,17]:
        raise Refused('destination_policy_changed')
    for key in ('wayfa_draft_authoring_currencies','wcml_display_currencies'):
        if doc.get(key)!=['USD','EUR','EGP','GBP']:
            raise Refused('currency_scope_drift_'+key)
    for key in ('wayfa_option_preimage_sha256','wayfa_option_postimage_sha256'):
        if not isinstance(doc.get(key),str) or not re.fullmatch('[a-f0-9]{64}',doc[key]):
            raise Refused('option_provenance_invalid')
    files=doc.get('files')
    if not isinstance(files,list) or len(files)!=len(SOURCES):
        raise Refused('file_set_incomplete')
    if {x.get('source') for x in files if isinstance(x,dict)}!=SOURCES:
        raise Refused('file_scope_incomplete')
    for item in files:
        if set(item)!={'source','sha256','bytes','live'}:
            raise Refused('file_metadata_changed')
        if not isinstance(item['sha256'],str) or not re.fullmatch('[a-f0-9]{64}',item['sha256']):
            raise Refused('bad_source_digest')
        if not isinstance(item['bytes'],int) or not 500<=item['bytes']<200000:
            raise Refused('bad_source_size')
        mapping=item['live']
        if not isinstance(mapping,dict) or not mapping or not set(mapping)<=set(['central','wayfa']):
            raise Refused('unapproved_site_target')
        paths=[('source',Path(root)/item['source'])]
        if live:
            for site,rel in mapping.items():
                if not isinstance(rel,str) or not rel.startswith('wp-content/') or '..' in rel.split('/'):
                    raise Refused('path_escape')
                paths.append((site,(SOURCE if site=='central' else WAYFA)/rel))
        for kind,path in paths:
            if not path.is_file() or path.is_symlink():
                raise Refused('missing_'+kind+'_file')
            content=path.read_bytes()
            if len(content)!=item['bytes'] or hashlib.sha256(content).hexdigest()!=item['sha256']:
                raise Refused('source_or_installed_drift_'+kind)
    return {'state':'CENTER_WAYFA_OPTIONS_PARITY_VERIFIED__ORIGINAL_318_313_HOLD',
      'source_files':len(files),'installed_verified':live,
      'wayfa_option_changes':10,'original_318_313_certified':False,
      'payment_authority_activated':False,'other_companies_deferred':True}
if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--live',action='store_true')
    args=p.parse_args()
    try:print(json.dumps(verify(json.loads(DOC.read_text()),live=args.live),indent=2))
    except (Refused,ValueError,OSError) as e:
        print(json.dumps({'state':'BLOCKED','reason':str(e),'release_certified':False}))
        raise SystemExit(2)
