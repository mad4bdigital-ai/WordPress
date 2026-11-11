"""Read-only operation-specific 306 preclaim never accepts old grant or replay."""
import ast,hashlib,importlib.util,unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]/'integrations/reviewed-source'
ORIGINAL=ROOT/'claimed-job-worker-license-preclaim-v1.py'
CURRENT=ROOT/'claimed-job-worker-receipt-preclaim-v1.py'

def source_fn(path,func):
    mod=ast.parse(path.read_text())
    return next(n for n in mod.body if isinstance(n,ast.FunctionDef) and n.name==func)

class PreclaimWorkerTests(unittest.TestCase):
    def test_legacy_code_release_gate_identical_ast(self):
        previous=source_fn(ORIGINAL,'company_code_release_grant_ready_legacy')
        candidate=source_fn(CURRENT,'company_code_release_grant_ready_legacy')
        self.assertEqual(ast.dump(previous,include_attributes=False),
                         ast.dump(candidate,include_attributes=False))
    def test_no_other_function_changed_besides_admission(self):
        prev=ast.parse(ORIGINAL.read_text());new=ast.parse(CURRENT.read_text())
        def funcs(tree):return {x.name:x for x in tree.body if isinstance(x,ast.FunctionDef)}
        a,b=funcs(prev),funcs(new)
        self.assertEqual(set(a),set(b))
        changed=[k for k in a if ast.dump(a[k],include_attributes=False)!=ast.dump(b[k],include_attributes=False)]
        self.assertEqual(changed,['dynamic_release_grant_ready'])
    def test_new_operation_is_bound_to_reviewed_manifest_and_broker(self):
        text=ast.get_source_segment(CURRENT.read_text(),source_fn(CURRENT,'dynamic_release_grant_ready'))
        for exact in ["operation=='company.code.release.receipt.reconcile'",
                      "deps.get(suffix)","sha(protected_file(source,index.root,maximum=2_000_000))",
                      "receipt_admission.root_preclaim(entry['plan'],job,command_sha)",
                      "checked.get('new_grant_signed_by_existing_broker') is True",
                      "checked.get('original_313_certified') is False",
                      "checked.get('schema_ddl_allowed') is False"]:
            self.assertIn(exact,text)
        self.assertNotIn('grant[\'verified\']=True',text)
    def test_no_issuer_or_production_schema_execution(self):
        s=CURRENT.read_text()
        f=ast.get_source_segment(s,source_fn(CURRENT,'dynamic_release_grant_ready'))
        self.assertNotIn('issue_grant(',f)
        self.assertNotIn('CREATE TABLE',f)
        self.assertNotIn('update_option(',f)
    def test_source_byte_identity_is_sha256_addressable(self):
        raw=CURRENT.read_bytes()
        self.assertEqual(hashlib.sha256(raw).hexdigest(),hashlib.sha256(CURRENT.read_bytes()).hexdigest())

if __name__=='__main__':unittest.main()
