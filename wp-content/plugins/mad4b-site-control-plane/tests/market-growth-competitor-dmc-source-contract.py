#!/usr/bin/env python3
"""Static guardrail for separate competitor research and contracted DMC exchange."""
from pathlib import Path
P=Path(__file__).resolve().parents[1]
def load(path):
    return (P/path).read_text(encoding='utf-8')
pol=load('includes/class-mad4b-scp-market-growth-policies.php')
exc=load('includes/class-mad4b-scp-market-content-exchange.php')
ret=load('includes/class-mad4b-scp-recovery-attempt-budget.php')
dyn=load('includes/adapters/class-mad4b-scp-dynamic-content-adapter.php')
ctx=load('includes/adapters/class-mad4b-scp-context-adapter.php')
test=load('tests/market-growth-competitor-dmc-runtime.php')
def ck(assertion,msg):
    if not assertion: raise AssertionError(msg)
for group in ('competitors','suppliers','dmc_connections','feed_mappings','pricing_rules','media_rules','assistant_roles'):
    ck("'"+group+"'" in pol, 'Missing configurable registry group: '+group)
for ability in ('mad4b/market-growth-policy-status','mad4b/market-growth-policy-update',
    'mad4b/market-growth-evaluate','mad4b/competitor-research-plan',
    'mad4b/dmc-exchange-plan','mad4b/dmc-export-preview','mad4b/dmc-import-prepare',
    'mad4b/market-assistant-route'):
    ck(ability in dyn, 'Missing discoverable ability: '+ability)
for key in ('draft_without_supplier_contract_allowed','media_ingest_requires_independent_license',
    'resale_rights_granted','publication_authorized','original_brand_aligned_copy',
    'exchange_plan_ready','import_writes_require_exact_authorization',
    'external_feed_transfer_executed','dmc-import-prepare','dmc-export-preview',
    'exact_write_grant_verified'):
    ck(key in exc or key in dyn, 'Missing separation-of-duties contract: '+key)
for key in ('expected_revision','expected_sha256','mad4b_growth_policy_stale',
    'commercial_supplier_agreement_unverified_or_expired',
    'media_provenance_license_or_expiry_unverified','publication_ready',
    'source_url_alone_is_license','market_price_currency_mismatch_fx_required',
    'price_below_recorded_cost','valid_money','mad4b_growth_rule_field_unknown',
    'current_user_can'):
    ck(key in pol, 'Missing bounded or fail-closed market policy: '+key)
for key in ('function reserve','function status','function reset','function finish',
    'mad4b_retry_circuit_open','mad4b_retry_prior_not_reconciled',
    'mad4b_retry_journal_gap','scope_sha256','add_option','readback'):
    ck(key in ret, 'Missing persisted retry invariant: '+key)
for method in ('brand_draft_create','materialize_brand_draft','reconcile_brand_materialization'):
    ck("guarded_brand_attempt( '"+method+"'" in ctx, 'Brand write not protected by persistent attempt budget: '+method)
ck("'context/recovery-attempt-status'" in ctx, 'Retry status ability missing')
ck("function_exists( 'get_posts' )" in exc and "current_user_can( 'edit_post'" in exc,
    'DMC export must be native WordPress, bounded and permission checked')
ck("source_post_id" in exc and "post_status' => 'publish'" in exc,
    'DMC export content selection is not publication scoped')
ck("post_status' => 'draft'" in exc and "content-apply-bundle" in exc,
    'DMC import must create candidates only and delegate the governed write')
for case in ('Noncontracted competitor research must remain a draft',
    'DMC must prepare drafts only','Fourth write passed persistent limit',
    'Concurrent/unknown retry bypass','Unsafe competitor source accepted'):
    ck(case in test, 'Native negative fixture missing: '+case)
print('PASS market competition/DMC separation, roles, bounded registry, pricing and persisted retry source contracts')
