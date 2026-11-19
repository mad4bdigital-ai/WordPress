import importlib.util
from pathlib import Path
import unittest

P = Path(__file__).resolve().parents[1] / 'price_tag_contract.py'
spec = importlib.util.spec_from_file_location('mad4b_price_tag_contract_under_test', P)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

class PriceTagContractTests(unittest.TestCase):
    def record(self):
        return {'module_sha256': 'a' * 64, 'tag_function_loaded': True,
                'tag_registered_in_elementor': True, 'evidence_provider_loaded': True,
                'price_tag_used_in_published_template': True, 'sample': {'id': 16}}
    def frontend(self):
        return [{'language': 'en', 'all_priced': True, 'label_localized': True},
                {'language': 'es', 'all_priced': True, 'label_localized': False}]
    def test_pass_with_independent_locale_warning(self):
        r = module.assess(self.record(), self.record(), self.frontend())
        self.assertEqual(r['state'], 'PASS_VERIFIED')
        self.assertFalse(r['target_p1'])
        self.assertFalse(r['can_auto_apply'])
    def test_missing_tag_blocks(self):
        target = self.record(); target['tag_registered_in_elementor'] = False
        r = module.assess(self.record(), target, self.frontend())
        self.assertEqual(r['state'], 'HOLD_PRICE_PRESENTATION_CONTRACT')
        self.assertIn('TARGET_ELEMENTOR_PRICE_TAG_NOT_REGISTERED', r['reason_codes'])
    def test_source_drift_blocks(self):
        target = self.record(); target['module_sha256'] = 'b' * 64
        r = module.assess(self.record(), target, self.frontend())
        self.assertIn('ENGINE_PRICE_TAG_MODULE_SOURCE_TARGET_SHA_DRIFT', r['reason_codes'])
    def test_missing_prices_block(self):
        pages = self.frontend(); pages[1]['all_priced'] = False
        self.assertEqual(module.assess(self.record(), self.record(), pages)['state'],
                         'HOLD_PRICE_PRESENTATION_CONTRACT')
    def test_missing_frontend_evidence_fail_closed(self):
        self.assertEqual(module.assess(self.record(), self.record(), None)['state'],
                         'HOLD_PRICE_PRESENTATION_CONTRACT')
    def test_not_applicable_is_not_claimed_as_verified(self):
        source=self.record();target=self.record()
        source['price_tag_used_in_published_template']=False
        target['price_tag_used_in_published_template']=False
        result=module.assess(source,target,None)
        self.assertEqual(result['state'],'NOT_APPLICABLE_NO_PUBLISHED_PRICE_LISTING')
        self.assertFalse(result['target_p1'])
    def test_missing_published_listing_template_blocks_when_source_requires_it(self):
        target=self.record();target['price_tag_used_in_published_template']=False
        result=module.assess(self.record(), target, None)
        self.assertEqual(result['state'],'HOLD_PRICE_PRESENTATION_CONTRACT')
        self.assertIn('SOURCE_PRICED_TEMPLATE_MISSING_ON_TARGET',result['reason_codes'])
if __name__ == '__main__':unittest.main()
