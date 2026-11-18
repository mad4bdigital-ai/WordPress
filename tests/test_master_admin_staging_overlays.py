"""Bounded staging overlays: no production authority, roles or grants."""
import json
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
class StageOverlayContracts(unittest.TestCase):
 def test_skill_task_registration(self):
  d=ROOT/'ops/skills-task-registration-staging-v1'
  m=json.loads((d/'manifest.json').read_text())
  self.assertEqual(m['environment'],'staging')
  self.assertTrue(m['read_only'])
  self.assertFalse(m['grants_created'])
  self.assertFalse(m['production_authorized'])
  self.assertEqual(len(m['entry_files']),3)
  a={x['path']:x for x in m['entry_files']}
  p=(d/a['includes/class-mad4b-scp-skill-abilities.php']['patch']).read_text()
  for x in ('mad4b/skill-match-task','mad4b/skill-composition-plan',
            "'SCP_Skill_Task_Composition', 'match_task'",
            "'SCP_Skill_Task_Composition', 'composition_plan'",
            "'additionalProperties' => false"):
   self.assertIn(x,p)
  adapter=(d/a['includes/adapters/class-mad4b-scp-skills-adapter.php']['patch']).read_text()
  self.assertIn('mad4b/skill-match-task',adapter)
  self.assertIn('mad4b/skill-composition-plan',adapter)
  entry=(d/a['mad4b-site-control-plane.php']['patch']).read_text()
  self.assertIn('class-external-skill-sources.php',entry)
  self.assertIn('class-skill-task-composition.php',entry)
  for x in m['entry_files']:
   self.assertRegex(x['preimage_sha256'],r'^[a-f0-9]{64}$')
   self.assertRegex(x['patched_sha256'],r'^[a-f0-9]{64}$')
 def test_master_admin_readonly(self):
  d=ROOT/'ops/master-admin-staging-readonly-v1'
  m=json.loads((d/'manifest.json').read_text())
  self.assertTrue(m['code_only'])
  self.assertFalse(m['company_role_mutation'])
  self.assertFalse(m['production_authorized'])
  self.assertEqual(len(m['files']),2)
  a={x['path']:x for x in m['files']}
  ui=(d/a['includes/class-mad4b-scp-skills-admin-ui.php']['patch']).read_text()
  cert=(d/a['includes/class-mad4b-scp-skill-runtime-certification.php']['patch']).read_text()
  for x in ('mad4b_manage_company_operations','read_only','authority_effect','manage_options'):
   self.assertIn(x,ui)
  for x in ('catalog_read_ready','external_app_projection_ready','authoring_ready',
            "'execution_authority_granted' => false"):
   self.assertIn(x,cert)
  for x in m['files']:
   self.assertRegex(x['preimage_sha256'],r'^[a-f0-9]{64}$')
   self.assertRegex(x['patched_sha256'],r'^[a-f0-9]{64}$')
if __name__=='__main__':unittest.main()
