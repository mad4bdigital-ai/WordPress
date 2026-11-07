import json
from pathlib import Path
import sys
import tempfile
import unittest
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from broker_journal import observe


class OriginalBrokerJournalTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.file = Path(self.temp.name) / "broker-events.jsonl"

    def tearDown(self):
        self.temp.cleanup()

    def test_original_event_projection(self):
        events = [
            {"event": "HEARTBEAT_VERIFIED", "company": "wayfatours", "job_id": ""},
            {"event": "CLAIM_VERIFIED_NOT_EXECUTED", "company": "wayfatours",
             "job_id": "a" * 32, "credential": "DO_NOT_EXPORT"},
            {"event": "NATIVE_SSH_AUTHORITY_READY", "company": "wayfatours",
             "job_id": "a" * 32},
            {"event": "EXECUTION_COMPLETED", "company": "wayfatours",
             "job_id": "a" * 32},
            {"event": "CLAIM_VERIFIED_NOT_EXECUTED", "company": "other",
             "job_id": "b" * 32},
        ]
        self.file.write_text("".join(json.dumps(item) + "\n" for item in events))
        output = observe("wayfatours", self.file)
        self.assertEqual(output["event_count"], 3)
        self.assertEqual(output["stage_313"], "PENDING_INDEPENDENT_NATIVE_READBACK")
        self.assertFalse(output["execution_verified"])
        self.assertFalse(output["write_authorized"])
        self.assertNotIn("DO_NOT_EXPORT", json.dumps(output))
        self.assertEqual(observe("unrelated", self.file)["evidence_state"], "Unverified")

    def test_fail_closed_invalid_input(self):
        self.file.write_text('{"event":"CLAIM_ATTEMPTED","company":"wayfatours","job_id":"bad"}\n')
        self.assertEqual(observe("wayfatours", self.file)["event_count"], 0)
        with self.assertRaises(ValueError):
            observe("../wayfatours", self.file)
        with self.assertRaises(ValueError):
            observe("wayfatours", self.file, limit=0)
        link = Path(self.temp.name) / "journal-link"
        link.symlink_to(self.file)
        with self.assertRaises(ValueError):
            observe("wayfatours", link)


if __name__ == "__main__":
    unittest.main()
