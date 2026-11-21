"""Monotonic, signed, Root-only Desired registry CAS and site-isolation tests."""
import copy
import json
import sqlite3
import sys
import concurrent.futures
import tempfile
from pathlib import Path
import unittest
from cryptography.hazmat.primitives.serialization import Encoding, PublicFormat
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/"ops/host-authority-v128"))
import desired_registry_store as store
import live_first_reconciliation as engine
import test_live_first_reconciliation as fixture
import live_current_source as live
from persistent_enrollment import ConnectionDenied

NOW=fixture.NOW

def pub(k):
    return k.public_key().public_bytes(Encoding.Raw, PublicFormat.Raw)

class DesiredRegistryStoreTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root=Path(self.temp.name)
        self.wordpress_root=self.root/"wordpress"
        self.wordpress_root.mkdir()
        self.private=self.root/"private-registry"
        self.private.mkdir(mode=0o700)
        self.f=fixture.LiveFirstReconcileTests(
            "test_approved_desired_is_pinned_to_independent_registry")
        self.f.setUp()
        self.site=self.f.site
        self.f.row["revision"]=1
        # Separate the approved baseline fixture from the live signed
        # snapshot, so a deliberate test mutation cannot alter its signature.
        self.f.row["base_files"]=copy.deepcopy(self.f.row["base_files"])
        self.f.row["files"]=copy.deepcopy(self.f.row["files"])
        self.f.sign_desired()
        self.registry=store.DesiredRegistry(self.private,self.wordpress_root)

    def admit(self, expected=store.GENESIS_SHA256):
        return self.registry.admit(
            self.site,self.f.row,self.f.checkpoint,
            lambda binding:self.f.l.receipt,self.f.l.pub,fixture.live_tests.ORIGIN,
            pub(self.f.desired_key),pub(self.f.registry_key),
            expected_previous_sha256=expected,now=NOW)

    def next_revision(self):
        previous=copy.deepcopy(self.f.row)
        self.f.row={
            **{key:copy.deepcopy(previous[key])
               for key in engine.DESIRED_FIELDS},
            "revision":previous["revision"]+1,
            "source_head_sha":"9"*40,
            "base_files":copy.deepcopy(previous["files"]),
        }
        self.f.row["files"][-1]["sha256"]="d"*64
        self.f.sign_desired()

    def test_signed_genesis_uses_real_host_observation(self):
        receipt=self.admit()
        self.assertEqual(receipt["new_revision"],1)
        self.assertTrue(receipt["independent_highwater_checked"])
        self.assertTrue(receipt["registry_record_committed"])
        self.assertFalse(receipt["wordpress_mutation_performed"])
        self.assertFalse(receipt["host_write_authorized"])
        self.assertFalse(receipt["candidate_deployed"])
        head=self.registry.read_historical_head(self.site)
        self.assertEqual(head["revision"],1)
        self.assertFalse(head["current_authority_verified"])
        self.assertEqual(head["approved_record_sha256"],
                         receipt["approved_record_sha256"])

    def read_current(self):
        return self.registry.read_current_approved(
            self.site,lambda binding:self.f.checkpoint,
            pub(self.f.desired_key),pub(self.f.registry_key),now=NOW)

    def test_current_desired_read_requires_fresh_independent_highwater(self):
        self.admit()
        active=self.read_current()
        self.assertEqual(active["approved_revision"],1)
        self.assertEqual(active["desired"].source_head_sha,
                         self.f.row["source_head_sha"])
        self.assertTrue(active["current_authority_verified"])
        self.assertTrue(active["independent_current_highwater_verified"])
        self.assertFalse(active["mutations_performed"])
        self.assertFalse(active["host_write_authorized"])

    def test_historical_head_alone_never_qualifies_as_current(self):
        self.admit()
        historic=self.registry.read_historical_head(self.site)
        self.assertFalse(historic["current_authority_verified"])
        self.assertNotIn("desired",historic)

    def test_missing_highwater_reader_denied(self):
        self.admit()
        with self.assertRaisesRegex(
                ConnectionDenied,"DESIRED_REGISTRY_INDEPENDENT_READER_REQUIRED"):
            self.registry.read_current_approved(
                self.site,None,pub(self.f.desired_key),pub(self.f.registry_key),
                now=NOW)

    def test_revoked_highwater_invalidates_current_without_rewriting_db(self):
        self.admit()
        original=self.registry.read_historical_head(self.site)
        self.f.checkpoint["state"]="REVOKED"
        self.f.sign_highwater()
        with self.assertRaisesRegex(ConnectionDenied,"DESIRED_REGISTRY_NOT_CURRENT"):
            self.read_current()
        self.assertEqual(
            original["approved_record_sha256"],
            self.registry.read_historical_head(self.site)["approved_record_sha256"])

    def test_fresh_but_wrong_registry_revision_denied(self):
        self.admit()
        self.f.checkpoint["revision"]=2
        self.f.sign_highwater()
        with self.assertRaisesRegex(ConnectionDenied,"DESIRED_REGISTRY_NOT_CURRENT"):
            self.read_current()

    def test_stale_highwater_cannot_use_historical_approved_record(self):
        self.admit()
        self.f.checkpoint["observed_epoch"]=NOW-45
        self.f.sign_highwater()
        with self.assertRaisesRegex(ConnectionDenied,"DESIRED_REGISTRY_NOT_CURRENT"):
            self.read_current()

    def test_independent_highwater_signature_required_on_every_read(self):
        self.admit()
        self.f.checkpoint["signature"]="AA=="
        with self.assertRaisesRegex(ConnectionDenied,"DESIRED_REGISTRY_SIGNATURE_INVALID"):
            self.read_current()

    def test_unenrolled_site_cannot_read_another_sites_desired(self):
        self.admit()
        outsider=self.site.__class__(**{**self.site.__dict__,
            "site_uuid":"22222222-2222-4222-8222-222222222222"})
        with self.assertRaisesRegex(ConnectionDenied,"DESIRED_REGISTRY_CURRENT_MISSING"):
            self.registry.read_current_approved(
                outsider,lambda binding:self.f.checkpoint,
                pub(self.f.desired_key),pub(self.f.registry_key),now=NOW)

    def test_second_revision_read_requires_new_independent_highwater(self):
        first=self.admit()
        old_checkpoint=copy.deepcopy(self.f.checkpoint)
        self.next_revision()
        self.admit(first["approved_record_sha256"])
        active=self.read_current()
        self.assertEqual(active["approved_revision"],2)
        self.f.checkpoint=old_checkpoint
        with self.assertRaisesRegex(ConnectionDenied,"DESIRED_REGISTRY_NOT_CURRENT"):
            self.read_current()

    def test_successor_must_advance_exact_revision_and_base(self):
        first=self.admit()
        self.next_revision()
        result=self.admit(first["approved_record_sha256"])
        self.assertEqual(result["previous_revision"],1)
        self.assertEqual(result["new_revision"],2)
        self.assertNotEqual(result["previous_sha256"],
                            result["approved_record_sha256"])
        self.assertEqual(self.registry.read_historical_head(self.site)["revision"],2)

    def test_concurrent_same_revision_exactly_one_commit(self):
        def worker(_):
            try:
                result=self.admit()
                return ("committed",result["new_revision"])
            except ConnectionDenied as error:
                return ("denied",str(error))
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            results=list(pool.map(worker,range(2)))
        self.assertEqual(sum(x[0]=="committed" for x in results),1)
        self.assertEqual(sum(x[0]=="denied" for x in results),1)
        denied=next(x[1] for x in results if x[0]=="denied")
        self.assertEqual(denied,"DESIRED_LEDGER_CAS_PREIMAGE_MISMATCH")
        self.assertEqual(self.registry.read_historical_head(self.site)["revision"],1)
        with sqlite3.connect(str(self.private/store.DB_NAME)) as conn:
            self.assertEqual(conn.execute("SELECT count(*) FROM records").fetchone()[0],1)

    def test_replayed_revision_denied(self):
        first=self.admit()
        with self.assertRaisesRegex(ConnectionDenied,"DESIRED_LEDGER_CAS_PREIMAGE_MISMATCH"):
            self.admit()
        self.assertEqual(self.registry.read_historical_head(self.site)["revision"],1)

    def test_wrong_previous_head_rejected(self):
        self.admit()
        self.next_revision()
        with self.assertRaisesRegex(ConnectionDenied,"DESIRED_LEDGER_CAS_PREIMAGE_MISMATCH"):
            self.admit("a"*64)

    def test_skipped_revision_rejected(self):
        first=self.admit()
        self.next_revision()
        self.f.row["revision"]=4
        self.f.sign_desired()
        with self.assertRaisesRegex(ConnectionDenied,"DESIRED_LEDGER_NON_MONOTONIC_REVISION"):
            self.admit(first["approved_record_sha256"])

    def test_successor_baseline_cannot_silently_drop_history(self):
        first=self.admit()
        self.next_revision()
        self.f.row["base_files"][-1]["sha256"]="f"*64
        self.f.sign_desired()
        with self.assertRaisesRegex(ConnectionDenied,"DESIRED_LEDGER_PREDECESSOR_BASE_DRIFT"):
            self.admit(first["approved_record_sha256"])

    def test_first_desired_baseline_must_match_signed_live_current(self):
        self.f.row["base_files"][-1]["sha256"]="f"*64
        self.f.sign_desired()
        with self.assertRaisesRegex(ConnectionDenied,"DESIRED_LEDGER_GENESIS_NOT_LIVE_BASED"):
            self.admit()
        self.assertIsNone(self.registry.read_historical_head(self.site))

    def test_missing_host_reader_cannot_approve_source(self):
        with self.assertRaisesRegex(ConnectionDenied,"LIVE_HOST_RUNNER_READER_REQUIRED"):
            self.registry.admit(
                self.site,self.f.row,self.f.checkpoint,
                None,self.f.l.pub,fixture.live_tests.ORIGIN,
                pub(self.f.desired_key),pub(self.f.registry_key),
                expected_previous_sha256=store.GENESIS_SHA256,now=NOW)

    def test_independent_host_source_and_registry_keys_required(self):
        with self.assertRaisesRegex(ConnectionDenied,"INDEPENDENT_SIGNERS_REQUIRED"):
            self.registry.admit(
                self.site,self.f.row,self.f.checkpoint,
                lambda binding:self.f.l.receipt,pub(self.f.desired_key),
                fixture.live_tests.ORIGIN,
                pub(self.f.desired_key),pub(self.f.registry_key),
                expected_previous_sha256=store.GENESIS_SHA256,now=NOW)

    def test_production_requires_separate_approved_route(self):
        production=self.site.__class__(**{**self.site.__dict__,
            "environment":"production"})
        with self.assertRaisesRegex(ConnectionDenied,"DESIRED_LEDGER_STAGING_ONLY"):
            self.registry.admit(
                production,self.f.row,self.f.checkpoint,
                lambda binding:self.f.l.receipt,self.f.l.pub,
                fixture.live_tests.ORIGIN,
                pub(self.f.desired_key),pub(self.f.registry_key),
                expected_previous_sha256=store.GENESIS_SHA256,now=NOW)

    def test_wrong_host_key_rejected(self):
        bad=pub(Ed25519PrivateKey.generate())
        with self.assertRaisesRegex(ConnectionDenied,"LIVE_CURRENT_SIGNATURE_INVALID"):
            self.registry.admit(
                self.site,self.f.row,self.f.checkpoint,
                lambda binding:self.f.l.receipt,bad,fixture.live_tests.ORIGIN,
                pub(self.f.desired_key),pub(self.f.registry_key),
                expected_previous_sha256=store.GENESIS_SHA256,now=NOW)

    def test_wrong_source_owner_signature_rejected(self):
        self.f.row["source_head_sha"]="f"*40
        with self.assertRaisesRegex(ConnectionDenied,"DESIRED_SIGNATURE_INVALID"):
            self.admit()

    def test_revoked_independent_ledger_denied(self):
        self.f.checkpoint["state"]="REVOKED"
        self.f.sign_highwater()
        with self.assertRaisesRegex(ConnectionDenied,"DESIRED_REGISTRY_NOT_CURRENT"):
            self.admit()

    def test_old_checkpoint_rejected(self):
        self.f.checkpoint["observed_epoch"]=NOW-50
        self.f.sign_highwater()
        with self.assertRaisesRegex(ConnectionDenied,"DESIRED_REGISTRY_NOT_CURRENT"):
            self.admit()

    def test_site_scope_cannot_be_reused_elsewhere(self):
        other=self.f.site.__class__(**{**self.f.site.__dict__,
            "site_uuid":"22222222-2222-4222-8222-222222222222"})
        with self.assertRaisesRegex(ConnectionDenied,"LIVE_CURRENT_SITE_BINDING_MISMATCH"):
            self.registry.admit(
                other,self.f.row,self.f.checkpoint,
                lambda binding:self.f.l.receipt,self.f.l.pub,fixture.live_tests.ORIGIN,
                pub(self.f.desired_key),pub(self.f.registry_key),
                expected_previous_sha256=store.GENESIS_SHA256,now=NOW)

    def test_private_store_not_inside_wordpress(self):
        inside=self.wordpress_root/"inside"
        inside.mkdir(mode=0o700)
        with self.assertRaisesRegex(ConnectionDenied,"DESIRED_LEDGER_CANNOT_LIVE_INSIDE_WORDPRESS"):
            store.DesiredRegistry(inside,self.wordpress_root)

    def test_world_readable_root_denied(self):
        self.private.chmod(0o755)
        with self.assertRaisesRegex(ConnectionDenied,"DESIRED_LEDGER_ROOT_OWNER_OR_MODE_INVALID"):
            store.DesiredRegistry(self.private,self.wordpress_root)

    def test_symlinked_registry_root_denied(self):
        link=self.root/"linked"
        link.symlink_to(self.private,target_is_directory=True)
        with self.assertRaisesRegex(ConnectionDenied,"DESIRED_LEDGER_LINK_FORBIDDEN"):
            store.DesiredRegistry(link,self.wordpress_root)

    def test_database_created_with_owner_only_mode(self):
        self.admit()
        db=self.private/store.DB_NAME
        self.assertEqual(db.stat().st_mode & 0o777,0o600)

    def test_database_symlink_rejected_before_read(self):
        (self.private/store.DB_NAME).symlink_to(self.wordpress_root/"wp-config.php")
        with self.assertRaisesRegex(ConnectionDenied,"DESIRED_LEDGER_DATABASE_SYMLINK_DENIED"):
            self.admit()

    def test_sqlite_rollback_leaves_prior_head_untouched(self):
        first=self.admit()
        self.next_revision()
        self.f.row["base_files"][-1]["sha256"]="f"*64
        self.f.sign_desired()
        with self.assertRaisesRegex(ConnectionDenied,"DESIRED_LEDGER_PREDECESSOR_BASE_DRIFT"):
            self.admit(first["approved_record_sha256"])
        with sqlite3.connect(str(self.private/store.DB_NAME)) as conn:
            count=conn.execute("SELECT count(*) FROM records").fetchone()[0]
            self.assertEqual(count,1)
            head=conn.execute("SELECT revision FROM heads").fetchone()[0]
            self.assertEqual(head,1)

if __name__=="__main__":unittest.main()
