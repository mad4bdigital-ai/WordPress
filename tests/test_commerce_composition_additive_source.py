"""Preserve commercial QA modules while adding native advance lifecycle source."""
from pathlib import Path
import hashlib,json,subprocess,unittest
ROOT=Path(__file__).resolve().parents[1]
M=json.loads((ROOT/'docs/release-coverage/commerce-composition-additive-reconciliation-20261111.json').read_text())
HEAD=M['git_parent_head']
BASE='wp-content/plugins/commerce-composition/'
def original(path):
 return subprocess.run(['git','-C',str(ROOT),'show',HEAD+':'+path],
   capture_output=True,timeout=20)
class Compose(unittest.TestCase):
 def test_exact_twelve_pinned_additions_and_old_preimages(self):
  rows=M['rows']
  self.assertEqual(len(rows),12)
  self.assertEqual(sum(r['kind']=='missing_exact_source' for r in rows),8)
  self.assertEqual(sum(r['kind']=='reviewed_existing_source_reconcile' for r in rows),3)
  for item in rows:
   raw=(ROOT/item['path']).read_bytes()
   self.assertEqual(hashlib.sha256(raw).hexdigest(),item['candidate_sha256'])
   ref=original(item['path'])
   if item['kind']=='missing_exact_source':self.assertNotEqual(ref.returncode,0)
   else:
    self.assertEqual(ref.returncode,0)
    self.assertEqual(hashlib.sha256(ref.stdout).hexdigest(),item['base_sha256'])
 def test_commercial_qa_branch_code_preserved(self):
  entry=(ROOT/(BASE+'composition.php')).read_text()
  for name in ('CommercialFinalQuoteLifecycle','CommercialQuotePlan',
               'CommercialExternalChargeGate','NativeCptOrderItemIdentity',
               'PropertyUnitSourceCertification','CommercialQuoteReadiness'):
   self.assertIn("require_once __DIR__ . '/includes/"+name+".php'",entry)
  for name in ('CommercialFinalQuoteLifecycle','CommercialQuotePlan',
               'CommercialExternalChargeGate','CommercialQuoteReadiness'):
   self.assertIn(name+'::class',entry)
  self.assertIn("require_once __DIR__ . '/includes/RequestAdvancePayments.php'",entry)
  self.assertIn("require_once __DIR__ . '/includes/RequestLifecycle.php'",entry)
  self.assertIn("require_once __DIR__ . '/includes/CompanyPolicyLibrary.php'",entry)
  self.assertIn("RequestLifecycle::class, 'bootstrap'], 59",entry)
  self.assertIn("SingleFluentPaymentRouter::class, 'boot'], 60",entry)
 def test_workflow_operator_and_idempotency_paths_exist(self):
  jobs=(ROOT/(BASE+'includes/WorkflowJobs.php')).read_text()
  actions=(ROOT/(BASE+'includes/WorkflowActions.php')).read_text()
  lifecycle=(ROOT/(BASE+'includes/RequestLifecycle.php')).read_text()
  board=(ROOT/(BASE+'includes/FluentBoardsTaskBridge.php')).read_text()
  for marker in ("'sync_request_lifecycle'",'RequestLifecycle::validPayload',
                 'RequestAdvancePayments::submissionContext',
                 'commerce_run_workflows'):
   self.assertIn(marker,jobs)
  self.assertIn("RequestLifecycle::execute($payload)",actions)
  self.assertIn("self::receiptMeta($p['form_id'],$p['submission_id'],$key",lifecycle)
  self.assertIn("render_lifecycle($msg,$p,$o)",lifecycle)
  self.assertIn("MAD4B_SCP_Company_Communication::canSend()",lifecycle)
  self.assertIn("public static function ensureSubmission",board)
  self.assertIn("MAD4B_Early_Payment_Benefits_V1::claimState",board)
 def test_no_unintended_release_or_mutation_claim(self):
  for name in ('release_authorized','company_code_deployed','woocommerce_order_written',
               'lifecycle_job_sent','original313_signer_issued'):
   self.assertIs(M[name],False)
  self.assertIs(M['existing_candidate_only_code_not_removed'],True)
if __name__=='__main__':unittest.main()
