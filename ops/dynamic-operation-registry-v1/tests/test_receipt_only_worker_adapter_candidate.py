"""Receipt-only Worker Adapter source boundary, without creating any Job."""
import ast,unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
OLD=ROOT/'worker_adapter.py'
NEW=ROOT/'integrations/reviewed-source/worker_adapter_receipt_only_v1.py'

def fns(path):
    return {n.name:n for n in ast.parse(path.read_text()).body if isinstance(n,ast.FunctionDef)}

class ReceiptOnlyAdapterCandidateTests(unittest.TestCase):
    def test_existing_other_operations_unchanged(self):
        old,new=fns(OLD),fns(NEW)
        self.assertEqual(set(new)-set(old),{'receipt_only_evidence'})
        for name in ('indexed_plans','digest_table','select'):
            self.assertEqual(ast.dump(old[name],include_attributes=False),
                             ast.dump(new[name],include_attributes=False))
    def test_exact_receipt_handler_shares_no_code_apply(self):
        source=NEW.read_text()
        helper=ast.get_source_segment(source,fns(NEW)['receipt_only_evidence'])
        for key in ('signed_recovery_admission.root_preclaim',
                    'independent_readback.verify',
                    "'RECEIPT_ONLY_POSTIMAGE_VERIFIED_PENDING_ORIGINAL_313'",
                    "'original_313_certified':False",
                    "'job_replayed':False",
                    "'production_mutation':False"):
            self.assertIn(key,helper)
        for denied in ('subprocess.run(', 'CREATE TABLE','dbDelta(',
                       'update_option(', 'apply_release(', 'signed_finish('):
            self.assertNotIn(denied,helper)
    def test_fresh_original_312_before_receipt_or_reservation(self):
        source=NEW.read_text()
        executor=ast.get_source_segment(source,fns(NEW)['execute_claimed'])
        a=executor.index('admission.fresh_original_312(')
        b=executor.index('admission.verify_claimed_312(')
        c=executor.index('receipt_only_evidence(index,candidate,m,receipt_plan)')
        d=executor.index('fd=os.open(reservation,')
        self.assertLess(a,b);self.assertLess(b,c);self.assertLess(c,d)
        self.assertEqual(executor.count('receipt_only_evidence(index,candidate,m,receipt_plan)'),2)
    def test_only_receipt_branch_is_readonly(self):
        source=NEW.read_text()
        executor=ast.get_source_segment(source,fns(NEW)['execute_claimed'])
        self.assertIn("if operation=='company.code.release.receipt.reconcile':",executor)
        self.assertIn("'receipt_only_readback_verified':True",executor)
        self.assertIn("'code_reapplied':False",executor)
        self.assertIn("'central_313_receipt_created':False",executor)
        self.assertIn('p=subprocess.run(',executor)
        self.assertLess(executor.index("'code_reapplied':False"),executor.index('p=subprocess.run('))
    def test_original_code_release_handler_keeps_exact_host_applied(self):
        src=NEW.read_text()
        self.assertIn('HOST_APPLIED_PENDING_INDEPENDENT_ORIGINAL_313',src)
        self.assertIn('DYNAMIC_HOST_READBACK_REQUIRED',src)
        self.assertNotIn('original_313_certified\':True',src)

if __name__=='__main__':unittest.main()
