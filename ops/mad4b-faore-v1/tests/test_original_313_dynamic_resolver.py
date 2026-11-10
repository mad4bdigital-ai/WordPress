import importlib.util
import json
import sys
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT))
SPEC=importlib.util.spec_from_file_location(
    "faore_original313_resolver",ROOT/"integrations/coolify-php-runtime.py")
MOD=importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MOD)
UUID="a3a7cc36-db7e-4a14-8456-b41857def05c"
ORIGIN="https://wayfatours.com"
CID="a"*64


class Original313DynamicResolverTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory()
        self.file=Path(self.tmp.name)/"root-target.json"
        self.file.write_text(json.dumps({
          "contract":"mad4b.company-native-target-runtime-bindings.v1",
          "targets":{"wayfatours:production":{
            "site_uuid":UUID,"canonical_origin":ORIGIN}},
        }))
        self.file.chmod(0o600)

    def tearDown(self):
        self.tmp.cleanup()

    def test_scope_from_original_registry_not_constants(self):
        with patch.object(MOD,"REGISTRY",self.file):
            self.assertEqual(MOD.original_scope(ORIGIN,UUID),
                             ("wayfatours","production"))
            with self.assertRaises(RuntimeError):
                MOD.original_scope("https://foreign.example",UUID)

    def test_ambiguous_enrollment_denied(self):
        data=json.loads(self.file.read_text())
        data["targets"]["copy:production"]=data["targets"]["wayfatours:production"]
        self.file.write_text(json.dumps(data))
        with patch.object(MOD,"REGISTRY",self.file):
            with self.assertRaisesRegex(RuntimeError,"NOT_UNIQUE"):
                MOD.original_scope(ORIGIN,UUID)

    def test_pinned_id_with_real_profile_readback_protocol(self):
        def native(company,environment):
            return {"site_uuid":UUID,"canonical_origin":ORIGIN,
                    "environment":"production","target_container_id":CID}
        def mocked_run(args,**kwargs):
            if args[1]=="inspect":
                payload=[{"Destination":"/var/www/html",
                          "Type":"bind","RW":True,
                          "Source":self.tmp.name}]
                return SimpleNamespace(returncode=0,stdout=json.dumps(payload))
            return SimpleNamespace(returncode=0,stdout=json.dumps({
                "origin":ORIGIN,"site_uuid":UUID,
                "environment":"production","bound":True}))
        with patch.object(MOD,"original_scope",return_value=("wayfatours","production")):
            with patch.object(MOD,"resolve_native",side_effect=native):
                with patch.object(MOD.subprocess,"run",side_effect=mocked_run):
                    self.assertEqual(MOD.discover(ORIGIN,UUID),CID)

    def test_unbound_wordpress_rejected(self):
        with patch.object(MOD,"original_scope",return_value=("wayfatours","production")):
            with patch.object(MOD,"resolve_native",return_value={
                "site_uuid":UUID,"canonical_origin":ORIGIN,
                "environment":"production","target_container_id":CID
            }):
                def fake(args,**kwargs):
                    if args[1]=="inspect":
                        return SimpleNamespace(returncode=0,stdout=json.dumps([{
                          "Destination":"/var/www/html","Type":"bind",
                          "RW":True,"Source":self.tmp.name}]))
                    return SimpleNamespace(returncode=0,stdout=json.dumps({
                        "origin":ORIGIN,"site_uuid":UUID,
                        "environment":"production","bound":False}))
                with patch.object(MOD.subprocess,"run",side_effect=fake):
                    with self.assertRaisesRegex(RuntimeError,"NOT_BOUND"):
                        MOD.discover(ORIGIN,UUID)


if __name__=="__main__":
    unittest.main()
