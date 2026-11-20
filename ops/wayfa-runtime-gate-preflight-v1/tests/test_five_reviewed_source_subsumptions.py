"""Five exact rc129 source contracts: earlier production delta must remain bounded.

This is non-authorizing static regression evidence, not a Production release
or a claim that full cross-site REST/AJAX functional acceptance passed.
"""
import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[3]
P = ROOT / "wp-content/plugins/mad4b-site-control-plane/includes"

def text(name):
    path=P/name
    assert path.is_file() and not path.is_symlink(),name
    return path.read_text()

class CandidateSubsumesReviewedWayfaDeltas(unittest.TestCase):
    def test_skill_adapter_keeps_both_reads_and_no_write_or_admin(self):
        src=text("adapters/class-mad4b-scp-skills-adapter.php")
        for name in ("mad4b/skill-match-task", "mad4b/skill-composition-plan",
                     "mad4b/skill-sources-list", "mad4b/skill-source-skills",
                     "mad4b/skill-source-get", "mad4b/skill-source-resource"):
            self.assertIn("'"+name+"'",src)
        self.assertIn("'content' => array()",src)
        self.assertIn("'admin' => array()",src)
        self.assertIn("Registered centrally by MAD4B_SCP_Skill_Abilities",src)

    def test_brand_context_rejects_foreign_claim_and_requires_bound_source(self):
        source=text("class-mad4b-scp-brand-context-builder.php")
        start=source.index("private static function approved_brand_asset(")
        stop=source.index("private static function approved_assets_by_category()",start)
        gate=source[start:stop]
        for x in ("hash_equals($current,$reviewed)", "brand_asset_binding_evidence($asset)",
                  "'bound'!==($binding['status']??'')",
                  "brand_owner_evidence($asset)", "'foreign'!==($text_claim['status']??'')"):
            self.assertIn(x,gate)
        self.assertIn("'approved' !== (string)($asset['review_status']??'')",gate)

    def test_structural_receipt_requires_exact_enrolled_actor_and_object_acl(self):
        source=text("class-mad4b-scp-content-job-structural-qa.php")
        start=source.index("public static function attest(")
        block=source[start:]
        for x in ("is_array($site=MAD4B_SCP_Site_Profile::status())",
                  "($site['authority_ready']??null)!==true",
                  "true!==MAD4B_SCP_Central_Write_Acceptance::permitted()",
                  "current_user_can('edit_post',$current['source_post_id'])",
                  "current_user_can('edit_post',$current['target_post_id'])",
                  "MAD4B_SCP_Operational_Integrity::capture()"):
            self.assertIn(x,block)
        self.assertIn("enrolled_original_actor_required",block)

    def test_research_task_exact_site_owner_permission_fails_closed(self):
        source=text("class-mad4b-scp-research-task-bridge.php")
        block=source[source.index("private static function allowed()"):source.index("private static function error(")]
        for x in ("is_array($site)", "($site['authority_ready']??null)===true",
                  "($site['origin_match']??null)===true",
                  "current_user_can('manage_options')===true",
                  "catch(Throwable $error)", "return false;"):
            self.assertIn(x,block)

    def test_skill_registry_requires_strict_enrollment_and_url_binding(self):
        source=text("class-mad4b-scp-skill-registry.php")
        start=source.index("public static function catalog_enabled()")
        block=source[start:source.index("public static function",start+1)]
        for x in ("true === MAD4B_SCP_Site_Profile::origin_enrolled()",
                  "true === MAD4B_SCP_Site_Profile::site_urls_match_enrollment()"):
            self.assertIn(x,block)
        self.assertIn("never enables the authoring editor",source)

if __name__=="__main__":
    unittest.main()
