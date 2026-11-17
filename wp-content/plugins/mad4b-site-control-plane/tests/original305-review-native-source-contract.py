"""Review controller registration and host safety boundaries; portable source checks."""
from pathlib import Path
import re
import unittest
ROOT=Path(__file__).resolve().parents[1]
MAIN=ROOT/'mad4b-site-control-plane.php'
CTRL=ROOT/'includes/class-mad4b-scp-original305-incident-review.php'
class Original305NativeReview(unittest.TestCase):
 def test_loaded_from_normal_plugin_without_replacing_ticket_engine(self):
  boot=MAIN.read_text()
  match="require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-original305-incident-review.php';"
  self.assertEqual(boot.count(match),1)
  self.assertIn("MAD4B_SCP_Original305_Incident_Review::boot();",boot)
  self.assertLess(boot.index('class-mad4b-scp-approval-tickets.php'),boot.index(match))
  original="require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-native-host-source-release-review.php';"
  self.assertEqual(boot.count(original),1)
  self.assertIn('MAD4B_SCP_Native_Host_Source_Release_Review::boot();',boot)
  self.assertLess(boot.index(original),boot.index(match))
 def test_registers_ability_on_original_wordpress_hook(self):
  s=CTRL.read_text()
  self.assertIn("add_action('wp_abilities_api_init', array(__CLASS__, 'register_ability'), 45)",s)
  self.assertIn('wp_register_ability(self::ABILITY',s)
  self.assertIn("'category'=>'mad4b-write'",s)
  self.assertIn("'execute_callback'=>array(__CLASS__, 'deny_direct_execution')",s)
  self.assertIn("'surface'=>'internal'",s)
  self.assertIn('wp_has_ability(self::ABILITY)',s)
 def test_web_browser_session_and_native_original_ticket_engine(self):
  s=CTRL.read_text()
  for value in ("wp_validate_auth_cookie($cookie, 'logged_in')",
                'wp_get_session_token()',"wp_verify_nonce($nonce, 'wp_rest')",
                'MAD4B_SCP_Policy::can_plan_mutations()',
                'MAD4B_SCP_Policy::can_approve_mutations()',
                'MAD4B_SCP_Approval_Tickets::create_pending(',
                'MAD4B_SCP_Approval_Tickets::bind_ticket_to_current_candidate(',
                'MAD4B_SCP_Approval_Tickets::decide_pending(',
                'MAD4B_SCP_Approval_Tickets::canonical_payload_hash('):
   self.assertIn(value,s)
 def test_no_host_release_no_auto_signer_no_hold_changes(self):
  s=CTRL.read_text()
  for bad in ('HOLD.unlink(', 'wp_set_current_user(', 'original_signer(',
              'sodium_crypto_sign(', 'systemctl', 'wp_remote_post(',
              'update_option(', 'delete_option(', 'exec(', 'shell_exec('):
   self.assertNotIn(bad,s)
  for value in ("'incident_closed'=>false", "'host_release_authorized'=>false",
                "'host_source_request_independently_verified_here'=>false", 'deny_direct_execution'):
   self.assertIn(value,s)
 def test_admin_ui_has_no_manual_json_agent_or_ticket_controls(self):
  s=CTRL.read_text()
  self.assertIn('Original305 — Automatic Readiness',s)
  self.assertIn("'mad4b_original305_runtime_observation_v1'",s)
  self.assertIn("window.setInterval(refresh,60000)",s)
  self.assertIn('AUTOMATED_OBSERVATION_EXPIRED',s)
  self.assertIn('AUTOMATED_OBSERVATION_EVIDENCE_INCOMPLETE',s)
  self.assertIn("'holdproof'=>'Incident HOLD file proof'",s)
  self.assertIn("'wpread'=>'WordPress independent readback'",s)
  for old in ('mad4b-o305-request','mad4b-o305-agent','mad4b-o305-approve',
              'Create pending review ticket</button>', 'Exact V2 request JSON</label>'):
   self.assertNotIn(old,s)

 def test_admin_ajax_readonly_transport_is_authenticated_and_nonce_checked(self):
  s=CTRL.read_text()
  for token in (
    "add_action('wp_ajax_mad4b_original305_readiness_status'",
    "check_ajax_referer('mad4b_original305_readiness_status','_ajax_nonce',false)",
    "wp_send_json_success(self::status(),200)",
    "wp_send_json_error(array('code'=>'mad4b_original305_admin_required'",
    "wp_send_json_error(array('code'=>'mad4b_original305_nonce_invalid'",
    "admin_url('admin-ajax.php')", "credentials:'same-origin'",
    'envelope.success!==true', "'Accept':'application/json'",
    'nocache_headers()'):
   self.assertIn(token,s)
  self.assertNotIn("add_action('wp_ajax_nopriv_mad4b_original305_readiness_status'",s)
  self.assertNotIn("fetch(endpoint,{method:'GET',credentials:'same-origin',headers:{'X-WP-Nonce'",s)

 def test_native_wordpress_site_health_and_dashboard_notice(self):
  s=CTRL.read_text()
  for required in ("add_filter('site_status_tests'", "add_action('admin_notices'",
      'function site_health_result()', 'function site_health_tests(',
      'function operational_health()', "'ORIGINAL305_HISTORICAL_HOLD'",
      "'ORIGINAL305_OBSERVATION_STALE'", "'ORIGINAL305_REGISTRY_MISMATCH'",
      "'ORIGINAL305_WORDPRESS_READBACK_UNAVAILABLE'",
      "'ORIGINAL305_SIGNED_READINESS_NOT_ACTIVE'", "'ORIGINAL305_SIGNED_READINESS_HEALTHY'",
      'legacy_hold_present', 'wordpress_original_signed_readiness_admitted',
      "if ($pagenow!=='index.php') return;"):
   self.assertIn(required,s)
  self.assertNotIn("wp_mail(",s)
  self.assertNotIn("wp_set_current_user(",s)

 def test_strict_request_contract_and_hash(self):
  s=CTRL.read_text()
  for x in ('class MAD4B_SCP_Original305_Incident_Review',
            'mad4b.original305-incident-closure-review-request.v2',
            'legacy_hold_sha256','service_unit_sha256','supervisor_sha256',
            'hash_equals($provided',"hash('sha256', $canon)",
            "array_diff(array_keys($request), $keys)"):
   self.assertIn(x,s)
if __name__=='__main__':unittest.main()
