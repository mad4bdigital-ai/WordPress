"""ETG read-only site enrollment and reconciliation via the existing Resolver."""
import tempfile,unittest,sys
from pathlib import Path
from unittest.mock import patch
import resolver as r

TARGET="egypt-tour-gates:staging"
SCOPE={"site_uuid":"d745d81f-6fc4-5c6a-99dd-d953c92137bf",
       "environment":"staging","canonical_origin":"https://staging.egypttourgates.com",
       "release_observer":"mad4b.etg-staging-central-release-observer.v1",
       "operations":[],"release_apply_authorized":False}
INV={TARGET:{"site_uuid":SCOPE["site_uuid"],"environment":"staging",
             "canonical_origin":SCOPE["canonical_origin"]}}
POLICY={"contract":r.CONTRACT,"enabled":True,"default_production_mutation":False,
        "scopes":{TARGET:SCOPE}}

class CentralEtgIntegrationTests(unittest.TestCase):
    def test_live_readonly_enrollment_survives_inventory_refresh(self):
        self.assertIn(TARGET,r.inventory())
        enrolled=r.inventory()[TARGET]
        self.assertFalse(enrolled["managed_execution_authorized"])
        self.assertFalse(enrolled["production_mutation"])
    def test_manual_event_to_resolver_receipt(self):
        with tempfile.TemporaryDirectory() as temp:
            db=r.db_open(Path(temp)/"test.sqlite3")
            observer={d:r.result("not_applicable","etg_observer","read_only")
                      for d in r.DOMAINS}
            observer["transport"]=r.result("verified","etg_observer","pinned_ssh_read")
            observer["identity"]=r.result("verified","etg_observer","site_exact")
            observer["package"]=r.result("blocked_dependency","etg_observer","runtime_drift")
            observer["operation_admission"]=r.result("blocked_authority","etg_observer","no_release_grant")
            try:
                with patch.object(r,"inventory",return_value=INV):
                    event=r.enqueue(db,TARGET,"manual","unit_tests","etg-unit-manual-event-0001")
                    with patch.object(r,"inspect_registered_release_readback",return_value=observer):
                        result=r.reconcile(db,event["event_id"],POLICY)
                self.assertFalse(result["external_write_performed"])
                self.assertEqual(result["domains"]["package"],"blocked_dependency")
                self.assertEqual(result["domains"]["operation_admission"],"blocked_authority")
                # Dynamic site_binding writes its own independent observation receipt.
                # It is separate from the original per-domain observation set.
                domain_receipts=db.execute(
                    "select domain,count(*) from receipts where target=? group by domain",
                    (TARGET,)).fetchall()
                recorded={row["domain"]:row["count(*)"] for row in domain_receipts}
                self.assertEqual(set(recorded),set(r.DOMAINS)|{"site_binding"})
                self.assertTrue(all(value==1 for value in recorded.values()))
                self.assertEqual(db.execute(
                    "select count(*) from receipts where target=?",
                    (TARGET,)).fetchone()[0],len(r.DOMAINS)+1)
                self.assertEqual(db.execute(
                    "select state from reviews where target=? and domain=? and reason=?",
                    (TARGET,"operation_admission","no_release_grant")).fetchone()[0],"open")
                self.assertEqual(r.reconcile(db,event["event_id"],POLICY)["idempotent"],True)
            finally:db.close()
    def test_readonly_scope_never_accepts_new_mutation_capability(self):
        import etg_staging_release_observer as observer
        bad={**SCOPE,"operations":["plugin_files.write"]}
        r=observer.inspect(bad,read=lambda _: self.fail("SSH must not run"))
        self.assertEqual(r["identity"]["state"],"review_required")

if __name__=="__main__":unittest.main()
