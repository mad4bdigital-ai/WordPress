#!/usr/bin/env python3
"""Verifies site-owned Woo+Fluent mail styling source without granting release authority."""
from pathlib import Path
import argparse,hashlib,json,re
ROOT=Path(__file__).resolve().parents[1]
DOC=ROOT/'docs/release-coverage/company-email-experience-bridge-20261111.json'
PATH='wp-content/mu-plugins/mad4b-company-email-experience-bridge-v1.php'
class Blocked(ValueError):pass

def verify(doc,root=ROOT,live=None):
    if not isinstance(doc,dict) or doc.get('contract')!='mad4b.company-email-experience-bridge.v1':
        raise Blocked('INVALID_CONTRACT')
    if doc.get('plugin_path')!=PATH or doc.get('central_composite_release_authorized') is not False:
        raise Blocked('INVALID_AUTHORITY')
    expected_true=['WooCommerce_email_classes_unmodified','Fluent_Forms_notification_enablement_unmodified',
        'one_sender_no_new_wp_mail_dispatch','same_company_localized_public_links_only',
        'order_payment_and_reset_links_unmodified','desktop_and_mobile_chromium_render_verified',
        'source_and_wayfa_live_readback_required']
    expected_false=['Stripe_live_enabled','early_payment_benefits_enabled','qa_emails_to_customers',
        'gmail_inbox_visual_verified','outlook_desktop_app_verified']
    if any(doc.get(k) is not True for k in expected_true) or any(doc.get(k) is not False for k in expected_false):
        raise Blocked('POLICY_PROOF_INVALID')
    if doc.get('test_message_smtp_accepted_count')!=2:
        raise Blocked('QA_ACCEPTANCE_INCONSISTENT')
    if not isinstance(doc.get('source_bytes'),int) or not 0<doc['source_bytes']<100000:
        raise Blocked('INVALID_LENGTH')
    if not re.fullmatch('[0-9a-f]{64}',doc.get('source_sha256','')) or not re.fullmatch('[0-9a-f]{40}',doc.get('source_blob_sha1','')):
        raise Blocked('INVALID_SOURCE_HASH')
    for base in [root]+([live] if live else []):
        path=Path(base)/PATH
        if not path.is_file() or path.is_symlink():
            raise Blocked('BRIDGE_SOURCE_MISSING')
        payload=path.read_bytes()
        if len(payload)!=doc['source_bytes'] or hashlib.sha256(payload).hexdigest()!=doc['source_sha256']:
            raise Blocked('BRIDGE_SOURCE_DRIFT')
        if hashlib.sha1(f'blob {len(payload)}\0'.encode()+payload).hexdigest()!=doc['source_blob_sha1']:
            raise Blocked('BRIDGE_BLOB_DRIFT')
    source=(Path(root)/PATH).read_text()
    required=['woocommerce_email_header','woocommerce_email_styles','woocommerce_email_footer_text',
              'fluentform/submission_message_parse','fluentform/email_template_colors',
              'fluentform/email_template_header_image','fluentform/email_template_footer_text',
              'fluentform/email_body','requestLanguage','get_meta']
    if any(marker not in source for marker in required):
        raise Blocked('BRIDGE_CONTRACT_INCOMPLETE')
    forbidden=['wp_mail(', 'wc_create_order(', 'update_option(', 'as_enqueue_async_action(',
               'wp_schedule_event(', 'woocommerce_payment_complete']
    if any(marker in source for marker in forbidden):
        raise Blocked('ILLEGAL_SECOND_DISPATCHER_OR_MUTATION')
    brand=Path(root)/'wp-content/mu-plugins/includes/mad4b-company-email-brand-v1.php'
    if not brand.is_file() or 'finally' not in brand.read_text():
        raise Blocked('ORIGINAL_SCOPED_BRAND_MISSING')
    return {'state':'UNIFIED_EMAIL_PRESENTATION_SOURCE_VERIFIED__COMPOSITE_RELEASE_HOLD',
            'verified_files':1,'live_readback':live is not None,'sender_changed':False,
            'customer_email_sent_by_verifier':False,'release_authorized':False}

if __name__=='__main__':
    parser=argparse.ArgumentParser()
    parser.add_argument('--live-root',type=Path)
    args=parser.parse_args()
    try:print(json.dumps(verify(json.loads(DOC.read_text()),live=args.live_root),indent=2))
    except (OSError,ValueError) as error:
        print(json.dumps({'state':'BLOCKED','reason':str(error),'release_authorized':False}))
        raise SystemExit(2)
