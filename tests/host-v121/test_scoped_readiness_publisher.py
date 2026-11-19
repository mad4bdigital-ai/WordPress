#!/usr/bin/env python3
"""v121 scoped Root-to-WordPress signed readiness publisher tests."""
from __future__ import annotations
import base64
import importlib.util
import hashlib
from pathlib import Path
import time
import unittest
from unittest.mock import patch
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat
ROOT=Path(__file__).resolve().parents[2]
p=ROOT/'ops/host-anchor-v121/publish_owner_readiness.py'
sp=importlib.util.spec_from_file_location('v121_test_scoped_readiness',p)
m=importlib.util.module_from_spec(sp);sp.loader.exec_module(m)

class SignedRootReadinessTests(unittest.TestCase):
    def setUp(self):
        self.key=Ed25519PrivateKey.generate()
        self.pub=self.key.public_key()
        self.t=int(time.time())
    def receipt(self):
        return m.signed_receipt(self.key,self.t)
    def signed_message(self,row):
        return (m.DOMAIN+"\n".join(str(row[k]) for k in m.FIELDS)).encode()
    def test_real_domain_separated_signed_envelope(self):
        row=self.receipt()
        self.assertEqual(tuple(row),m.FIELDS+("signature",))
        self.pub.verify(base64.b64decode(row["signature"]),
                        self.signed_message(row))
        self.assertEqual(row["company"],"all-royal-egypt")
        self.assertEqual(row["environment"],"staging")
        self.assertEqual(row["host"],"hosting-2")
        self.assertEqual(row["expires_epoch"]-row["issued_epoch"],21600)
    def test_signed_evidence_cannot_be_used_for_another_scope(self):
        original=self.receipt()
        self.pub.verify(base64.b64decode(original["signature"]),
                        self.signed_message(original))
        for k,bad in [("company","wayfatours"),("host","hosting-3"),
                      ("worker_sha256","f"*64),
                      ("issued_epoch",self.t+3600),
                      ("mode","all-operations")]:
            changed=original.copy();changed[k]=bad
            with self.subTest(k=k),self.assertRaises(Exception):
                self.pub.verify(base64.b64decode(changed["signature"]),
                                self.signed_message(changed))
    def test_original_broker_identities_and_no_owner_side_effects(self):
        php=m.php_source(self.receipt())
        for required in (
            'MAD4B_Central_VPS_Broker_V1::PUBKEY',
            'sodium_crypto_sign_verify_detached',
            'MAD4B_Original_Remote_Hostinger_328_V1::active',
            'MAD4B_Native_Host_Exact_Grant_V1::current_anchor_readback_v2',
            'update_option($key,$x,false)',
            'SIGNED_SCOPED_ROOT_READINESS_PUBLISHED',
            m.WORKER_SHA,m.RUNNER_SHA,
        ):
            self.assertIn(required,php)
        for forbidden in (
            'wp_set_current_user','rest_do_request','create_grant',
            'Original305','add_user','activate_plugin'):
            self.assertNotIn(forbidden,php)
        self.assertLess(len(php),8000)
    def test_unknown_worker_or_missing_root_permit_fails_before_wpcli(self):
        with patch.object(m,'pinned',side_effect=m.ReadinessHeld(
             "V121_STATUS_RUNNER_MISSING")),patch.object(m.subprocess,'run',
             side_effect=AssertionError("Unexpected WordPress write")):
            with self.assertRaisesRegex(m.ReadinessHeld,
                      'V121_STATUS_RUNNER_MISSING'):
                m.publish()
    def test_root_worker_or_signer_source_hash_must_be_pinned(self):
        self.assertEqual(len(m.WORKER_SHA),64)
        self.assertEqual(len(m.RUNNER_SHA),64)
        self.assertEqual(m.DOMAIN,
                         "MAD4B_HOST_AUTHORITY_V121_SCOPED_READINESS\n")
        self.assertEqual(m.OPTION,
                         "mad4b_host_authority_v121_scoped_readiness_v1")

if __name__=="__main__":
    unittest.main(verbosity=2)
