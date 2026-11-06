import importlib.util
import subprocess
import unittest
from pathlib import Path
from unittest.mock import patch

FILE=Path('/opt/mad4b-company-transport/broker-v1/vps-broker-poller.py')
spec=importlib.util.spec_from_file_location('mad4b_broker_busy_defer',FILE)
m=importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)

class PollerBusyTests(unittest.TestCase):
    def probe(self,code,stderr='',stdout=''):
        response=subprocess.CompletedProcess(args=['wp'],returncode=code,
            stdout=stdout,stderr=stderr)
        with patch.object(m,'with_actor',return_value=['wp']), \
             patch.object(m.subprocess,'run',return_value=response):
            return m.wp_call(['pending'])
    def test_exact_busy_is_retryable(self):
        with self.assertRaises(m.RetryableWPCLIBusy):
            self.probe(75,'STAGING_WPCLI_BUSY: two active sessions')
    def test_other_75_remains_a_denial(self):
        with self.assertRaisesRegex(RuntimeError,'WPCLI_FAILURE_75'):
            self.probe(75,'AUTHORIZATION_DENIED')
    def test_other_failures_remain_denied(self):
        with self.assertRaisesRegex(RuntimeError,'WPCLI_FAILURE_1'):
            self.probe(1,'Permission denied')
    def test_verified_json_readback_required(self):
        self.assertEqual(self.probe(0,stdout='{"contract":"verified"}')[
                         'contract'],'verified')
        with self.assertRaisesRegex(RuntimeError,'NO_JSON_RECEIPT'):
            self.probe(0,stdout='invalid stdout')
    def test_claim_logic_never_assumes_wpcli_busy_is_success(self):
        with self.assertRaises(m.RetryableWPCLIBusy):
            self.probe(75,'STAGING_WPCLI_BUSY')

if __name__=='__main__':unittest.main()
