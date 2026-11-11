"""No doubled Fluent Forms callback registration in either plugin."""
from pathlib import Path
import unittest
ROOT=Path(__file__).resolve().parents[1]
SOURCE='includes/class-mad4b-scp-company-email-routing.php'
class OwnedMailFilter(unittest.TestCase):
 def test_each_source_registers_exactly_one_hook(self):
  for plugin in ('mad4b-site-control-plane','mad4b-company-portfolio-manager'):
   p=ROOT/'wp-content/plugins'/plugin/SOURCE
   with self.subTest(plugin=plugin):
    s=p.read_text()
    self.assertEqual(s.count("add_filter('fluentform/email_to'"),1)
    self.assertIn("add_filter('fluentform/email_to', [__CLASS__, 'fluent_form_recipient'], 100, 4)",s)
    self.assertIn("register_rest_route",s)
    self.assertNotIn("wp_mail(",s)
 def test_plugin_owner_controlled_by_site_runtime(self):
  core=(ROOT/'wp-content/plugins/mad4b-site-control-plane/mad4b-site-control-plane.php').read_text()
  manager=(ROOT/'wp-content/plugins/mad4b-company-portfolio-manager/mad4b-company-portfolio-manager.php').read_text()
  self.assertIn("'production' === wp_get_environment_type()",core)
  self.assertIn("in_array(wp_get_environment_type(), ['staging','production'], true)",manager)
  self.assertIn("!class_exists('MAD4B_SCP_Company_Email_Routing', false)",manager)
if __name__=='__main__':unittest.main()
