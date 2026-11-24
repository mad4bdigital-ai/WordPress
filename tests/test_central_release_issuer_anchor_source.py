"""Prove the rc129 .pub rename retains installed rc128 RSA verifier identity.

No private issuer key, owner credential, release verdict, or network access.
This is source continuity, not cryptographic release authorization.
"""
import base64
import hashlib
from pathlib import Path
import re
import unittest

ROOT=Path(__file__).resolve().parents[1]
PLUGIN=ROOT/'wp-content/plugins/mad4b-site-control-plane'
SOURCE_PEM=PLUGIN/'config/central-release-issuer-public.pub'
REGISTRY=PLUGIN/'includes/class-mad4b-scp-central-release-registry.php'
# Independently checked by openssl on both signed rc128 live installations:
# config/central-release-issuer-public.pem and the candidate .pub key.
LIVE_DER_SHA256='b9f47bfa3a5a9ff7c7630e0ca914fd4d3230f4dba942cdc00ac8c445e3217e7a'

class CentralIssuerTrustIdentity(unittest.TestCase):
    def test_source_public_key_is_exact_deployed_identity(self):
        body=SOURCE_PEM.read_bytes()
        self.assertNotIn(b'PRIVATE KEY',body)
        m=re.fullmatch(rb'-----BEGIN PUBLIC KEY-----\r?\n([A-Za-z0-9+/=\r\n]+)-----END PUBLIC KEY-----(?:\r?\n)*',body)
        self.assertIsNotNone(m,'The source must provide one valid SPKI public key, no extra payload')
        der=base64.b64decode(re.sub(rb'\s',b'',m.group(1)),validate=True)
        self.assertGreater(len(der),300)
        self.assertEqual(hashlib.sha256(der).hexdigest(),LIVE_DER_SHA256)

    def test_pinned_verifier_consumes_exact_candidate_file(self):
        src=REGISTRY.read_text()
        anchor='private static function pinned_public_key()'
        self.assertEqual(src.count(anchor),1)
        start=src.index(anchor)
        end=src.index('public static function verify_envelope(',start)
        method=src[start:end]
        self.assertIn("'/config/central-release-issuer-public.pub'",method)
        self.assertNotIn('central-release-issuer-public.pem',method)
        self.assertIn('is_link( $file )',method)
        self.assertIn('is_readable( $file )',method)
        self.assertIn("'-----BEGIN PUBLIC KEY-----'",method)
        self.assertIn('openssl_verify(',src)
        self.assertNotIn('PRIVATE KEY',method)

    def test_nonauthorizing_rename_does_not_add_second_trust_anchor(self):
        self.assertFalse((PLUGIN/'config/central-release-issuer-public.pem').exists())
        self.assertEqual([p.name for p in (PLUGIN/'config').glob('central-release-issuer-public.*')],
                         ['central-release-issuer-public.pub'])

if __name__=='__main__':unittest.main()
