#!/usr/bin/env python3
"""Exact enrolled-source MU bridge; no shadow handler or remote operations."""
from pathlib import Path
import hashlib
ROOT=Path(__file__).resolve().parents[4]
p=ROOT/'wp-content/mu-plugins/250-mad4b-company-plugin-sync-operation.php'
src=p.read_bytes()
# Pinned against the observed installed Staging MU source; the local VPS
# working-copy variant omitted mandatory owner email gating and is rejected.
assert hashlib.sha256(src).hexdigest()=='fa13b82e71a7fd7267a6e57dfa3c556b9faac62c580f1e7cbcb1117c550885e7'
text=src.decode('utf-8')
for marker in (
    "'mad4b/company-plugin-sync-plan'",
    "'mad4b/company-plugin-sync-request'",
    "'mad4b/company-plugin-sync-job'",
    "MAD4B_Company_Bootstrap_Contract::record",
    "mad4b_company_owner_email_required",
    "MAD4B_SCP_Operation_Journal::begin",
    "MAD4B_SCP_Operation_Journal::status",
    "hash_equals($rec['plan_hash'],$input['plan_hash'])",
    "wp_get_environment_type()==='staging'",
    "DB_NAME==='etg_commerce_staging'",
    "wp_parse_url(home_url(),PHP_URL_HOST)==='tours.dev.mad4b.com'",
    "'production_mutation_authorized'=>false",
    "MAD4B_Company_Readiness_Governance::guard($request)"
):
    assert marker in text,marker
print('COMPANY_PLUGIN_SYNC_MU_SOURCE: PASS (installed exact source and owner-email safety)')
