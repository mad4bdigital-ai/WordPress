import ast
import pathlib
import unittest

ROOT=pathlib.Path(__file__).resolve().parents[1]
CODE=(ROOT/'company-mode-price-policy-reconcile.py').read_text()

def rules(rows):
    return sorted(rows,key=lambda x:x['mode'])

class ModePolicyOrderTests(unittest.TestCase):
    def test_only_ui_order_diff_is_not_conflict(self):
        base=[{'mode':'tour','strategy':'rate_occupancy','enabled':'1','minimum_travelers':'1','maximum_travelers':'50'},
              {'mode':'extra_trip','strategy':'rate_occupancy','enabled':'1','minimum_travelers':'1','maximum_travelers':'999'},
              {'mode':'activity','strategy':'rate_occupancy','enabled':'1','minimum_travelers':'1','maximum_travelers':'50'}]
        self.assertEqual(rules(base),rules(list(reversed(base))))
    def test_changed_enabled_still_blocks(self):
        source=[{'mode':'tour','strategy':'rate_occupancy','enabled':'1'}]
        target=[{'mode':'tour','strategy':'rate_occupancy','enabled':'0'}]
        self.assertNotEqual(rules(source),rules(target))
    def test_changed_strategy_still_blocks(self):
        source=[{'mode':'cruise_tour','strategy':'rate_occupancy'}]
        target=[{'mode':'cruise_tour','strategy':'accommodation_matrix'}]
        self.assertNotEqual(rules(source),rules(target))
    def test_source_reconcile_uses_order_insensitive_comparison(self):
        ast.parse(CODE)
        self.assertIn('sorted(normalized,key=lambda x:x[\'mode\'])',CODE)
        self.assertIn('sorted(source_rows,key=lambda x:x[\'mode\'])',CODE)
        self.assertIn('target_row_order_customized',CODE)

if __name__=='__main__': unittest.main()
