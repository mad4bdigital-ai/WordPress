#!/usr/bin/env python3
"""Broker fixed-command and Ed25519 regression tests; no live grants issued."""
import base64
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest import mock
from types import SimpleNamespace
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat,PrivateFormat,NoEncryption
import signed_broker as m
from remote_delegation import Target,Refused

UUID="aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa"
TARGET=Target("egypt-tour-gates","staging",UUID,"https://staging.egypttourgates.com")

class Tests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory()
        p=Path(self.tmp.name)
        self.private=p/"key.pem"
        self.public=p/"pub.txt"
        self.handler=p/"fixed.py"
        self.key=Ed25519PrivateKey.generate()
        self.private.write_bytes(self.key.private_bytes(Encoding.PEM,PrivateFormat.PKCS8,NoEncryption()))
        self.public.write_text(base64.b64encode(self.key.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)).decode())
        self.handler.write_text("print('stub, not invoked by test')\n")
        for f in (self.private,self.public,self.handler):f.chmod(0o600)
        self.calls=[]
    def tearDown(self):
        self.tmp.cleanup()
    def runner(self,argv,**kw):
        self.calls.append((argv,kw))
        envelope=json.loads(kw["input"])
        sig=base64.b64decode(envelope.pop("signature"))
        wire=json.dumps({k:envelope[k] for k in m.FIELDS},sort_keys=True,separators=(",",":"),ensure_ascii=True).encode()
        self.key.public_key().verify(sig,wire)
        envelope["signature"]=base64.b64encode(sig).decode()
        data={"contract":m.RESPONSE,"target":TARGET.company+":staging","action":"reconcile305",
              "state":"GRANT_ISSUED_CONFIRMED","grant_registered":True,
              "grant_issued":True,"grant_active":False,"production_mutated":False}
        return SimpleNamespace(returncode=0,stdout=json.dumps(data),stderr="")
    def broker(self,runner=None,priv=None,pub=None,handler=None):
        return m.SignedOriginal305Broker(runner=runner or self.runner,
             private=priv or self.private,public=pub or self.public,handler=handler or self.handler)
    def test_fixed_signed_dispatch_and_result(self):
        result=self.broker()("reconcile305",TARGET)
        self.assertTrue(result["grant_issued"])
        self.assertFalse(result["grant_active"])
        a,k=self.calls[0]
        self.assertEqual(a[1],str(self.handler))
        self.assertFalse(any("shell" in str(v) for v in a))
        self.assertEqual(k["timeout"],100)
    def test_signed_scope_not_other_company(self):
        value=m.signed_request("reconcile305",TARGET,100000,self.key,
                               self.key.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw))
        self.assertEqual(value["site_uuid"],UUID)
        self.assertEqual(value["company"],"egypt-tour-gates")
        self.assertEqual(value["environment"],"staging")
        self.assertEqual(value["expires"]-value["issued"],55)
    def test_wrong_action_not_signed(self):
        with self.assertRaisesRegex(Refused,"BROKER_FIXED_RECONCILE305_ONLY"):
            m.signed_request("shell",TARGET,1000,self.key,
                             self.key.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw))
    def test_private_pub_mismatch(self):
        alternate=Ed25519PrivateKey.generate().public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)
        with self.assertRaisesRegex(Refused,"BROKER_PINNED_KEY_MISMATCH"):
            m.signed_request("reconcile305",TARGET,1000,self.key,alternate)
    def test_untrusted_permissions_refused(self):
        self.handler.chmod(0o666)
        with self.assertRaisesRegex(Refused,"ORIGINAL_BROKER_FILE_NOT_TRUSTED"):
            self.broker()("reconcile305",TARGET)
        self.assertFalse(self.calls)
    def test_symlink_refused(self):
        alias=Path(self.tmp.name)/"alias.py"
        alias.symlink_to(self.handler)
        with self.assertRaisesRegex(Refused,"ORIGINAL_BROKER_FILE_NOT_TRUSTED"):
            self.broker(handler=alias)("reconcile305",TARGET)
    def test_no_root_refused(self):
        with mock.patch.object(m.os,"geteuid",return_value=1234):
            with self.assertRaisesRegex(Refused,"ORIGINAL_ROOT_BROKER_REQUIRED"):
                self.broker()("reconcile305",TARGET)
    def test_broker_input_does_not_allow_shell(self):
        with self.assertRaisesRegex(Refused,"BROKER_OPERATION_DENIED"):
            self.broker()("shell",TARGET)
    def test_status_fail_no_grant_claim(self):
        def runner(*args,**kw):return SimpleNamespace(returncode=2,stdout="",stderr="private")
        with self.assertRaisesRegex(Refused,"ORIGINAL_305_DISPATCH_DENIED_OR_UNCERTAIN"):
            self.broker(runner=runner)("reconcile305",TARGET)
    def test_wrong_target_receipt_denied(self):
        def runner(*args,**kw):
            return SimpleNamespace(returncode=0,stdout=json.dumps({
                "contract":m.RESPONSE,"target":"other:staging",
                "action":"reconcile305","production_mutated":False}),stderr="")
        with self.assertRaisesRegex(Refused,"ORIGINAL_305_EXACT_RECEIPT_MISMATCH"):
            self.broker(runner=runner)("reconcile305",TARGET)
    def test_unsafe_production_mutation_receipt_denied(self):
        def runner(*args,**kw):
            return SimpleNamespace(returncode=0,stdout=json.dumps({
                "contract":m.RESPONSE,"target":TARGET.company+":staging",
                "action":"reconcile305","production_mutated":True}),stderr="")
        with self.assertRaisesRegex(Refused,"ORIGINAL_305_EXACT_RECEIPT_MISMATCH"):
            self.broker(runner=runner)("reconcile305",TARGET)
    def test_incorrect_pin_file_refused(self):
        other=Ed25519PrivateKey.generate()
        self.public.write_text(base64.b64encode(other.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)).decode())
        with self.assertRaisesRegex(Refused,"BROKER_PINNED_KEY_MISMATCH"):
            self.broker()("reconcile305",TARGET)
    def test_request_nonce_changes(self):
        k=self.key
        pub=k.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)
        one=m.signed_request("reconcile305",TARGET,1234,k,pub)
        two=m.signed_request("reconcile305",TARGET,1234,k,pub)
        self.assertNotEqual(one["nonce"],two["nonce"])
if __name__=="__main__":unittest.main()

