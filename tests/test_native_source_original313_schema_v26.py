"""V26 native source evidence must match first-party WordPress 313 shape."""
from pathlib import Path
import hashlib
import json
import subprocess
import unittest
import os
ROOT=Path(__file__).resolve().parents[1]
SRC=ROOT/'ops/native-source-publisher-v1'

class Original313SchemaV26(unittest.TestCase):
    def test_schema_contains_wordpress_required_independent_output(self):
        doc=json.loads((SRC/'catalog/evidence-schema.json').read_text())
        required={'grant_id','transport_id','result_status','output_sha256',
                  'expected_readback_sha256','readback_sha256','production_mutation'}
        self.assertTrue(required<=set(doc['fields']))
        self.assertEqual(doc['fields']['output_sha256'],'sha256')
        self.assertEqual(doc['success_state'],'HOST_READBACK_VERIFIED_PENDING_ORIGINAL_313')

    def test_generated_manifest_exact_evidence_sha(self):
        doc=json.loads((SRC/'catalog/generated/source.code.release.manifest.json').read_text())
        blob=(SRC/'catalog/evidence-schema.json').read_bytes()
        self.assertEqual(doc['evidence']['sha256'],hashlib.sha256(blob).hexdigest())
        self.assertEqual(doc['evidence_schema'],'source_code_release.v1')

    def test_php_original313_verifier_accepts_and_rejects_exact_schema(self):
        args=['docker','run','--rm','--pull=never','--network','none',
          '--read-only','--cap-drop=ALL','--security-opt=no-new-privileges',
          '-v',str(ROOT)+':/work:ro','--entrypoint','php',os.environ.get('MAD4B_TEST_PHP_IMAGE','php:8.5-cli'),
          '/work/tests/native-source-original313-evidence-v26.php']
        p=subprocess.run(args,capture_output=True,text=True,timeout=30)
        self.assertEqual(p.returncode,0,p.stderr[-400:]+p.stdout[-400:])
        payload=json.loads(p.stdout)
        self.assertEqual(payload['state'],'PASS')
        self.assertEqual(len(payload['strict_invalid_fields_rejected']),5)
        self.assertTrue(all(payload['strict_invalid_fields_rejected'].values()))
        self.assertFalse(payload['proof_signed'])
        self.assertFalse(payload['source_operation_live_ready'])

if __name__=='__main__':unittest.main()
