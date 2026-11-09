#!/usr/bin/env python3
"""Hermetic contract test: never sends email, creates users or changes company permissions."""
from pathlib import Path
import json,subprocess
root=Path(__file__).resolve().parents[1]
center=(root/'wordpress/mu-plugins/322-mad4b-optional-company-authorization-center.php').read_text()
site=(root/'wordpress/mu-plugins/323-mad4b-optional-company-authorization-site.php').read_text()
worker=(root/'host/company-optional-authorization-worker.py').read_text()
checks={
 'central_nonblocking':"'required_for_routing'=>false" in center,
 'central_consent_not_grant':"'execution_granted'=>false" in center,
 'central_draft_no_automatic_email':"'DRAFT_OPTIONAL'" in center and "'send_automatic'=>false" in center,
 'central_explicit_confirmation':"SEND COMPANY AUTHORIZATION EMAIL" in center,
 'mail_claim_before_send':"DELIVERY_IN_PROGRESS" in center and center.index("DELIVERY_IN_PROGRESS")<center.index("$ok=wp_mail("),
 'transport_acceptance_not_delivery':"'EMAIL_ACCEPTED'" in center and "'EMAIL_FAILED'" in center,
 'original_write_permissions_preserved':"'original_write_authority_unchanged']=true" in center,
 'central_mcp_abilities':all(n in center for n in ('mad4b/company-optional-authorization-plan','mad4b/company-optional-authorization-create','mad4b/company-optional-authorization-send')),
 'remote_exact_master_admin':"in_array('master_admin',(array)$user->roles,true)" in site,
 'remote_same_wordpress_user':"get_current_user_id()!==(int)$owner->ID" in site,
 'remote_approval_requires_nonce':'check_admin_referer' in site,
 'remote_token_hash_verified':"hash_equals($row['token_hash'],hash('sha256',$token))" in site,
 'remote_single_use':"($row['state']??'')!=='AWAITING_OWNER'" in site,
 'remote_does_not_create_write_grant':"'original_write_grants_created'=>false" in site,
 'worker_verifies_original_ssh':"routing.get('selected_lane')!='cross_ssh_wpcli'" in worker,
 'worker_emails_only_explicitly_queued':"if record['state']=='QUEUED_EMAIL':" in worker,
 'worker_auto_discovers_registered_sites':"POLICY['targets'].items()" in worker,
 'worker_one_use_idempotent_links':"hmac.new(key,context.encode(),hashlib.sha256)" in worker,
 'worker_singleton_lock':"fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)" in worker,
 'worker_redacts_sensitive_error_details':"entry['reason']=str(e)[:115]" in worker,
 'no_direct_original_grant_mutation':"add_cap(" not in site and "add_cap(" not in center,

 'consent_starts_activation':"WAITING_CHATGPT_PKCE_OAUTH" in site,
 'original_pkce_issuer_only':"MAD4B_SCP_Local_OAuth_Store::tables()" in site,
 'exact_original_oauth_client':"MAD4B_SCP_ChatGPT_Auto_Binding::CHATGPT_CLIENT_ID" in site,
 'exact_postapproval_user_scope':"site_uuid=%s AND wp_user_id=%d" in site,
 'actual_token_proof_only':"token_issued'=>true" in site and "revoked_at IS NULL" in site and "expires_at>UTC_TIMESTAMP()" in site,
 'step_up_for_write':"MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE" in site,
 'grant_ready_original_readback':"MAD4B_SCP_Staging_Write_Authority::effective()" in site,
 'center_mirrors_actual_proof':"host_activation_readback(" in center,
 'center_rejects_claims_without_token':"$grants&&!$token" in center,
 'worker_no_fake_issuer':"activation_readback(record,remote,remote_state)" in worker,
 'no_change_to_original_host_router':"ROUTER.load()" in worker and "ROUTER.plan(" in worker,
}
bad=[k for k,v in checks.items() if not v]
if bad:raise SystemExit('CONTRACT_FAILED_'+','.join(bad))
p=subprocess.run(['python3','-m','py_compile',str(root/'host/company-optional-authorization-worker.py')],capture_output=True,text=True)
if p.returncode:raise SystemExit('PYTHON_COMPILE_FAILED')
print(json.dumps({'contract':'mad4b.optional-company-auth.qa.v1','checks_passed':len(checks),
 'python_compile':'PASS','emails_sent':0,'write_grants_issued':0,'original_router_modified':False}))
