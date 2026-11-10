import sys
import unittest
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
from dynamic_runtime import resolve

UUID="a3a7cc36-db7e-4a14-8456-b41857def05c"
ORIGIN="https://wayfatours.com"
RESOURCE="a"*24


def container(name="php-replacement",resource=RESOURCE,env="production"):
    return {"Id":"a"*64,"Name":"/"+name,"State":{"Running":True},
        "Config":{"Labels":{
          "coolify.managed":"true","coolify.type":"service",
          "com.docker.compose.service":"php",
          "com.docker.compose.project":resource,
          "coolify.serviceUuid":resource,
          "coolify.environmentName":env}}}


def profile(name):
    return {"contract":"mad4b.site-profile.v2","site_uuid":UUID,
       "environment":"production","canonical_origin":ORIGIN,"_siteurl":ORIGIN}


class DynamicRuntimeTests(unittest.TestCase):
    def resolve(self,rows,read=profile,**kwargs):
        return resolve("wayfatours","production",UUID,ORIGIN,
                       discover=lambda:rows,read_profile=read,**kwargs)

    def test_replacement_container_is_discovered(self):
        out=self.resolve([container("php-new-version")])
        self.assertEqual(out["target_container"],"php-new-version")
        self.assertEqual(out["coolify_service_uuid"],RESOURCE)
        self.assertTrue(out["discovered_dynamically"])
        self.assertFalse(out["native_write_authorized"])

    def test_duplicate_live_targets_fail_closed(self):
        out=self.resolve([container("php-a"),container("php-b")])
        self.assertEqual(out["reason"],"AMBIGUOUS_MATCHING_LIVE_RUNTIMES")

    def test_profile_mismatch_fails_closed(self):
        out=self.resolve([container()],read=lambda _:dict(profile(""),site_uuid="foreign"))
        self.assertEqual(out["reason"],"NO_MATCHING_LIVE_COOLIFY_WORDPRESS")

    def test_coolify_resource_pinning(self):
        out=self.resolve([container()],service_uuid="b"*24)
        self.assertEqual(out["state"],"BLOCKED")
        out=self.resolve([container()],service_uuid=RESOURCE)
        self.assertEqual(out["state"],"TARGET_OBSERVED")

    def test_non_php_or_wrong_environment_not_selected(self):
        wrong=container();wrong["Config"]["Labels"]["com.docker.compose.service"]="nginx"
        self.assertEqual(self.resolve([wrong])["state"],"BLOCKED")
        self.assertEqual(self.resolve([container(env="staging")])["state"],"BLOCKED")

    def test_unmanaged_container_not_selected(self):
        wrong=container();wrong["Config"]["Labels"]["coolify.managed"]="false"
        self.assertEqual(self.resolve([wrong])["state"],"BLOCKED")

    def test_empty_and_overflow_denied(self):
        self.assertEqual(self.resolve([])["state"],"BLOCKED")
        self.assertEqual(self.resolve([container()]*81)["reason"],"DISCOVERY_OVERFLOW")

    def test_origin_and_uuid_input_validation(self):
        with self.assertRaises(ValueError):
            resolve("wayfatours","production",UUID,"http://wayfatours.com",
                    discover=lambda:[])
        with self.assertRaises(ValueError):
            resolve("wayfatours","production","bad",ORIGIN,discover=lambda:[])


if __name__=="__main__":
    unittest.main()
