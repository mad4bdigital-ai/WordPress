from pathlib import Path
import unittest
ROOT=Path(__file__).resolve().parents[3]
CONTENT=(ROOT/'wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-content-jobs.php').read_text()
DRAFT=(ROOT/'wp-content/mu-plugins/016-mad4b-company-content-job-draft-v1.php').read_text()
class DraftContentSecurity(unittest.TestCase):
    def test_company_guard_is_additive_to_existing_original_authorization(self):
        self.assertIn('MAD4B_SCP_Policy::can_mutate()',CONTENT)
        self.assertIn("MAD4B_Native_Origin_Content_Jobs_V1::admitted_for",CONTENT)
        self.assertIn("MAD4B_Company_Content_Job_Draft_V1::admitted($input,$scope)",CONTENT)
        self.assertIn("MAD4B_SCP_Operational_Integrity::capture()",CONTENT)
        self.assertIn("MAD4B_SCP_Operational_Integrity::assert_unchanged",CONTENT)
        self.assertIn("START TRANSACTION",CONTENT)
        self.assertIn("ROLLBACK",CONTENT)
        self.assertIn("COMMIT",CONTENT)
    def test_submission_is_browser_post_strict_review_and_same_scope(self):
        for expected in ["check_admin_referer(self::ACTION)",
                         "wp_verify_nonce($nonce,self::ACTION)",
                         "REST_REQUEST","WP_CLI",
                         "MAD4B_SCP_Policy::mutation_gate_status()",
                         "'automation_level'=>'strict'",
                         "MAD4B_SCP_Operational_Integrity::capture()",
                         "MAD4B_SCP_Content_Jobs::create_job($input)",
                         "MAD4B_SCP_Site_Profile::status()",
                         "MAD4B_Admin_Surface_Registry_V1::allowed(self::SLUG,'draft')",
                         "wp_safe_redirect("]:
            self.assertIn(expected,DRAFT)
        self.assertNotIn("MAD4B_SCP_Policy::can_mutate = ",DRAFT)
if __name__=='__main__':unittest.main()
