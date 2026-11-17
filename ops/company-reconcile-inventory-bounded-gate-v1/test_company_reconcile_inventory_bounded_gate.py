#!/usr/bin/env python3
"""No network/host service interaction; prove the exact dual-gate semantics."""
from __future__ import annotations
import importlib.util,pathlib,unittest
p=pathlib.Path(__file__).with_name('company-reconcile-inventory-bounded-gate.py')
spec=importlib.util.spec_from_file_location('bounded_inventory_gate',p)
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class GateTests(unittest.TestCase):
 def with_codes(self,codes):
  calls=[]
  def runner(args,timeout):
   calls.append((args,timeout));return codes.pop(0),''
  original=m.trusted;m.trusted=lambda p:True
  try:out=m.one_attempt(runner)
  finally:m.trusted=original
  return out,calls
 def test_both_original_gates_needed(self):
  out,calls=self.with_codes([0,0,0]);self.assertTrue(out[0]);self.assertEqual(len(calls),3)
 def test_optional_denied_stops(self):
  out,calls=self.with_codes([1]);self.assertFalse(out[0]);self.assertEqual(len(calls),1)
 def test_cpu_sample_denied_stops(self):
  out,calls=self.with_codes([0,1]);self.assertFalse(out[0]);self.assertEqual(len(calls),2)
 def test_cpu_denied_stops(self):
  out,calls=self.with_codes([0,0,1]);self.assertFalse(out[0]);self.assertEqual(len(calls),3)
 def test_untrusted_original_scripts_block(self):
  original=m.trusted;m.trusted=lambda p:False
  try:self.assertEqual(m.one_attempt()[1],'admission_program_untrusted')
  finally:m.trusted=original
 def test_first_pass_no_sleep(self):
  calls=[]
  r=m.wait_for_window(20,clock=lambda:0,sleep=lambda n:calls.append(n),attempt=lambda:(True,'ok'))
  self.assertTrue(r['admitted']);self.assertEqual(r['attempts'],1);self.assertEqual(calls,[])
 def test_repeated_retry_without_forging_admission(self):
  tick=[0.0];calls=[]
  def sleeper(sec):tick[0]+=sec;calls.append(sec)
  r=m.wait_for_window(20,clock=lambda:tick[0],sleep=sleeper,attempt=lambda:(False,'original_cpu_budget_denied'))
  self.assertFalse(r['admitted']);self.assertEqual(r['retry'],'NEXT_EXISTING_TIMER');self.assertGreater(len(calls),0)
  self.assertFalse(r['original_gates_bypassed'])
 def test_try_again_then_admit(self):
  tick=[0.0];codes=[(False,'busy'),(True,'both_original_gates_admitted')]
  def sleeper(sec):tick[0]+=sec
  r=m.wait_for_window(20,clock=lambda:tick[0],sleep=sleeper,attempt=lambda:codes.pop(0))
  self.assertTrue(r['admitted']);self.assertEqual(r['attempts'],2)
 def test_bounded_arguments_fail_closed(self):
  for t in (-1,46,999):
   with self.assertRaises(ValueError):m.wait_for_window(t)
  self.assertEqual(m.main(['--max-wait','60']),2)
 def test_no_change_to_authority(self):
  r=m.wait_for_window(0,clock=lambda:0,sleep=lambda n:None,attempt=lambda:(False,'test'))
  self.assertEqual(r['write_jobs_created'],0);self.assertFalse(r['original_gates_bypassed'])
if __name__=='__main__':unittest.main(verbosity=2)
