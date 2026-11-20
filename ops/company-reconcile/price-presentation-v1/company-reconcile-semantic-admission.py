#!/usr/bin/env python3
"""Read-only site-scoped publication admission (fail-closed).
Semantic reports are DISCOVERY, never original provider acceptance receipts.
No shell/SQL mutation, no grant issuance, no job dispatch; all environments.
"""
from __future__ import annotations
import datetime
import argparse,datetime as dt,hashlib,json,pathlib,re,subprocess,sys
ROOT=pathlib.Path('/opt/mad4b-company-provisioning')
REPORTS=ROOT/'reports/start-company-reconcile'
CHECKS=ROOT/'policies/company-start-reconcile-completeness-v1.json'
CODE=re.compile(r'^[a-z][a-z0-9-]{2,47}$')
ENV=re.compile(r'^[a-z][a-z0-9_-]{1,63}$')
UUID=re.compile(r'^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$',re.I)

def load(path):
    if not path.is_file() or path.is_symlink():return None
    return json.loads(path.read_text())

def admission(company,environment,site_uuid,canonical_origin,profile_revision=None,manifest_sha256=None,report_dir=None):
    if not CODE.fullmatch(company) or not ENV.fullmatch(environment) or not UUID.fullmatch(str(site_uuid or '')):
        raise ValueError('INVALID_EXACT_TARGET_BINDING')
    if not isinstance(canonical_origin,str) or not re.fullmatch(r'https://[a-zA-Z0-9.-]+',canonical_origin):
        raise ValueError('INVALID_CANONICAL_ORIGIN')
    root=pathlib.Path(report_dir) if report_dir else REPORTS/company/environment/site_uuid
    # Legacy Wayfa receipts can be inspected, never treated as native evidence.
    # Legacy Wayfa observation receipts live in the shared report root.
    # A new site-scoped CSS evidence directory alone must not shadow them.
    # Explicit report-dir selections are never silently redirected.
    if not report_dir and company=='wayfatours' and not (root/'wayfa-live-observation.json').is_file():
        root=REPORTS
    values=load(root/'wayfa-en-es-three-stage-value-format-parity.json') if company=='wayfatours' else load(root/'value-parity.json')
    providers=load(root/'wayfa-en-es-provider-value-parity.json') if company=='wayfatours' else load(root/'provider-parity.json')
    observed=load(root/'wayfa-live-observation.json') if company=='wayfatours' else load(root/'reconcile-observation.json')
    p=load(CHECKS)
    if not p:raise RuntimeError('RECONCILE_COMPLETENESS_POLICY_UNAVAILABLE')
    blockers=[];facts={};classifications=[]
    expected=(company,environment,site_uuid,canonical_origin)
    if not observed or tuple((observed.get('target') or {}).get(k) for k in ('company_id','environment','site_uuid','canonical_origin')) != expected:
        blockers.append('EXACT_TARGET_OR_NATIVE_OBSERVATION_MISSING')
    if not values or not providers:
        blockers.append('VALUE_AND_PROVIDER_PARITY_REPORTS_MISSING')
    if observed and (profile_revision is None or observed.get('target',{}).get('profile_revision')!=profile_revision):
        blockers.append('ORIGINAL_SITE_PROFILE_NOT_CERTIFIED')
    if observed and manifest_sha256 and observed.get('manifest_sha256')!=manifest_sha256:
        blockers.append('SOURCE_MANIFEST_BINDING_CHANGED')
    if observed and not manifest_sha256:
        blockers.append('SOURCE_MANIFEST_NOT_BOUND_TO_PUBLICATION')
    if values:
        if values.get('site_uuid')!=site_uuid or values.get('languages')!=['en','es'] and company=='wayfatours':
            blockers.append('PARITY_WRONG_SITE_OR_LANGUAGE_SCOPE')
        stages=values.get('comparisons',{})
        direct=stages.get('live_source_to_target',{}).get('categories',{})
        facts['value_direct']=direct
        classified={'generated_cache_only':0,'allowed_origin_rewrite':0,'functional_drift':0,'missing_functional':0}
        for state,items in stages.get('live_source_to_target',{}).get('sample_differences',{}).items():
            for item in items:
                key=item['key'];field=key[-1]
                if field=='_elementor_css':bucket='generated_cache_only';reason='REGENERATE_ELEMENTOR_CSS_TARGET'
                elif field=='trans_gellery':bucket='allowed_origin_rewrite';reason='ASSERT_MEDIA_PATHS_AND_FILES'
                elif state=='missing_in_later':bucket='missing_functional';reason='RECONCILE_PROVIDER_VALUE_MISSING'
                else:bucket='functional_drift';reason='RECONCILE_PROVIDER_VALUE_DIFF'
                classified[bucket]+=1
                if bucket in ('functional_drift','missing_functional'):
                    classifications.append({'provider':key[0],'object_key':key,'discrepancy':state,'repair_action':reason,'automated_write_eligible':False})
        facts['classified']=classified
        if classified['functional_drift'] or classified['missing_functional']:
            blockers.append('UNRESOLVED_FUNCTIONAL_VALUE_PARITY')
        if classified['generated_cache_only']:
            blockers.append('GENERATED_CSS_REBUILD_READBACK_REQUIRED')
        if values.get('stats',{}).get('target',{}).get('format_error_count'):
            blockers.append('INVALID_TARGET_JSON_OR_PHP_SERIALIZED')
    if providers:
        if providers.get('site_uuid')!=site_uuid:blockers.append('PROVIDER_DIFF_WRONG_SITE')
        for name,row in providers.get('providers',{}).items():
            x=row.get('source_to_target',{})
            counts=x.get('counts',{}) if isinstance(x,dict) else {}
            functional={k:v for k,v in counts.items() if k in ('value_drift','missing','invalid_format','format_drift') and v}
            facts[name]=functional
            if functional:blockers.append('PROVIDER_DEFINITION_VALUE_DRIFT_'+name.upper())
        wpml=providers.get('wpml',{}).get('source_to_target',{})
        facts['wpml']=dict((k,wpml.get(k)) for k in ('shared_original_identities','trid_or_source_language_mismatches','missing'))
        if wpml.get('trid_or_source_language_mismatches'):
            blockers.append('WPML_TRANSLATION_GROUP_PARITY_REQUIRED')
    # Required plugin/code payloads are a separate origin-bound artifact contract.
    code=load(root/'wayfa-en-es-required-code-inventory.json') if company=='wayfatours' else load(root/'required-code-inventory.json')
    runtime=load(root/'wayfa-en-es-code-runtime-readback.json') if company=='wayfatours' else load(root/'code-runtime-readback.json')
    if not code or not runtime:
        blockers.append('REQUIRED_CODE_AND_PLUGIN_READBACK_MISSING')
    else:
        if code.get('site_uuid')!=site_uuid or runtime.get('site_uuid')!=site_uuid or runtime.get('canonical_origin')!=canonical_origin:
            blockers.append('CODE_MANIFEST_WRONG_SITE_IDENTITY')
        required=[v for v in code.get('plugins',[]) if v.get('scope')=='required_provider']
        incomplete=[v['name'] for v in required if v.get('missing_code_files',0)>0 or v.get('target_status')!='active']
        facts['code_required_count']=len(required)
        facts['code_required_missing_or_inactive']=incomplete
        if incomplete:blockers.append('REQUIRED_CODE_RELEASE_OR_PLUGIN_NOT_RECONCILED')
        mismatched=[v['name'] for v in required if v.get('source_release_tree_sha256')!=v.get('target_release_tree_sha256')]
        facts['required_code_release_drift']=mismatched
        if mismatched:blockers.append('REQUIRED_CODE_RELEASE_HASH_MISMATCH')
        if runtime.get('unresolved_safe_boot') is True:blockers.append('PLUGIN_SAFE_BOOT_PROVIDER_ACCEPTANCE_REQUIRED')
        if runtime.get('unresolved_pricing_resolver') is True:blockers.append('COMMERCIAL_PRICING_PROVIDER_CODE_UNAVAILABLE')
        if runtime.get('runtime',{}).get('navigation_enabled') is not True or runtime.get('runtime',{}).get('presentation_policy') is not True:
            blockers.append('TENANT_MU_PRESENTATION_RUNTIME_NOT_READY')
        if runtime.get('portable_hash_review_pending'):
            blockers.append('TENANT_MU_PORTABLE_HASH_REVIEW_REQUIRED')
    # Source/tenant Elementor indicative-price display is a functional readback,
    # not a plugin-present assumption. Require independently fresh, enrolled,
    # non-mutating evidence before accepting any Wayfa production release.
    if company=='wayfatours' and environment=='production' and canonical_origin=='https://wayfatours.com':
        price_report=load(ROOT/'reports/company-live-semantic-reconciliation-current.json')
        price_contract=(price_report or {}).get('comparison',{}).get('price_tag_contract',{})
        valid=False
        if (price_report and price_report.get('contract')=='mad4b.company-live-semantic-reconciliation.v1'
            and price_report.get('evidence_mode')=='LIVE_RUNTIME'
            and price_report.get('read_only') is True
            and price_report.get('writes_to_tenant') is False
            and price_report.get('source_origin')=='https://tours.dev.mad4b.com'
            and price_report.get('target_origin')==canonical_origin
            and price_report.get('company')==company and price_report.get('environment')==environment
            and price_contract.get('contract')=='mad4b.company-elementor-price-tag-runtime-readback.v1'
            and price_contract.get('state')=='PASS_VERIFIED'
            and price_contract.get('read_only') is True
            and price_contract.get('can_auto_apply') is False
            and price_contract.get('source',{}).get('site_uuid')
            and price_contract.get('target',{}).get('site_uuid')==site_uuid
            and price_contract.get('module_source_target_sha_match') is True
            and all(x.get('all_priced') for x in price_contract.get('frontend',[]))
            and {x.get('language') for x in price_contract.get('frontend',[])}=={'en','es'}):
            try:
                age=(datetime.datetime.now(datetime.timezone.utc)-datetime.datetime.fromisoformat(
                    price_report['observed_utc'].replace('Z','+00:00'))).total_seconds()
                valid=0<=age<=1200
            except (KeyError,ValueError,TypeError):valid=False
        facts['elementor_price_tag_runtime']={
            'status':price_contract.get('state','NOT_OBSERVED'),
            'live_current_source_target_verified':valid,
            'module_sha256_match':price_contract.get('module_source_target_sha_match') is True,
            'auto_apply':False}
        if not valid:blockers.append('ELEMENTOR_PRICE_TAG_RUNTIME_SOURCE_TARGET_EVIDENCE_REQUIRED')
        geo=price_report.get('comparison',{}).get('country_scope_contract',{}) if isinstance(price_report,dict) else {}
        geo_good=bool(valid and geo.get('contract')=='mad4b.company-geographic-operating-scope-runtime-readback.v1'
            and geo.get('state')=='PASS_VERIFIED' and geo.get('read_only') is True
            and geo.get('can_auto_publish') is False
            and geo.get('module_source_target_sha_match') is True
            and geo.get('target',{}).get('site_uuid')==site_uuid
            and geo.get('target',{}).get('policy_version')=='1'
            and geo.get('source',{}).get('policy_version')=='1'
            and geo.get('target',{}).get('allowed_country_ids'))
        facts['company_geographic_operating_scope']={
            'status':geo.get('state','NOT_OBSERVED'),
            'scope_verified':geo_good,
            'target_countries':geo.get('target',{}).get('allowed_country_ids',[]),
            'intentionally_held_draft_rate_count':geo.get('scope_held_published_rate_draft_parent_count',0),
            'auto_publish':False}
        if not geo_good:blockers.append('COMPANY_GEOGRAPHIC_SCOPE_RUNTIME_EVIDENCE_REQUIRED')

    # Currency is a live provider and financial-source contract, not a present-plugin check.
    # A company cannot pass parity if its currency shortcode resolves to a static
    # fallback or WCML/verified FX settings are absent. Never copy provider rates.
    if company=='wayfatours':
        currency=load(ROOT/'reports/company-currency-runtime-reconcile-current.json')
        if not currency or currency.get('contract')!='mad4b.company-currency-runtime-reconcile.v1' or \
           currency.get('target',{}).get('origin')!=canonical_origin or \
           currency.get('source',{}).get('origin')!='https://tours.dev.mad4b.com':
            blockers.append('CURRENCY_PROVIDER_LIVE_RECONCILE_EVIDENCE_MISSING')
        else:
            try:
                observed_at=dt.datetime.fromisoformat(currency['time_utc'])
                age=(dt.datetime.now(dt.timezone.utc)-observed_at).total_seconds()
            except (KeyError,ValueError,TypeError):age=float('inf')
            if age<0 or age>900:
                blockers.append('CURRENCY_NATIVE_PROVIDER_READBACK_STALE')
            facts['currency_runtime']={'source_blockers':currency.get('source_blockers',[]),
                'target_blockers':currency.get('target_blockers',[]),
                'financial_configuration_mutated':currency.get('financial_configuration_mutated'),
                'currency_reconciled':currency.get('currency_reconciled')}
            if currency.get('target_blockers') or currency.get('currency_reconciled') is not True:
                blockers.append('CURRENCY_NATIVE_PROVIDER_AND_FX_PARITY_REQUIRED')
            if currency.get('financial_configuration_mutated') is not False:
                blockers.append('UNAUTHORIZED_FINANCIAL_CONFIGURATION_MUTATION')
    # An external read-only CSS comparison can identify drift but NEVER
    # establish native publication authority. The original target provider
    # must supply its own exact-site signed acceptance receipt below.
    css_evidence=load(REPORTS/company/environment/site_uuid/'elementor-css-source-evidence.json')
    if not css_evidence:
        blockers.append('ELEMENTOR_CSS_SOURCE_TARGET_EVIDENCE_MISSING')
    else:
        identity_ok=(css_evidence.get('contract')=='mad4b.company-elementor-css-source-evidence.v1'
          and css_evidence.get('target_site_uuid')==site_uuid
          and (css_evidence.get('target') or {}).get('origin')==canonical_origin
          and css_evidence.get('wpcli_read_only') is True
          and css_evidence.get('publication_or_mutation_performed') is False)
        if not identity_ok:
            blockers.append('ELEMENTOR_CSS_EVIDENCE_TARGET_IDENTITY_INVALID')
        else:
            facts['elementor_css']={
              'source_origin':(css_evidence.get('source') or {}).get('origin'),
              'target_kit_id':(css_evidence.get('target') or {}).get('kit_id'),
              'source_kit_css_bytes':(css_evidence.get('source') or {}).get('kit_custom_css_bytes'),
              'target_kit_css_bytes':(css_evidence.get('target') or {}).get('kit_custom_css_bytes'),
              'source_and_target_kit_css_sha_equal':css_evidence.get('editable_kit_css_sha_equal'),
              'target_native_provider_present':(css_evidence.get('target') or {}).get('portable_native_reconcile_provider_file')
            }
            if css_evidence.get('editable_kit_css_sha_equal') is not True:
                blockers.append('TARGET_ELEMENTOR_KIT_EDITABLE_CSS_SOURCE_DRIFT')
            if (css_evidence.get('target') or {}).get('portable_native_reconcile_provider_file') is not True:
                blockers.append('TARGET_NATIVE_ELEMENTOR_CSS_PROVIDER_MISSING')
            # Even equal hashes and a present provider are NOT an acceptance
            # receipt for mapped templates, generated CSS, or the browser.
    # Source-owned UI evidence can FAIL admission but can never certify
    # publication by itself. Native interactive receipts remain independent.
    ui_path=REPORTS/company/environment/site_uuid/'ui-runtime-locale-evidence.json'
    ui=load(ui_path)
    if not ui:
        blockers.append('UI_RUNTIME_LOCALE_READBACK_REQUIRED')
    else:
        ui_bound=(ui.get('contract')=='mad4b.company-ui-runtime-locale-readback.v1'
            and ui.get('company_code')==company
            and ui.get('environment')==environment
            and ui.get('target_site_uuid')==site_uuid
            and ui.get('target_origin')==canonical_origin
            and ui.get('read_only') is True
            and ui.get('runtime_mutation_performed') is False)
        if not ui_bound:
            blockers.append('UI_RUNTIME_LOCALE_EVIDENCE_IDENTITY_INVALID')
        else:
            rows=ui.get('rows') or []
            flags=[f for item in rows for f in item.get('flags',[])]
            runtime_languages=set((runtime or {}).get('languages') or [])
            observed_languages=set(str((item.get('target') or {}).get('lang','')).split('-')[0].lower()
                for item in rows)
            missing_languages=sorted(runtime_languages-observed_languages)
            facts['ui_runtime_locale']={'rows':len(rows),'flags':sorted(set(flags)),
                'missing_languages':missing_languages,'native_receipt':False}
            if flags:blockers.append('UI_RUNTIME_LOCALE_BROWSER_PARITY_FAILED')
            if missing_languages or not rows:blockers.append('UI_RUNTIME_LOCALE_LANGUAGE_COVERAGE_INCOMPLETE')
            # Browser observations are not proof of clicking switchers, share,
            # Extras or quote recalculation, even when every snapshot passed.
            if ui.get('is_native_publication_receipt') is not True:
                blockers.append('UI_RUNTIME_LOCALE_NATIVE_INTERACTION_RECEIPT_REQUIRED')
    # Extras are a first-class provider domain. Evidence comes from a
    # registered native WP-CLI command on each site, never eval-file or
    # a company-specific fix script. It cannot issue publication grants.
    extra_evidence=load(REPORTS/company/environment/site_uuid/'extras-native-readback.json')
    if not extra_evidence:
        blockers.append('EXTRAS_NATIVE_RECONCILE_READBACK_REQUIRED')
    else:
        extra_identity=(extra_evidence.get('contract')=='mad4b.company-extras-source-native-readback.v1'
          and extra_evidence.get('company_code')==company
          and extra_evidence.get('environment')==environment
          and extra_evidence.get('site_uuid')==site_uuid
          and extra_evidence.get('target_origin')==canonical_origin
          and extra_evidence.get('read_only') is True
          and extra_evidence.get('production_mutated') is False
          and extra_evidence.get('is_native_publication_receipt') is False)
        if not extra_identity:
            blockers.append('EXTRAS_NATIVE_RECONCILE_SITE_IDENTITY_INVALID')
        else:
            flags=extra_evidence.get('flags') or []
            notices=extra_evidence.get('notices') or []
            facts['extras_reconcile']={'state':extra_evidence.get('state'),
                'source_release_sha256':extra_evidence.get('source_release_sha256'),
                'target_release_sha256':extra_evidence.get('target_release_sha256'),
                'flags':flags,'notices':notices,
                'source_published_eligible':(extra_evidence.get('source') or {}).get('plan',{}).get('eligible_listing_candidates'),
                'target_published_eligible':(extra_evidence.get('target') or {}).get('plan',{}).get('eligible_listing_candidates')}
            if flags or extra_evidence.get('state')=='RECONCILE_REQUIRED':
                blockers.append('EXTRAS_NATIVE_SOURCE_TARGET_RECONCILE_REQUIRED')
            # No published Extras is a content-readiness notice. It must not
            # silently trigger a public booking or clone QA data.
    # Signed independent host corrective code receipts are documented
    # separately; they do not replace original provider publication evidence.
    host_path=REPORTS/company/environment/site_uuid/'extras-native-host-repair-receipt.json'
    host=load(host_path)
    if host:
        signature_bound=(host_path.stat().st_uid==0 and not host_path.stat().st_mode&0o022
          and host.get('contract')=='mad4b.company-native-host-corrective-release-readback.v1'
          and host.get('state')=='SIGNED_HOST_NATIVE_CORRECTIVE_RELEASE_VERIFIED'
          and host.get('company_code')==company
          and host.get('environment')==environment
          and host.get('site_uuid')==site_uuid
          and host.get('target_origin')==canonical_origin
          and host.get('signed_source_manifests_verified') is True
          and host.get('host_native_operator_receipts_verified') is True
          and host.get('source_target_code_parity') is True
          and host.get('original_core_313_certified') is False
          and host.get('commercial_publication_approved') is False)
        if signature_bound:
            facts['extras_native_corrective_code_release']={
              'state':host['state'],'release_count':host['release_count'],
              'current_artifacts':host['unique_current_artifacts'],
              'host_native_receipts_verified':True,
              'original_313_certified':False,'commercial_publication_approved':False}
        else:
            blockers.append('EXTRAS_HOST_NATIVE_CORRECTIVE_RECEIPT_IDENTITY_INVALID')
    # ETG Staging only: frozen Core + Company Management code source gate.
    # This is a negative publication admission dependency, never an authority issuer.
    # A stale target inventory or missing independent runtime evidence is HOLD.
    if environment=='staging' and canonical_origin=='https://staging.egypttourgates.com':
        gate=ROOT/'bin/company-etg-core-release-gate.py'
        inventory=ROOT/'audits/20261103-etg-vs-central-source/etg-release-preflight-fresh-20261104.json'
        if site_uuid!='d745d81f-6fc4-5c6a-99dd-d953c92137bf':
            blockers.append('ETG_RELEASE_GATE_WRONG_SITE_IDENTITY')
        try:
            proc=subprocess.run([sys.executable,str(gate),'--target-inventory',str(inventory)],
                capture_output=True,text=True,timeout=45,check=False)
            gate_report=json.loads(proc.stdout)
            is_valid=(gate_report.get('contract')=='mad4b.etg-source-release-preflight.v1'
                and gate_report.get('target',{}).get('site_uuid')==site_uuid
                and gate_report.get('target',{}).get('origin')==canonical_origin
                and gate_report.get('publication_admitted') is False
                and gate_report.get('write_executed') is False
                and proc.returncode in (0,3))
            if not is_valid:
                blockers.append('ETG_CORE_ADDON_SOURCE_GATE_INVALID')
            facts['etg_core_addon_release']={
                'release_id':gate_report.get('release_id'),
                'release_sha256':gate_report.get('release_sha256'),
                'source_code_integrity_pass':gate_report.get('source_code_integrity_pass'),
                'dependency_gate_pass':gate_report.get('dependency_gate_pass'),
                'state':gate_report.get('status'),
                'blockers':gate_report.get('blockers',[]),
                'not_a_native_acceptance_receipt':True}
            if gate_report.get('status')!='PASS':
                blockers.append('ETG_CORE_ADDON_RELEASE_NOT_CERTIFIED')
        except (subprocess.SubprocessError,ValueError,OSError):
            blockers.append('ETG_CORE_ADDON_SOURCE_GATE_UNAVAILABLE')
    # ARE Staging: FAQ content reverse is advisory until independent frontend
    # and native publication proof. Evidence never authorizes a clone or grant.
    if company=='all-royal-egypt' and environment=='staging' and canonical_origin=='https://staging.allroyalegypt.com':
        try:
            faq_helper=ROOT/'bin/company-reconcile-faq-overlay-v1.py'
            faq_proc=subprocess.run([sys.executable,str(faq_helper),'--company',company,
                '--environment',environment,'--origin',canonical_origin,'--site-uuid',site_uuid],
                capture_output=True,text=True,timeout=20,check=False)
            faq=json.loads(faq_proc.stdout)
            faq_identity=(faq.get('contract')=='mad4b.company-reconcile.faq-overlay.v1'
                and faq.get('company')==company and faq.get('environment')==environment
                and faq.get('origin')==canonical_origin and faq.get('site_uuid')==site_uuid
                and faq.get('scope_applies') is True
                and faq.get('publication_eligible') is False
                and faq.get('writes_performed') is False and faq.get('grants_issued') is False)
            if not faq_identity or faq_proc.returncode not in (0,2):
                blockers.append('FAQ_RECONCILE_OVERLAY_IDENTITY_OR_EXECUTION_INVALID')
            else:
                facts['faq_reverse_advisory']={k:faq.get(k) for k in
                    ('state','facts','blockers','evidence_sha256','publication_eligible')}
                if faq.get('state')!='CONDITIONALLY_VERIFIED_NOT_NATIVE_PUBLICATION_RECEIPT':
                    blockers.append('FAQ_DEPENDENCY_FRONTEND_RECONCILE_HOLD')
        except (subprocess.SubprocessError,ValueError,OSError):
            blockers.append('FAQ_RECONCILE_OVERLAY_UNAVAILABLE')
    native_receipts=(observed or {}).get('checks') or {}
    checks=['migration_value_format_parity','migration_semantic_value_parity','migration_provider_definition_parity','migration_source_target_readback','elementor_css_native_compiled_and_visual_readback']
    for key in checks:
        r=native_receipts.get(key) or {}
        if r.get('state')!='PASS' or not r.get('native_provider_signature_verified') or not r.get('readback_verified'):
            blockers.append('NATIVE_CERTIFIED_RECEIPT_MISSING_'+key.upper())
    # No local report can mint native signatures; authenticity comes from original provider acceptance only.
    policy_fingerprint=hashlib.sha256(CHECKS.read_bytes()).hexdigest()
    result={'contract':'mad4b.company-reconcile-semantic-admission.v1','company_id':company,'environment':environment,
      'site_uuid':site_uuid,'canonical_origin':canonical_origin,'profile_revision':profile_revision,
      'report_directory':str(root),'policy_sha256':policy_fingerprint,'publication_accepted':False,
      'blockers':sorted(set(blockers)),'readiness':'BLOCKED_RECONCILE_EVIDENCE_REQUIRED',
      'observations':facts,'proposed_repairs':classifications,
      'automated_write_executed':False,'native_grants_issued':False}
    return result

def main():
    p=argparse.ArgumentParser();p.add_argument('--company',required=True);p.add_argument('--environment',required=True)
    p.add_argument('--site-uuid',required=True);p.add_argument('--origin',required=True);p.add_argument('--profile-revision');p.add_argument('--manifest-sha256');p.add_argument('--report-dir')
    a=p.parse_args();res=admission(a.company,a.environment,a.site_uuid,a.origin,a.profile_revision,a.manifest_sha256,a.report_dir)
    print(json.dumps(res,ensure_ascii=False,separators=(',',':')))
    sys.exit(0 if res['publication_accepted'] else 2)
if __name__=='__main__':main()
