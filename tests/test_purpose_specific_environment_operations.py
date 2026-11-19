#!/usr/bin/env python3
"""Source-only purpose-specific operation review, never grants or deployments."""
import json
from pathlib import Path
import re
import unittest

ROOT=Path(__file__).resolve().parents[1]
REVIEW=ROOT/"governance/production-readiness/purpose-specific-environment-operations.json"
SOURCE=ROOT/"wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-staging-write-grant-reconciliation.php"

class PurposeSpecificRuntimeReview(unittest.TestCase):
    def test_all_original_staging_exceptions_are_source_mapped_and_fail_closed(self):
        data=json.loads(REVIEW.read_text())
        self.assertEqual(data["contract"],"mad4b.production-purpose-specific-operation-review.v1")
        self.assertIs(data["authorizing"],False)
        self.assertIs(data["production_authorized"],False)
        self.assertIs(data["creates_agent_grants"],False)
        items=data["operations"]
        self.assertEqual(len(items),16)
        source=SOURCE.read_text()
        block=source.split("$staging_reviewed = array(",1)[1].split("\n\t\t\t);",1)[0]
        original=dict(re.findall(r"'([a-z][\w/-]+)'\s*=>\s*'([a-z\w_-]+)'",block))
        reviewed={}
        for x in items:
            key=x["ability"]
            self.assertNotIn(key,reviewed)
            reviewed[key]=x["provider"]
            self.assertTrue(x["staging_source_eligibility"])
            self.assertFalse(x["production_source_eligibility"])
            self.assertFalse(x["route_equivalence_proven"])
            self.assertEqual(x["production_execution_state"],"independent_contract_required")
            path=Path(x["original_owner_source"])
            self.assertTrue((ROOT/path).is_file(),str(path))
            self.assertTrue(str(path).startswith("wp-content/plugins/mad4b-site-control-plane/includes/"))
        self.assertEqual(reviewed,original)
        self.assertEqual(len(reviewed),16)

    def test_no_fabricated_production_grant_from_alternate_workflow(self):
        data=json.loads(REVIEW.read_text())
        for operation in data["operations"]:
            possible=operation.get("production_context_route")
            if possible is not None:
                self.assertTrue(possible.startswith("mad4b/"))
                self.assertFalse(operation["route_equivalence_proven"])
            self.assertIn("independently certified",operation["reviewed_reason"])

if __name__=="__main__":
    unittest.main()
