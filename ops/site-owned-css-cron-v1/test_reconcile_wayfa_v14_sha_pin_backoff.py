#!/usr/bin/env python3
"""Fixture-only tests; cannot touch live WordPress or attempt history."""
import datetime,importlib.util,pathlib,unittest
spec=importlib.util.spec_from_file_location('pin_reconcile',pathlib.Path(__file__).with_name('reconcile_wayfa_v14_sha_pin_backoff.py'))
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class PinRepair(unittest.TestCase):
 def setUp(self):
  self.old={'failures':1,'last_attempt':1795112825,'next_retry':1795113425}
  self.hist={m.KEY:dict(self.old),'source:public:118':{'failures':0}}
  self.receipt={'contract':'mad4b.css.wpcron.host-receipt.v1','site':'wayfa','job':'first_fold','ticket':39,'exit':5,'deferred':True,'completion_reason':'worker_exit_nonzero','worker_complete':False,'at':'2026-11-19T18:27:05Z','observed_output_tail':'CSS_NETWORK_PROFILE wayfa bridge\nHOLD wayfa live native consumer drift\n'}
  self.profile={'site_id':'wayfa','site_host':'wayfatours.com','environment':'production','renderer_sha256':m.RENDERER_SHA}
  self.pins=['a'*64,m.PHP_SHA]
 def validate(self):return m.evidence(self.hist,self.receipt,self.profile,self.pins)
 def test_exact_pre_browser_pin_failure(self):self.assertEqual(self.validate(),self.old)
 def test_only_single_failure_repaired(self):
  self.hist[m.KEY]['failures']=2
  with self.assertRaises(ValueError):self.validate()
 def test_changed_ticket_blocked(self):
  self.receipt['ticket']=40
  with self.assertRaises(ValueError):self.validate()
 def test_mismatched_consumer_pin_blocked(self):
  self.pins[1]='f'*64
  with self.assertRaises(ValueError):self.validate()
 def test_65_character_pin_blocked(self):
  self.pins[1]+='b'
  with self.assertRaises(ValueError):self.validate()
 def test_measured_QA_error_not_reclassified(self):
  self.receipt['observed_output_tail']='HOLD wayfa no fully certified language in batch'
  with self.assertRaises(ValueError):self.validate()
 def test_different_attempt_no_reset(self):
  self.hist[m.KEY]['last_attempt']+=60
  with self.assertRaises(ValueError):self.validate()
 def test_other_site_denied(self):
  self.profile['site_id']='source'
  with self.assertRaises(ValueError):self.validate()
if __name__=='__main__':unittest.main(verbosity=2)
