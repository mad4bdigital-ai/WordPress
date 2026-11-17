from pathlib import Path
import json
import unittest
ROOT=Path(__file__).resolve().parents[1]
DATA=ROOT/'ops/wayfa-readonly-skill-operations-v1'
class WayfaSkillsHotfixReview(unittest.TestCase):
 def test_manifest_read_only(self):
  m=json.loads((DATA/'manifest.json').read_text())
  self.assertEqual(m['contract'],'mad4b.wayfa-read-only-operations-v1')
  self.assertEqual(m['site'],'wayfatours.com')
  self.assertEqual(m['mode'],'manual_code_only_source_candidate')
  self.assertEqual(m['environment'],'production')
  self.assertEqual(len(m['files']),5)
  for k in ('role_changes','app_id_changes','grants_issued','skill_authoring_enabled','declares_original_release'):
   self.assertIs(m[k],False,k)
  for row in m['files']:
   self.assertRegex(row['preimage_sha256'],r'^[a-f0-9]{64}$')
   self.assertRegex(row['patched_sha256'],r'^[a-f0-9]{64}$')
   self.assertNotEqual(row['preimage_sha256'],row['patched_sha256'])
   self.assertTrue((DATA/row['patch']).is_file())
 def test_read_only_source_intent(self):
  contents='\n'.join(p.read_text() for p in DATA.glob('*.patch'))
  for target in ('mad4b/skill-match-task','mad4b/skill-composition-plan',
                 'class-skill-task-composition.php','class-external-skill-sources.php',
                 'mad4b_manage_company_operations','catalog_read_ready',
                 'external_app_projection_ready','authoring_ready'):
   self.assertIn(target,contents)
  for unsafe in ('add_cap(', 'update_option(', 'plugin_asdk_app_'):
   self.assertNotIn(unsafe,contents)
if __name__=='__main__':unittest.main()
