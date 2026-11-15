"""Historical same-company CSS tuning is only a QA input, not certification."""
import importlib.util
import sys
import unittest
from pathlib import Path
from types import ModuleType
from unittest.mock import patch

root=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(root))
# The engine's imports depend on the VPS performance intelligence module
# and are stubbed only in hermetic tests.
support=ModuleType('cache_coherence_intelligence_v3')
support._runtime_owner=lambda *a: (_ for _ in ()).throw(RuntimeError("no runtime"))
support._manifest=support._runtime_owner
support._wp_option=support._runtime_owner
sys.modules.setdefault('cache_coherence_intelligence_v3',support)
spec=importlib.util.spec_from_file_location('css_reconciliation_qa',root/'css_reconciliation_workflow_v3.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)

def record(path,lang,priority='high',preload=True,status='pass'):
 return {f'{path}|{lang}|mobile':{'gate_status':status,
    'css_fetch_priority':priority,'css_full_preload':preload,
    'evidence':{'requires_mobile_hero_priority':False,
                'requires_city_preload_deferred':False}}}
def manifest(rows):
 return {'site':'example.test','entries':{
  str(i):{'variants':row} for i,row in enumerate(rows)
 }}
routes=[{'path':'/','locale':'en','source_sha256':'new-sha-en'},
        {'path':'/es/','locale':'es','source_sha256':'new-sha-es'}]

class CandidateStrategyOnly(unittest.TestCase):
 def test_passed_historical_qa_tuning_advisory(self):
  p=m.prior_css_strategy_for_qa(manifest([record('/','en'),record('/es/','es')]),routes)
  self.assertEqual(p,{'priority':'high','preload':True,'hero900':False,'defer_city':False})
 def test_missing_one_language_denied(self):
  self.assertIsNone(m.prior_css_strategy_for_qa(manifest([record('/','en')]),routes))
 def test_conflicting_historical_entries_denied(self):
  obj=manifest([record('/','en'),record('/','en','low',False),record('/es/','es')])
  self.assertIsNone(m.prior_css_strategy_for_qa(obj,routes))
 def test_unpassed_history_denied(self):
  self.assertIsNone(m.prior_css_strategy_for_qa(manifest([
      record('/','en','high',True,'hold'),record('/es/','es')]),routes))
 def test_strong_source_never_implies_release(self):
  hints=m.prior_css_strategy_for_qa(manifest([record('/','en'),record('/es/','es')]),routes)
  self.assertFalse(any(key in hints for key in ('css_public_approved','original313_granted',
    'market_shared_cache_approved','release_admission')))
 def test_non_boolean_preload_is_not_accepted(self):
  bad=record('/','en');bad['/|en|mobile']['css_full_preload']='true'
  self.assertIsNone(m.prior_css_strategy_for_qa(manifest([bad,record('/es/','es')]),routes))

if __name__=='__main__':unittest.main()
