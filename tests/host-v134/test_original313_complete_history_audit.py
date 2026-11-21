"""Original313 archival signed-chain census and fail-closed evidence gaps."""
import base64
import hashlib
import json
from pathlib import Path
import os
import sys
import tempfile
import unittest
from unittest import mock
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/"ops/host-authority-v128"))
import original313_complete_history_audit as a

def signature(row,domain,key):
    payload={k:v for k,v in row.items() if k!="signature"}
    msg=(domain+"\n"+json.dumps(payload,sort_keys=True,separators=(",",":"),ensure_ascii=False)).encode()
    return base64.b64encode(key.sign(msg)).decode()

def sign(row,domain,key):
    row["signature"]=signature(row,domain,key)
    return row

def sha_payload(row,domain):
    payload={k:v for k,v in row.items() if k!="signature"}
    msg=(domain+"\n"+json.dumps(payload,sort_keys=True,separators=(",",":"),ensure_ascii=False)).encode()
    return hashlib.sha256(msg).hexdigest()

class LedgerAuditTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root=Path(self.tmp.name)/"store"
        self.root.mkdir(mode=0o700)
        self.folders={}
        for name in a.V2_DIRS:
            p=self.root/name;p.mkdir(mode=0o700);self.folders[name]=p
        self.index=Path(self.tmp.name)/"index.json"
        self.broker=Ed25519PrivateKey.generate()
        self.independent=Ed25519PrivateKey.generate()
        from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat
        pub=lambda k:k.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)
        self.index.write_text(json.dumps({
           "contract":a.INDEX_CONTRACT,"installed_by_first_party":True,
           "host_anchor_312_public_key_b64":base64.b64encode(pub(self.broker)).decode(),
           "independent_verifier_public_key_b64":base64.b64encode(pub(self.independent)).decode(),
        }))
        self.index.chmod(0o600)
        self._write(self.root/".first-issue-root-commissioned-v121.json",
                    {"contract":"test.commissioning.v1"})
        self.base={
          "company":"example-tenant","environment":"staging",
          "site_uuid":"49c562d1-8f2f-456f-b454-26816c6ba4cb",
          "revision":1,"grant_revision":0,
          "anchor_nonce":"a"*32,"state":"ACTIVE",
        }
        ledger=sign({**self.base,"contract":a.V2_LEDGER,
           "target_host_id":"hosting-2","ssh_principal":"user",
           "event_id":"b"*32,"previous_event_sha256":"0"*64,
           "issued_epoch":1700000000,"expires_epoch":1700000200
        },a.DOMAINS[a.V2_LEDGER],self.broker)
        self.ledger_path=self.folders["revisions"]/"example-tenant--staging.json"
        self._write(self.ledger_path,ledger)
        checkpoint=sign({**self.base,"contract":a.V2_CHECKPOINT,
          "event_sha256":sha_payload(ledger,a.DOMAINS[a.V2_LEDGER]),
          "issued_epoch":1700000000,"expires_epoch":1700000200
        },a.DOMAINS[a.V2_CHECKPOINT],self.independent)
        self.checkpoint_path=self.folders["checkpoints"]/"example-tenant--staging.json"
        self._write(self.checkpoint_path,checkpoint)
        receipt=sign({
           "contract":a.V2_FINAL_RECEIPT,
           "company":self.base["company"],"environment":"staging",
           "site_uuid":self.base["site_uuid"],"revision":1,
           "state":"ACTIVE","event_sha256":checkpoint["event_sha256"],
           "checkpoint_sha256":sha_payload(checkpoint,a.DOMAINS[a.V2_CHECKPOINT]),
           "issued_epoch":1700000100,
        },a.DOMAINS[a.V2_FINAL_RECEIPT],self.independent)
        self.receipt_path=self.folders["receipts"]/"abc.json"
        self._write(self.receipt_path,receipt)
        # Unit fixtures may run in non-root CI. Runtime still verifies uid,
        # file links/modes and root read authority before trusting any record.
        if os.geteuid()!=0:
            self.patcher=mock.patch.object(a,"protected",
                side_effect=lambda p,isdir=False:p.stat())
            self.patcher.start();self.addCleanup(self.patcher.stop)
        self.uid=mock.patch.object(a.os,"geteuid",return_value=0)
        self.uid.start();self.addCleanup(self.uid.stop)

    def _write(self,path,blob):
        path.write_text(json.dumps(blob));path.chmod(0o600)

    def check(self):return a.history_census(root=self.root,index_path=self.index)

    def test_signed_revision_checkpoint_receipt_chain(self):
        r=self.check()
        self.assertEqual(r["v2_signed_current_pairs_verified"],1)
        self.assertEqual(r["json_file_count"],4)
        self.assertEqual(r["complete_original313_predecessor_for_target"],"NOT_ESTABLISHED")
        self.assertFalse(r["complete_cross_generation_history_verified"])
        self.assertFalse(r["new_original313_v3_predecessor_signed"])
        self.assertFalse(r["write_authorized"])

    def test_tampered_ledger_signature_denied(self):
        obj=json.loads(self.ledger_path.read_text())
        obj["state"]="REVOKED"
        self._write(self.ledger_path,obj)
        with self.assertRaisesRegex(a.ArchiveUntrusted,"ROOT313_HISTORICAL_SIGNATURE_INVALID"):
            self.check()

    def test_missing_checkpoint_denied(self):
        self.checkpoint_path.unlink()
        with self.assertRaisesRegex(a.ArchiveUntrusted,"ROOT313_V2_LEDGER_CHECKPOINT_COVERAGE_GAP"):
            self.check()

    def test_missing_receipt_denied(self):
        self.receipt_path.unlink()
        with self.assertRaisesRegex(a.ArchiveUntrusted,"ROOT313_V2_RECEIPT_COVERAGE_GAP"):
            self.check()

    def test_signed_receipt_mismatch_denied(self):
        row=json.loads(self.receipt_path.read_text())
        row["event_sha256"]="c"*64
        row=sign({k:v for k,v in row.items() if k!="signature"},
                 a.DOMAINS[a.V2_FINAL_RECEIPT],self.independent)
        self._write(self.receipt_path,row)
        with self.assertRaisesRegex(a.ArchiveUntrusted,"ROOT313_V2_RECEIPT_LINK_MISMATCH"):
            self.check()

    def test_hidden_unknown_archived_source_denied(self):
        self._write(self.root/".hidden-alternate-ledger.json",{"contract":"other"})
        with self.assertRaisesRegex(a.ArchiveUntrusted,"ROOT313_UNINDEXED_PRIVATE_HISTORY_SOURCE"):
            self.check()

    def test_expired_historical_signatures_still_verified(self):
        r=self.check()
        self.assertEqual(r["v2_signed_archival_chains"][0]["signature_chain"],
            "V2_LEDGER_313_CHECKPOINT_AND_FINAL_RECEIPT_VERIFIED")
        self.assertFalse(r["v2_signed_archival_chains"][0]["historical_chain_exhaustive"])

    def test_unregistered_firstparty_index_denied(self):
        row=json.loads(self.index.read_text());row["installed_by_first_party"]=False
        self._write(self.index,row)
        with self.assertRaisesRegex(a.ArchiveUntrusted,"ROOT313_INDEX_NOT_ENROLLED"):
            self.check()

    def test_root_signer_collisions_denied(self):
        row=json.loads(self.index.read_text())
        row["independent_verifier_public_key_b64"]=row["host_anchor_312_public_key_b64"]
        self._write(self.index,row)
        with self.assertRaisesRegex(a.ArchiveUntrusted,"ROOT313_SIGNER_INDEPENDENCE_INVALID"):
            self.check()

if __name__=="__main__":unittest.main()
