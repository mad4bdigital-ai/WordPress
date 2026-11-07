#!/usr/bin/env python3
"""Read-only fixture tests; never connects to live WordPress or mutates its DB."""
import copy
import importlib.util
import json
from pathlib import Path
import unittest

ROOT=Path('/opt/mad4b-company-provisioning')
SCRIPT=ROOT/'bin/company-live-semantic-discovery-v1.py'
SAMPLES=ROOT/'reports/live-semantic-reconciliation-v1-20261107'
spec=importlib.util.spec_from_file_location('mad4b_live_semantic',SCRIPT)
m=importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)

class LiveSemanticDetection(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.source=json.loads((SAMPLES/'source-discovery.json').read_text())
        cls.wayfa=json.loads((SAMPLES/'wayfa-discovery.json').read_text())

    def test_live_baseline_honors_program_and_package(self):
        result=m.semantic_compare(self.source,self.wayfa)
        h=result['live_target_health']
        self.assertTrue(h['program_filters_correct'])
        self.assertEqual(h['published_rates_missing_package'],0)
        self.assertEqual(h['wpml_rate_parent_mismatch'],0)
        self.assertTrue(h['jsf_signed'])
        self.assertTrue(h['dfsb_slots_available'])
        self.assertFalse(result['auto_apply'])

    def test_bad_query_taxonomy_detected_independently_of_filter_id(self):
        target=copy.deepcopy(self.wayfa)
        row=next(x for x in target['observations']['filters']['definitions']
                 if x['query_var']=='related_program_term_id')
        row['query_id']=9876
        row['query_taxonomies']=['duration-options']
        row['configured_taxonomy']='duration-options'
        result=m.semantic_compare(self.source,target)
        self.assertFalse(result['live_target_health']['program_filters_correct'])
        self.assertTrue(any(x['field']=='query_taxonomies' and
                            x['filter_id']==row['id'] for x in result['filter_semantic_drift']))
        self.assertFalse(result['auto_apply'])

    def test_missing_packages_regression_visible_but_no_write(self):
        target=copy.deepcopy(self.wayfa)
        target['observations']['rates']['counts']['published_missing_package']=32
        result=m.semantic_compare(self.source,target)
        self.assertEqual(result['live_target_health']['published_rates_missing_package'],32)
        self.assertFalse(result['auto_apply'])

    def test_wpml_sibling_intent_comparison(self):
        target=copy.deepcopy(self.wayfa)
        byid={x['id']:x for x in self.source['observations']['wpml']['rate_groups']}
        row=next(x for x in target['observations']['wpml']['rate_groups'] if x['id'] in byid)
        row['source_language']='fr' if row['source_language']!='fr' else 'es'
        result=m.semantic_compare(self.source,target)
        self.assertTrue(any(x['field']=='source_language' and
                            x['rate_id']==row['id'] for x in result['wpml_rate_semantic_drift']))

    def test_historical_repairs_are_verified_against_runtime_not_only_receipts(self):
        target=copy.deepcopy(self.wayfa)
        manifest=json.loads((ROOT/'reports/rate-program-perf-deep-review-20261107/package-fk-repair-manifest.json').read_text())
        target['observations']['rates']['published_rate_details']=[
            {'id':r['id'],'package_term_id':manifest['target_term']} for r in manifest['records']]
        observations=m.receipt_milestones(self.source,target)
        self.assertEqual(observations['program_query_316_to_programs']['state'],'VERIFIED_CURRENT')
        self.assertEqual(observations['package_level_rates']['state'],'VERIFIED_CURRENT')
        self.assertEqual(observations['wpml_rate_sibling_link']['state'],'VERIFIED_CURRENT')
        target['observations']['rates']['published_rate_details'][0]['package_term_id']=0
        observations=m.receipt_milestones(self.source,target)
        self.assertEqual(observations['package_level_rates']['state'],'DRIFT_DETECTED')

    def test_fastcgi_policy_readback_not_cache_hit_claim(self):
        config=json.loads((ROOT/'policies/live-semantic-discovery-sites-v1.json').read_text())
        source=m.nginx_cache_readback(config['source'])
        target=m.nginx_cache_readback(config['targets'][0])
        self.assertIn(1800,source['public_ttl_seconds_observed'])
        self.assertIn(1800,target['public_ttl_seconds_observed'])
        self.assertFalse(source['cache_hit_verified'])
        self.assertFalse(target['cache_hit_verified'])

    def test_semantic_digest_ignores_capture_clock(self):
        target=copy.deepcopy(self.wayfa)
        target['captured_utc']='2030-10-10T10:10:10Z'
        self.assertEqual(m.stable_digest(self.wayfa),m.stable_digest(target))
        target['observations']['runtime']['jet_smart_filters_signature']=False
        self.assertNotEqual(m.stable_digest(self.wayfa),m.stable_digest(target))

    def test_plugin_drift_does_not_authorize_transfer(self):
        target=copy.deepcopy(self.wayfa)
        target['observations']['plugin_code_fingerprints']['dynamic-filter-seo-bridge/assets/js/ajax-filter-state.js']='0'*64
        result=m.semantic_compare(self.source,target)
        self.assertTrue(result['code_drift'])
        self.assertTrue(all(x['apply_authorized'] is False for x in result['code_drift']))

if __name__=='__main__':
    unittest.main()
