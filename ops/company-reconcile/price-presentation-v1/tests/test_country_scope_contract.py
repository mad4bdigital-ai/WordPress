import importlib.util
from pathlib import Path
import unittest

P=Path(__file__).resolve().parents[1]/'country_scope_contract.py'
spec=importlib.util.spec_from_file_location('mad4b_geo_scope_contract_test',P)
geo=importlib.util.module_from_spec(spec)
spec.loader.exec_module(geo)

class GeographicOperatingScopeTests(unittest.TestCase):
    @staticmethod
    def make():
        sha='a'*64
        source={'module_sha256':sha,'policy_version':'1','site_uuid':'source',
          'allowed_country_ids':[665,2759,2764,2769],
          'active_language_codes':['en','es','de','fr','ar','it'],
          'language_scope_coverage':[{'language':l,'published_tour_count':1,'invalid_published_tour_ids':[],
               'geographic_states':{'IN_SCOPE':1},'menu_country_ids':[665]}
               for l in ('en','es','de','fr','ar','it')],
          'tour_rows':[{'id':n,'status':'publish','required_country_ids':countries,'scope_code':'IN_SCOPE'}
              for n,countries in [(60619,[665,2769]),(60621,[665,2764]),(60625,[665,2759]),(60663,[665,2764]),(60665,[665,2769])]]}
        target={'module_sha256':sha,'policy_version':'1','site_uuid':'wayfa',
          'allowed_country_ids':[665],
          'active_language_codes':['en','es'],
          'language_scope_coverage':[{'language':l,'published_tour_count':1,'invalid_published_tour_ids':[],
               'geographic_states':{'IN_SCOPE':1},'menu_country_ids':[665]} for l in ('en','es')],
          'tour_rows':[{'id':row['id'],'status':'draft','required_country_ids':row['required_country_ids'],
                        'scope_code':'HOLD_COUNTRY_NOT_ENABLED'} for row in source['tour_rows']],
          'draft_published_rate_total':16,'draft_published_rate_country_hold_count':16}
        return source,target
    def test_full_verified_intentional_hold(self):
        source,target=self.make();r=geo.assess(source,target)
        self.assertEqual(r['state'],'PASS_VERIFIED')
        self.assertEqual(r['scope_held_published_rate_draft_parent_count'],16)
        self.assertEqual(len(r['source_published_target_draft_scope_held_ids']),5)
        self.assertFalse(r['can_auto_publish'])
        self.assertFalse(r['mutates_tenant'])
    def test_target_country_mismatch_fails(self):
        source,target=self.make();target['tour_rows'][0]['required_country_ids']=[665]
        r=geo.assess(source,target)
        self.assertEqual(r['state'],'HOLD_GEOGRAPHIC_SCOPE_CONTRACT')
        self.assertTrue(any('TOUR_COUNTRY_REQUIREMENTS_SOURCE_TARGET_MISMATCH' in reason for reason in r['reason_codes']))
    def test_module_mismatch_fails(self):
        source,target=self.make();target['module_sha256']='b'*64
        self.assertIn('GEOGRAPHIC_SCOPE_SOURCE_TARGET_MODULE_DRIFT',geo.assess(source,target)['reason_codes'])
    def test_editorial_draft_is_not_country_hold(self):
        source,target=self.make();target['tour_rows'][0]['scope_code']='IN_SCOPE'
        r=geo.assess(source,target)
        self.assertIn(60619,r['source_published_target_draft_editorial_ids'])
        self.assertNotIn(60619,r['source_published_target_draft_scope_held_ids'])
    def test_missing_combined_country_evidence_fails(self):
        source,target=self.make();target['tour_rows'][0]['scope_code']='HOLD_COUNTRY_EVIDENCE_MISSING'
        r=geo.assess(source,target)
        self.assertIn('COMBINED_TOUR_COUNTRY_EVIDENCE_MISSING',r['reason_codes'])
    def test_rate_hold_overcount_fails(self):
        source,target=self.make();target['draft_published_rate_country_hold_count']=17
        self.assertIn('GEO_RATE_HOLD_COUNT_EXCEEDS_PARENT_RATES',geo.assess(source,target)['reason_codes'])
    def test_spanish_published_tour_country_misclassification_fails(self):
        source,target=self.make()
        target['language_scope_coverage'][1]['invalid_published_tour_ids']=[60661]
        target['language_scope_coverage'][1]['geographic_states']={'HOLD_COUNTRY_NOT_ENABLED':1}
        result=geo.assess(source,target)
        self.assertEqual(result['state'],'HOLD_GEOGRAPHIC_SCOPE_CONTRACT')
        self.assertIn('PUBLISHED_TOURS_OUTSIDE_OPERATING_SCOPE:target:es',result['reason_codes'])

    def test_missing_language_scan_fails_closed(self):
        source,target=self.make()
        del source['language_scope_coverage'][-1]
        result=geo.assess(source,target)
        self.assertIn('WPML_GEOGRAPHIC_LANGUAGE_COVERAGE_REQUIRED:source',result['reason_codes'])

    def test_source_arabic_locale_blocks_on_geo_regression(self):
        source,target=self.make()
        source['language_scope_coverage'][4]['invalid_published_tour_ids']=[64946]
        result=geo.assess(source,target)
        self.assertIn('PUBLISHED_TOURS_OUTSIDE_OPERATING_SCOPE:source:ar',result['reason_codes'])

    def test_readback_requires_enrolled_container(self):
        with self.assertRaises(ValueError):geo.probe({'container':'/bin/sh','origin':'https://example.com'})

if __name__=='__main__':unittest.main()
