import json,sys,subprocess,unittest
from pathlib import Path
from unittest.mock import patch
sys.path.insert(0,str(Path(__file__).parent))
import resolver

class CoolifyReadOnlyCandidateTests(unittest.TestCase):
 def setUp(self):
  self.b={'provider':'docker_wpcli','provider_locator':'php-guxhp9y6vgh7oouuw8plxrlr',
   'company_id':'wayfatours','site_id':'primary','environment':'production',
   'canonical_origin':'https://wayfatours.com','site_uuid':'a3a7cc36-db7e-4a14-8456-b41857def05c',
   'host_id':'srv2030908','wp_root':'/var/www/html','registration_status':'observed_not_enrolled'}
  self.profile={'site_uuid':self.b['site_uuid'],'canonical_origin':self.b['canonical_origin'],
                'environment':'production'}
 def run_mock(self,label=None,profile=None):
  label=('guxhp9y6vgh7oouuw8plxrlr php running' if label is None else label)
  profile=self.profile if profile is None else profile
  samples=[
   subprocess.CompletedProcess(['docker'],0,label+'\n',''),
   subprocess.CompletedProcess(['wp'],0,self.b['canonical_origin']+'\n',''),
   subprocess.CompletedProcess(['wp'],0,json.dumps(profile),'')]
  with patch.object(resolver.subprocess,'run',side_effect=samples) as m:
   status,origin=resolver.provider_read(self.b,{'srv2030908':{}})
  return status,origin,m.call_count
 def test_exact_coolify_candidate_observed_without_authorizing(self):
  self.assertEqual(self.run_mock(),('OBSERVED','https://wayfatours.com',3))
 def test_wrong_project_label_fails_closed(self):
  self.assertEqual(self.run_mock(label='wrong php running'),('UNTRUSTED_COOLIFY_CONTAINER_SCOPE',None,1))
 def test_wrong_site_profile_fails_closed(self):
  data={**self.profile,'site_uuid':'b0725ffa-87e1-47e3-b0c0-c4aa4eb8882b'}
  self.assertEqual(self.run_mock(profile=data),('SITE_PROFILE_SCOPE_MISMATCH',None,3))
 def test_unenrolled_container_cannot_be_used(self):
  self.b['registration_status']='enrolled'
  with patch.object(resolver.subprocess,'run') as run:
   self.assertEqual(resolver.provider_read(self.b,{}),('UNENROLLED_LOCAL_CONTAINER',None))
   run.assert_not_called()
 def test_old_unscoped_alias_cannot_be_used(self):
  self.b['provider_locator']='wayfatours-production-php'
  with patch.object(resolver.subprocess,'run') as run:
   self.assertEqual(resolver.provider_read(self.b,{}),('UNENROLLED_LOCAL_CONTAINER',None))
   run.assert_not_called()
if __name__=='__main__':unittest.main()
