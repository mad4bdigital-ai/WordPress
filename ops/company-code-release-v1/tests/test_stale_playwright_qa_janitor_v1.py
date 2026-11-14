#!/usr/bin/env python3
"""Hermetic safety rules for browser QA janitor."""
import copy,datetime,importlib.util,json,pathlib,time,unittest
p=pathlib.Path(__file__).with_name('stale-playwright-qa-janitor-v1.py')
if not p.exists():p=pathlib.Path(__file__).resolve().parents[1]/'tools'/'stale-playwright-qa-janitor-v1.py'
spec=importlib.util.spec_from_file_location('qa_janitor_v1',p);j=importlib.util.module_from_spec(spec);spec.loader.exec_module(j)
NOW=time.time();age=lambda days:(datetime.datetime.fromtimestamp(NOW-days*86400,datetime.timezone.utc).isoformat())
def fixture(cmd=None):return {'Config':{'Image':'mad4b-firecrawl-playwright:20261107-lifecycle-v3','Cmd':cmd or ['node','/work/qa.js'],'Labels':{'com.docker.compose.project':'mad4b-firecrawl-production'}},'HostConfig':{'RestartPolicy':{'Name':'no'},'AutoRemove':True,'PortBindings':{}},'Created':age(2),'State':{'Running':True}}
class Tests(unittest.TestCase):
 def test_stale_one_shot(self):self.assertEqual(j.eligible(fixture(),NOW,45)[0],True)
 def test_fresh_one_shot_remains(self):
  f=fixture();f['Created']=age(20/(24*60));self.assertFalse(j.eligible(f,NOW,45)[0])
 def test_managed_service_remains(self):
  f=fixture(['node','dist/api.js']);f['HostConfig']['RestartPolicy']['Name']='unless-stopped';f['Config']['Labels']['coolify.managed']='true';self.assertFalse(j.eligible(f,NOW,45)[0])
 def test_restart_policy_remains(self):
  f=fixture();f['HostConfig']['RestartPolicy']['Name']='always';self.assertFalse(j.eligible(f,NOW,45)[0])
 def test_exposed_ports_remain(self):
  f=fixture();f['HostConfig']['PortBindings']={'3000/tcp':[{'HostPort':'3000'}]};self.assertFalse(j.eligible(f,NOW,45)[0])
 def test_foreign_image_remains(self):
  f=fixture();f['Config']['Image']='wordpress:latest';self.assertFalse(j.eligible(f,NOW,45)[0])
 def test_regular_node_app_remains(self):self.assertFalse(j.eligible(fixture(['node','dist/api.js']),NOW,45)[0])
 def test_non_script_remains(self):self.assertFalse(j.eligible(fixture(['bash','/work/maintenance.sh']),NOW,45)[0])
 def test_stopped_remains(self):
  f=fixture();f['State']['Running']=False;self.assertFalse(j.eligible(f,NOW,45)[0])
 def test_managed_label_protects_qa_command(self):
  f=fixture();f['Config']['Labels']['coolify.managed']='true';self.assertFalse(j.eligible(f,NOW,45)[0])
 def test_legacy_qa_paths(self):
  for cmd in [['node','/tools/capture.js'],['node','/work/coverage.js'],['/tmp/qa.js'],['/usr/src/app/test.js']]:
   self.assertTrue(j.eligible(fixture(cmd),NOW,45)[0],cmd)
 def test_inside_managed_service_old_qa_only(self):
  rows=[{'pid':101,'ppid':10,'age_seconds':3600*93,'comm':'node','args':'node /tmp/mad4b-fixed-mobile.js'},
        {'pid':102,'ppid':10,'age_seconds':3600*93,'comm':'node','args':'node /tmp/mad4b-mobile-smoke.js'},
        {'pid':103,'ppid':10,'age_seconds':3600*93,'comm':'node','args':'node dist/api.js'}]
  self.assertEqual([v['pid'] for v in j.service_roots(rows,10,103,90)],[101,102])
 def test_service_exec_fresh_safety(self):
  rows=[{'pid':111,'ppid':10,'age_seconds':45*60,'comm':'node','args':'node /tmp/mad4b-mobile-smoke.js'}]
  self.assertEqual(j.service_roots(rows,10,200,90),[])
 def test_service_exec_child_safety(self):
  rows=[{'pid':111,'ppid':200,'age_seconds':3600*100,'comm':'node','args':'node /tmp/mad4b-mobile-smoke.js'}]
  self.assertEqual(j.service_roots(rows,10,200,90),[])
 def test_service_exec_unrelated_script_safety(self):
  rows=[{'pid':111,'ppid':10,'age_seconds':3600*100,'comm':'node','args':'node /tmp/my-production-server.js'},
        {'pid':112,'ppid':10,'age_seconds':3600*100,'comm':'node','args':'node /tmp/mad4b-commerce-live.js'}]
  self.assertEqual(j.service_roots(rows,10,200,90),[])
 def test_service_exec_not_a_node_script(self):
  rows=[{'pid':111,'ppid':10,'age_seconds':3600*100,'comm':'python3','args':'node /tmp/mad4b-fixed-mobile.js'}]
  self.assertEqual(j.service_roots(rows,10,200,90),[])
 def test_current_image_upgrade_tag(self):
  f=fixture();f['Config']['Image']='mad4b-firecrawl-playwright:v2027.01';self.assertTrue(j.eligible(f,NOW,45)[0])
if __name__=='__main__':unittest.main(verbosity=2)
