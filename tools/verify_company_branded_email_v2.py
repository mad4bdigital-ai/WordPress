#!/usr/bin/env python3
"""Source-only tenant and locale mail verification; no mail, gateway or SQL calls."""
import hashlib,json,re,argparse
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
MANIFEST=ROOT/'docs/release-coverage/company-branded-woocommerce-email-v2-20261111.json'
PATHS={
 'wp-content/mu-plugins/includes/mad4b-company-email-brand-v1.php',
 'wp-content/mu-plugins/mad4b-request-woocommerce-email-v1.php',
 'wp-content/mu-plugins/templates/emails/mad4b-request-lifecycle.php',
 'wp-content/mu-plugins/templates/emails/plain/mad4b-request-lifecycle.php',
 'wp-content/plugins/commerce-composition/includes/RequestLifecycle.php',
}
class Blocked(ValueError):pass
def verify(doc,root=ROOT,live=None):
 if doc.get('contract')!='mad4b.company-branded-woocommerce-email.v2':
  raise Blocked('CONTRACT_INVALID')
 if doc.get('release_authorized') is not False or doc.get('stripe_activation_or_payment_capture') is not False or doc.get('new_mail_queue_or_dispatcher') is not False:
  raise Blocked('AUTHORITY_ESCALATION')
 if doc.get('company_early_payment_default_off') is not True or doc.get('untranslated_or_foreign_links_hidden') is not True or doc.get('payment_link_eligibility_enforced') is not True:
  raise Blocked('POLICY_REQUIREMENT_MISSING')
 if doc.get('single_dispatch_authority')!='Commerce\\Composition\\RequestLifecycle::sendMail':
  raise Blocked('UNTRUSTED_DISPATCH_AUTHORITY')
 rows=doc.get('files')
 if not isinstance(rows,list) or len(rows)!=len(PATHS) or {r.get('path') for r in rows if isinstance(r,dict)}!=PATHS:
  raise Blocked('UNVERIFIED_PATH_MANIFEST')
 for row in rows:
  if set(row)!={'path','sha256','git_blob_sha1','bytes'}:raise Blocked('INVALID_PROVENANCE_FIELDS')
  if not isinstance(row['bytes'],int) or not 0<row['bytes']<100000:raise Blocked('INVALID_SOURCE_SIZE')
  for k,n in [('sha256',64),('git_blob_sha1',40)]:
   if not isinstance(row[k],str) or not re.fullmatch('[0-9a-f]{'+str(n)+'}',row[k]):
    raise Blocked('INVALID_DIGEST')
  for base in [root]+([live] if live is not None else []):
   p=Path(base)/row['path']
   if not p.is_file() or p.is_symlink():raise Blocked('SOURCE_MISSING')
   data=p.read_bytes()
   if (len(data)!=row['bytes']
       or hashlib.sha256(data).hexdigest()!=row['sha256']
       or hashlib.sha1(f'blob {len(data)}\0'.encode()+data).hexdigest()!=row['git_blob_sha1']):
    raise Blocked('SOURCE_DRIFT')
 src={r['path']:str((Path(root)/r['path']).read_text()) for r in rows}
 brand=src['wp-content/mu-plugins/includes/mad4b-company-email-brand-v1.php']
 mail=src['wp-content/mu-plugins/mad4b-request-woocommerce-email-v1.php']
 html=src['wp-content/mu-plugins/templates/emails/mad4b-request-lifecycle.php']
 plain=src['wp-content/mu-plugins/templates/emails/plain/mad4b-request-lifecycle.php']
 flow=src['wp-content/plugins/commerce-composition/includes/RequestLifecycle.php']
 needed=[(brand,['brandIdentity','look-and-feel','elementor_active_kit',
                  'localizedUrl','homeForLanguage','renderScoped','finally','remove_filter',
                  'woocommerce_email_header_image_url']),
         (mail,['woocommerce_email_classes','$this->manual = true','render_lifecycle(','style_inline']),
         (html,['woocommerce_email_header','woocommerce_email_footer',
                'RequestAdvancePayments::isAdvance','RequestAdvancePayments::gateways',
                "['privacy','terms']","['home']"]),
         (plain,['RequestAdvancePayments::isAdvance','RequestAdvancePayments::gateways',
                 "['home','privacy','terms']"]),
         (flow,['requestLanguage(','source_url','deliveryFailureCategory',
                "render_lifecycle($msg,$p,$o)","wp_mail($msg['to']",
                "'accepted_by_transport'"])]
 for body,markers in needed:
  for marker in markers:
   if marker not in body:raise Blocked('RUNTIME_CONTRACT_MISSING')
 if sum(len(re.findall(r'\bwp_mail\s*\(',src[p])) for p in PATHS if p!='wp-content/plugins/commerce-composition/includes/RequestLifecycle.php')!=0:
  raise Blocked('ADDITIONAL_MAIL_DISPATCH')
 loyalty=(Path(root)/'wp-content/mu-plugins/mad4b-early-payment-benefits-v1.php').read_text()
 if "'enabled'=>'no'" not in loyalty or "'monetary_discount'=>false" not in loyalty:
  raise Blocked('LOYALTY_DEFAULT_CHANGED')
 return {'state':'BRANDED_EMAIL_V2_SOURCE_VERIFIED__COMPOSITE_RELEASE_HOLD',
         'verified_files':len(rows),'live_readback':live is not None,
         'single_dispatcher':True,'monetary_discount':False,'gateway_enabled_by_verifier':False,
         'release_authorized':False}
if __name__=='__main__':
 ap=argparse.ArgumentParser()
 ap.add_argument('--live-root',type=Path,default=None)
 args=ap.parse_args()
 try:print(json.dumps(verify(json.loads(MANIFEST.read_text()),live=args.live_root),indent=2))
 except (OSError,ValueError) as ex:
  print(json.dumps({'state':'BLOCKED','reason':str(ex),'release_authorized':False}))
  raise SystemExit(2)
