#!/usr/bin/env python3
"""IMP01: deterministic source-safety contract. Not live plugin/provider acceptance."""
from pathlib import Path
import re
ROOT=Path(__file__).resolve().parents[1]
R=ROOT.parent.parent.parent
read=lambda p:(ROOT/p).read_text(encoding="utf-8")
code=read("includes/class-mad4b-scp-activity-import-review.php")
profile=read("includes/class-mad4b-scp-content-experience-profiles.php")
spec=(R/"specs/007-content-intelligence-workflow-platform/extensions/imp01-dynamic-spreadsheet-import-and-apps-script.md").read_text(encoding="utf-8")
script=(R/"tools/feature007/google-apps-script/mad4b-activity-import-push.gs").read_text(encoding="utf-8")
def check(value, reason):
    if not value: raise AssertionError(reason)
for name in ("capabilities","plan","review","brand_core_plan","receive_signed",
             "authorize_signed","register_rest","admin_page","expire_nonce","wp_all_import_plan"):
    check("function "+name+"(" in code,"Missing bounded import ability: "+name)
for marker in ("MAD4B_ACTIVITY_IMPORT_SOURCE_KEYS","hash_hmac( 'sha256'",
    "hash_equals( $expected, $received )","abs( time() - $data['issued_at'] ) > 300",
    "mad4b_import_nonce_","! add_option( $nonce_key","MAD4B_SCP_Site_Profile::site_uuid()",
    "count( $rows ) > min( self::MAX_ROWS, $policy['max_rows'] )","count( $headers ) > self::MAX_COLUMNS",
    "mad4b_import_field_not_allowed","$profile['meta_keys']",
    "source_values_persisted","ready_for_import_execution' => false",
    "add_management_page","MAD4B_SCP_Context_Authority::brand_core_coverage()",
    "MAD4B_SCP_Context_Authority::review_queue()",
    "mad4b_wpai_update_not_allowed","requires_native_wpai_wizard_or_certified_adapter",
    "'ready_for_import_execution' => false"):
    check(marker in code,"Unsafe/incomplete IMP01 boundary: "+marker)
for suffix in ("business-activity-import-capabilities","business-activity-import-plan",
               "business-activity-import-review","business-activity-wp-all-import-plan","brand-core-acceptance-plan"):
    check(profile.count("'mad4b/"+suffix+"'")>=2,
          "MCP Ability missing from inventory/definitions: "+suffix)
for marker in ("computeHmacSha256Signature","PropertiesService.getScriptProperties",
    "LockService.getScriptLock","UrlFetchApp.fetch",
    "if (formulas.some(row => row.some(Boolean)))","'x-mad4b-signature'",
    "post_writes: 0"):
    check(marker in script,"Apps Script security contract missing: "+marker)
check("pmxi_saved_post" in spec and "WPML" in spec and "all options" not in code,
      "Native plugin hooks or dynamic option extension policy not declared")
check("wp_insert_post(" not in code and "wp_update_post(" not in code,
      "Review-only IMP01 must not directly mutate WordPress posts")
print("PASS IMP01 signed review inbox, managed profile boundary, Apps Script connector, Brand Core and safe WP All Import handoff contract (static only)")
