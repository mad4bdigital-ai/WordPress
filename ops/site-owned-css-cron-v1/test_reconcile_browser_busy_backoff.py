#!/usr/bin/env python3
"""Unit-only tests; no WordPress or real attempt history mutations."""
import importlib.util,pathlib,unittest
spec=importlib.util.spec_from_file_location('mad4b_reconcile',pathlib.Path(__file__).with_name('reconcile_browser_busy_backoff.py'))
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class Evidence(unittest.TestCase):
 def setUp(self):
  self.timestamp=1795108926
  self.entry={'last_attempt':self.timestamp,'failures':8,'next_retry':1795112531}
  self.history={m.KEY:dict(self.entry),'source:public:118':{'failures':0}}
  self.receipt={'contract':'mad4b.css.wpcron.host-receipt.v1','site':'wayfa','job':'first_fold',
     'ticket':39,'exit':0,'deferred':True,'worker_complete':False,
     'at':'2026-11-19T17:22:06Z','completion_reason':'latest_home_event_gate_not_ready',
     'observed_output_tail':'CRITICAL_CSS_EVENT_GATE {"reason":"new_dependency_identity"}\n'+
       m.RECEIPT_BUSY+'\n'+m.RECEIPT_PENDING+'d457d9ff'}
 def test_corrects_only_proven_browser_busy(self):
  self.assertEqual(m.eligible(self.history,self.receipt),self.entry)
 def test_blocks_different_task(self):
  self.receipt['ticket']=40
  with self.assertRaises(ValueError):m.eligible(self.history,self.receipt)
 def test_blocks_stale_receipt(self):
  self.receipt['at']='2026-11-19T17:23:06Z'
  with self.assertRaises(ValueError):m.eligible(self.history,self.receipt)
 def test_blocks_missing_pending_marker(self):
  self.receipt['observed_output_tail']=m.RECEIPT_BUSY
  with self.assertRaises(ValueError):m.eligible(self.history,self.receipt)
 def test_blocks_actual_browser_qa(self):
  self.receipt['observed_output_tail']+='\nQA_DECISION {"lang":"en"}'
  with self.assertRaises(ValueError):m.eligible(self.history,self.receipt)
 def test_blocks_successful_certificate(self):
  self.receipt['worker_complete']=True
  with self.assertRaises(ValueError):m.eligible(self.history,self.receipt)
 def test_blocks_unclassified_evidence(self):
  self.receipt['completion_reason']='verified_home_result'
  with self.assertRaises(ValueError):m.eligible(self.history,self.receipt)
 def test_blocks_no_false_failures(self):
  self.history[m.KEY]['failures']=0
  with self.assertRaises(ValueError):m.eligible(self.history,self.receipt)
if __name__=='__main__':unittest.main(verbosity=2)
