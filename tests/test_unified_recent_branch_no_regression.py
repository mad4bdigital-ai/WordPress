"""Keep newer consolidated behavior when reviewing divergent sibling branches."""
from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parents[1]

def code(relative):
    return (ROOT/relative).read_text()

class DivergentSiblingNonRegression(unittest.TestCase):
    def test_registry_retains_newer_signed_hooks_and_host_resource(self):
        s=code('ops/dynamic-operation-registry-v1/registry.py')
        for marker in ('def load_provider_hook(', 'operation_association_authority',
                       'HOST312_ROOT_PIN_INVALID', 'HOST_PROVIDER_RESOURCE_CONTRACT_INVALID',
                       'DYNAMIC_PROVIDER_HOOK_CHANGED_DURING_LOAD',
                       'host.source.ref.v1'):
            self.assertIn(marker,s)
        self.assertIn('HOST312_SIGNER_MUST_BE_INDEPENDENT',s)

    def test_media_license_identifier_not_downgraded(self):
        s=code('wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-content-experience-media-rights.php')
        self.assertIn('cc-by-4.0',s)
        self.assertIn("strtolower( trim( $item['license'] ) )",s)
        self.assertIn("0 !== strpos( (string) get_post_mime_type( $id ), 'image/' )",s)
        self.assertNotIn("str_starts_with( (string) get_post_mime_type",s)

    def test_media_missing_local_rights_does_not_invent_license(self):
        s=code('wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-content-experience-media.php')
        self.assertIn('$missing_local_image_usage',s)
        self.assertIn("wp_attachment_is_image( $id )",s)
        self.assertIn("get_attached_file( $id )",s)

    def test_critical_css_respects_native_mobile_breakpoint(self):
        s=code('ops/mobile-critical-css-v1/verify.js')
        self.assertIn('HERO900 && (!ADAPTIVE_MAX || width<=ADAPTIVE_MAX)',s)

    def test_cache_rate_records_are_not_renderable_pages(self):
        s=code('wp-content/plugins/mad4b-cache-lifecycle/includes/runtime.php')
        self.assertIn("if($p->post_type==='tour-rates')",s)
        self.assertIn("related_tour_id",s)
        self.assertIn("if(get_post_type((int)$id)==='tour-rates')return;",s)
        self.assertNotIn('str_starts_with(',s)

    def test_original305_source_retains_review_without_self_approval(self):
        reviewer=code('ops/native-source-publisher-v1/original305_incident_signed_review_v2.py')
        supervisor=code('ops/native-source-publisher-v1/original305_readiness_supervisor_v7.py')
        self.assertIn('verify_approval(',reviewer)
        self.assertIn('legacy_hold_sha256',reviewer)
        self.assertIn('REQUIRES_FIRSTPARTY_SIGNED_MASTER_REVIEW',reviewer)
        self.assertIn('AMBIGUOUS_SIGNED_WRITE_HOLD',supervisor)
        self.assertIn('ISSUER_SIGNED_RESULT_UNCONFIRMED',supervisor)

if __name__=='__main__':unittest.main()
