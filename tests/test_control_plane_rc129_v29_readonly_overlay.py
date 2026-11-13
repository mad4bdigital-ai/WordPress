"""Two V29 readonly abilities over verified RC129 live source: exact overlay."""
import hashlib
import json
from pathlib import Path
import subprocess
import unittest

ROOT=Path(__file__).resolve().parents[1]
PLUGIN=ROOT/'wp-content/plugins/mad4b-site-control-plane'
REVIEW=ROOT/'docs/release-coverage/control-plane-rc129-v29-readonly-overlay-20261113.json'
SHA=lambda raw:hashlib.sha256(raw).hexdigest()

def assert_bootstrap_overlay_is_exact():
    """Verify the reviewed RC129 preimage by reversing only three pinned hooks.

    No local Git commits or build artefacts are needed; this can be run from
    an independently fetched review tree.
    """
    doc=json.loads(REVIEW.read_text())
    installed=(ROOT/doc['entrypoint']).read_bytes()
    assert SHA(installed)==doc['main_candidate_sha256'], 'BOOTSTRAP_CANDIDATE_SHA_DRIFT'
    text=installed.decode('utf-8')
    changes=[
        ("require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-operation-registry.php';\n",
         "// Source-only bounded catalog review; never installs or activates a Provider.\nrequire_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-native-source-release-review.php';\n"),
        ("\tMAD4B_SCP_Operation_Registry::boot();\n",
         "\tMAD4B_SCP_Native_Source_Release_Review::boot();\n"),
        ("MAD4B_SCP_FCP_Queue_Readback::boot();\n",
         "// Central Original305 Job/Claim observation only, not an execution authority.\nrequire_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-original-job-readback.php';\nMAD4B_SCP_Original_Job_Readback::boot();\n")
    ]
    for anchor,added in changes:
        assert text.count(anchor+added)==1, 'BOOTSTRAP_NONADDITIVE_CHANGED_HOOK'
        text=text.replace(anchor+added,anchor,1)
    assert SHA(text.encode('utf-8'))==doc['main_preimage_sha256'], 'BOOTSTRAP_RECONSTRUCTED_PREIMAGE_SHA_DRIFT'
    return doc['main_candidate_sha256']


class OriginalReadOnlyOverlay(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.review=json.loads(REVIEW.read_text())

    def test_original_rc129_preimage_and_exact_additive_insertion(self):
        self.assertEqual(assert_bootstrap_overlay_is_exact(),
                         self.review['main_candidate_sha256'])

    def test_v29_modules_exact_and_no_execution_approval(self):
        doc=self.review
        self.assertEqual(len(doc['modules_sha256']),2)
        for name,digest in doc['modules_sha256'].items():
            with self.subTest(name=name):
                src=(PLUGIN/name).read_bytes()
                self.assertEqual(SHA(src),digest)
                self.assertNotIn(b'wp_insert_post(',src)
                self.assertNotIn(b'update_option(',src)
                self.assertNotIn(b'file_put_contents(',src)
        for flag in ('registry_provider_enrolled','owner_signed_release_approved',
                     'production_mutation_authorized','original_job_mutated'):
            self.assertFalse(doc[flag])
        self.assertTrue(doc['read_only'])

if __name__=='__main__':unittest.main()
