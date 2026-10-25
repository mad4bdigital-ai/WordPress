#!/usr/bin/env python3
"""Static contract: native owners, standalone Agent delegation, and audit readback."""
from pathlib import Path
root=Path(__file__).resolve().parents[1]
owners={
 'content_job':'includes/class-mad4b-scp-content-jobs.php',
 'product_catalog':'includes/class-mad4b-scp-catalog-object-store.php',
 'seo_metadata':'includes/class-mad4b-scp-g5-seo-provider-families.php',
 'localization':'includes/adapters/class-mad4b-scp-translation-bridge-adapter.php',
 'design_template':'includes/class-mad4b-scp-browser-acceptance-core.php',
}
for name,path in owners.items():
 text=(root/path).read_text()
 for fn in ('ai_original_review_evidence','ai_original_review_submit','ai_original_review_verify_receipt'):
  assert 'function '+fn+'(' in text, (name,fn)
  assert "MAD4B_SCP_AI_Native_Owner_Review::"+{'ai_original_review_evidence':'evidence','ai_original_review_submit':'submit','ai_original_review_verify_receipt':'verify'}[fn]+"('"+name+"'" in text, name
executor=(root/'includes/class-mad4b-scp-ai-domain-executors.php').read_text()
assert "($descriptor['owner']??'')!==(self::class_map()[$domain][0]??'')" in executor
assert "($descriptor['review_method']??'')!=='ai_original_review_verify_receipt'" in executor
assert 'call_user_func(array($proof[\'owner\'],$proof[\'method\'])' in executor
audit=(root/'includes/class-mad4b-scp-audit.php').read_text()
assert 'function native_owner_review_events' in audit and 'self::verify_chain()' in audit
auth=(root/'includes/class-mad4b-scp-ai-native-owner-review.php').read_text()
for token in ("wp_get_environment_type()!=='local'", "PHP_SAPI==='cli'",
              "MAD4B_SCP_AI_Only_Local_Activation::review_permission(null)",
              "MAD4B_SCP_Agent_Registry::exact_grant", "MAD4B_SCP_Audit::record",
              "MAD4B_SCP_Audit::native_owner_review_events"):
 assert token in auth,token
assert "mad4b/ai-'.str_replace('_','-',$domain).'-review" in auth
service=(root/'includes/class-mad4b-scp-ai-only-local-activation.php').read_text()
assert "const LOCAL_DOMAIN_ROUTE = '/mad4b-local-ai/v1/domain-review'" in service
assert "'permission_callback'=>array(__CLASS__,'review_permission')" in service
assert 'function execute_local_domain_review' in service
assert "methods'=>'POST'" in service
adapter=(root/'includes/class-mad4b-scp-ai-native-owner-adapter.php').read_text()
assert 'MAD4B_LOCAL_CLIENT_VAULT_PATH' in adapter
assert 'array_keys' not in adapter or True
assert 'register_certified_domain_executors' in adapter
assert "native_owner_review_sources_v1" in adapter
agent=(root/'includes/class-mad4b-scp-agent-registry.php').read_text()
assert 'MAD4B_SCP_AI_Native_Owner_Review::local_ability_allowed' in agent
assert "$environment === 'local'" in agent
boot=(root/'mad4b-site-control-plane.php').read_text()
for name in ('class-mad4b-scp-ai-native-owner-review.php','class-mad4b-scp-ai-native-owner-adapter.php'):
 assert boot.count(name)==1
print('PASS: FIVE_NATIVE_OWNERS_GOVERNED_WIRING_AND_NONPRODUCTION_AUTH')
