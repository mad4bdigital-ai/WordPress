#!/usr/bin/env python3
"""Exact imported source hashes only; no mail or payment authority."""
from pathlib import Path
import argparse,hashlib,json,re
ROOT=Path(__file__).resolve().parents[1]
DOC=ROOT/'docs/release-coverage/early-payment-woocommerce-email-source-v1-20261111.json'
ALLOWED={
 'wp-content/mu-plugins/mad4b-early-payment-benefits-v1.php',
 'wp-content/mu-plugins/mad4b-request-woocommerce-email-v1.php',
 'wp-content/mu-plugins/assets/mad4b-early-payment-benefits-v1.css',
 'wp-content/mu-plugins/templates/emails/mad4b-request-lifecycle.php',
 'wp-content/plugins/mad4b-platform/legacy-mu-tree/assets/mad4b-early-payment-benefits-v1.css',
}
class Blocked(ValueError):pass
def digest(data):return hashlib.sha256(data).hexdigest()
def blob(data):return hashlib.sha1((f'blob {len(data)}\0').encode()+data).hexdigest()
def verify(d,root=ROOT,live=None):
 if not isinstance(d,dict) or d.get('contract')!='mad4b.early-payment-woocommerce-email-source.v1':
  raise Blocked('CONTRACT_UNVERIFIED')
 if any(d.get(k) is not True for k in (
   'optional_company_policy_default_off',
   'service_benefits_only_no_monetary_discount',
   'per_tour_rate_override_not_new_global_authority',
   'existing_woocommerce_lifecycle_job_only',
   'woocommerce_manual_email_template',
   'original_wp_mail_dispatch_remains_in_request_lifecycle',
   'no_background_job_executed',
   'no_woocommerce_order_mutated',
   'no_email_delivered',
   'no_stripe_capture',
   'plain_text_template_missing_on_live_source')):
  raise Blocked('SOURCE_POLICY_PROOF_MISSING')
 if d.get('release_authorized') is not False:raise Blocked('INVALID_DEPLOY_AUTHORITY')
 expected_email='wp-content/mu-plugins/mad4b-request-woocommerce-email-v1.php'
 overlay=d.get('reviewed_candidate_overlays')
 if (not isinstance(overlay,list) or len(overlay)!=1 or
     not isinstance(overlay[0],dict) or
     set(overlay[0])!={'path','live_sha256','candidate_sha256','candidate_blob_sha1',
       'policy','mail_sent','payment_authority_granted','release_authorized'}):
  raise Blocked('REVIEWED_EMAIL_PATCH_UNVERIFIED')
 patch=overlay[0]
 if (patch['path']!=expected_email or
     patch['policy']!='woo_plain_preview_fallback_only_without_dispatch' or
     any(patch.get(k) is not False for k in ('mail_sent','payment_authority_granted','release_authorized'))):
  raise Blocked('UNAPPROVED_EMAIL_PATCH')
 if (any(not isinstance(patch.get(k),str) or
       not re.fullmatch('[a-f0-9]{64}',patch[k]) for k in ('live_sha256','candidate_sha256'))
     or not isinstance(patch.get('candidate_blob_sha1'),str) or
     not re.fullmatch('[a-f0-9]{40}',patch['candidate_blob_sha1'])):
  raise Blocked('EMAIL_PATCH_DIGEST_INVALID')
 fallback=d.get('candidate_plain_fallback')
 expected_plain='wp-content/mu-plugins/templates/emails/plain/mad4b-request-lifecycle.php'
 if (not isinstance(fallback,dict) or
     set(fallback)!={'path','candidate_sha256','source_kind','original_live_template_exists',
       'direct_send_or_email_trigger','gateway_eligibility_checked_before_link',
       'booking_confirmation_issued','release_authorized',
       'stale_pay_link_removed_without_needs_payment'}
     or fallback.get('path')!=expected_plain or
     fallback.get('source_kind')!='candidate_only_native_woo_plain_fallback'
     or any(fallback.get(k) is not False for k in
       ('original_live_template_exists','direct_send_or_email_trigger',
        'booking_confirmation_issued','release_authorized'))
     or any(fallback.get(k) is not True for k in
       ('gateway_eligibility_checked_before_link',
        'stale_pay_link_removed_without_needs_payment'))
     or not isinstance(fallback.get('candidate_sha256'),str)
     or not re.fullmatch('[a-f0-9]{64}',fallback['candidate_sha256'])):
  raise Blocked('PLAIN_FALLBACK_UNVERIFIED')
 plain=Path(root)/expected_plain
 if not plain.is_file() or plain.is_symlink() or digest(plain.read_bytes())!=fallback['candidate_sha256']:
  raise Blocked('PLAIN_FALLBACK_CONTENT_CHANGED')
 if live is not None and (Path(live)/expected_plain).exists():
  raise Blocked('UPSTREAM_PLAIN_TEMPLATE_APPEARED__REVIEW_REQUIRED')
 rows=d.get('source_files')
 if not isinstance(rows,list) or len(rows)!=5:raise Blocked('SOURCE_FILE_COUNT_DRIFT')
 paths=set()
 for row in rows:
  if not isinstance(row,dict) or set(row)!={'path','bytes','live_sha256',
         'candidate_sha256','git_blob_sha1','source_exact','deployment_authorized'}:
   raise Blocked('UNTRUSTED_SOURCE_RECORD')
  p=row['path']
  if p not in ALLOWED or p in paths or row['source_exact'] is not (p!=expected_email) or row['deployment_authorized'] is not False:
   raise Blocked('UNREGISTERED_SOURCE_FILE')
  paths.add(p)
  if (row['bytes']<1 or row['bytes']>=100000 or
    any(not isinstance(row[k],str) or
        not re.fullmatch('[a-f0-9]{64}',row[k])
        for k in ('live_sha256','candidate_sha256')) or
    not isinstance(row['git_blob_sha1'],str) or
    not re.fullmatch('[a-f0-9]{40}',row['git_blob_sha1']) or
    (p!=expected_email and row['candidate_sha256']!=row['live_sha256'])):
   raise Blocked('SOURCE_FILE_FINGERPRINT_UNVERIFIED')
  if p==expected_email and (row['live_sha256']!=patch['live_sha256'] or
                             row['candidate_sha256']!=patch['candidate_sha256']):
   raise Blocked('REVIEWED_EMAIL_PATCH_MISMATCH')
  for base in (root,live) if live is not None else (root,):
   f=Path(base)/p
   if not f.is_file() or f.is_symlink():raise Blocked('SOURCE_FILE_MISSING')
   b=f.read_bytes()
   iscandidate=Path(base)==Path(root)
   expected_hash=row['candidate_sha256'] if iscandidate else row['live_sha256']
   expected_blob=patch['candidate_blob_sha1'] if iscandidate and p==expected_email else row['git_blob_sha1']
   expected_bytes=row['bytes'] if not (iscandidate and p==expected_email) else None
   if ((expected_bytes is not None and len(b)!=expected_bytes)
        or not 0<len(b)<100000
        or digest(b)!=expected_hash or blob(b)!=expected_blob):
    raise Blocked('SOURCE_CONTENT_CHANGED')
 if paths!=ALLOWED:raise Blocked('REQUIRED_LIFECYCLE_FILE_MISSING')
 a=Path(root)/'wp-content/mu-plugins/assets/mad4b-early-payment-benefits-v1.css'
 b=Path(root)/'wp-content/plugins/mad4b-platform/legacy-mu-tree/assets/mad4b-early-payment-benefits-v1.css'
 if a.read_bytes()!=b.read_bytes():raise Blocked('EARLY_PAYMENT_CSS_DUPLICATE_DRIFT')
 return {
  'state':'EARLY_PAYMENT_WOOCOMMERCE_4_EXACT_1_REVIEWED_PATCH_AND_PLAIN_FALLBACK__HOLD',
  'exact_source_files':len(paths)-1,
  'reviewed_semantic_source_patches':1,'candidate_only_plain_templates':1,
  'live_source_readback':live is not None,
  'registered_mail_template':'woo_native_manual',
  'benefit_default_enabled':False,'monetary_discount_permitted':False,
  'payment_gateway_enabled_by_source_import':False,
  'original_request_mail_idempotency_receipt_required':True,
  'deployment_authorized':False,
 }
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('--live-root',type=Path)
 a=p.parse_args()
 try:
  d=json.loads(DOC.read_text())
  print(json.dumps(verify(d,live=a.live_root),indent=2,sort_keys=True))
 except (OSError,ValueError) as exc:
  print(json.dumps({'state':'BLOCKED','reason':str(exc),'deployment_authorized':False}))
  raise SystemExit(2)
