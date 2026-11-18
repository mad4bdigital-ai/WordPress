#!/usr/bin/env python3
"""Hermetic signed Host v2 *read-only scope* tests; no SSH or policy writes."""
import copy,importlib.util,pathlib,unittest
f=pathlib.Path(__file__).with_name("host_scope.py")
spec=importlib.util.spec_from_file_location("host_scope",f)
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
UUID={"egypt-tour-gates":"0c7786ba-a025-4650-9ceb-32a51d74aaec",
      "tourism-almallahgroup":"b8dffda1-db99-4789-aa72-86bf63f557cc"}
ORIGIN={"egypt-tour-gates":"https://egypttourgates.com",
      "tourism-almallahgroup":"https://tourism.almallahgroup-mg.com"}
ROUTE={"egypt-tour-gates":("hosting-3","/home/u783534439/domains/egypttourgates.com/public_html"),
       "tourism-almallahgroup":("hosting-1","/home/u338416126/domains/almallahgroup-mg.com/public_html/tourism.almallahgroup-mg.com")}
def fixture():
    hosts={"contract":"mad4b.native-vps-operator.v1","host_id":"srv2030908","revoked":False,"scopes":{
      "all-royal-egypt:production":{"allow_operator":False,"allow_mutation":False,
        "allow_production_mutation":False,"allow_sql":False,"allow_remote_shell":False,
        "allow_pinned_wpcli_read":True,
        "site_uuid":"0384b6ce-4004-4b4e-b701-7d77cb6f6ff0",
        "canonical_origin":"https://www.allroyalegypt.com"}}}
    router={"contract":"mad4b.unified-execution-router.policy.v1",
      "auth_model":"source-verified-host-operator-read-only","targets":{}}
    owners={k:{"uuid":UUID[k],"origin":ORIGIN[k]} for k in UUID}
    routes={k:{"origin":ORIGIN[k],"ssh_target":ROUTE[k][0],"ssh_root":ROUTE[k][1]} for k in UUID}
    return hosts,router,owners,routes
class Tests(unittest.TestCase):
    def test_enroll_exact_two_and_keep_all_mutation_flags_false(self):
        orig,router,owner,routes=fixture()
        hosts,nrouter,changed=m.desired(orig,router,owner,routes)
        self.assertEqual(set(changed),set(k+":production" for k in UUID))
        self.assertEqual(orig["scopes"].keys(),{"all-royal-egypt:production"})
        self.assertEqual(router["targets"],{})
        for name in UUID:
            h=hosts["scopes"][name+":production"]
            self.assertEqual(h["site_uuid"],UUID[name])
            self.assertEqual(h["canonical_origin"],ORIGIN[name])
            for key in m.MUTATION_KEYS:
                self.assertIs(h[key],False)
            self.assertIs(h["allow_pinned_wpcli_read"],True)
            rt=nrouter["targets"][name+":production"]
            self.assertIs(rt["read_only_hostinger_binding"],True)
            self.assertEqual(rt["providers"]["hostinger_pinned_read"]["ssh_target"],ROUTE[name][0])
        self.assertEqual(hosts["scopes"]["all-royal-egypt:production"],orig["scopes"]["all-royal-egypt:production"])
        self.assertEqual(m.desired(hosts,nrouter,owner,routes)[2],[])
    def test_refuses_existing_mutation_scope(self):
        h,r,o,route=fixture()
        h["scopes"]["egypt-tour-gates:production"]={
          "allow_operator":True,"allow_mutation":False,
          "allow_production_mutation":False,"allow_sql":False,
          "allow_remote_shell":False,"site_uuid":UUID["egypt-tour-gates"],
          "canonical_origin":ORIGIN["egypt-tour-gates"]}
        with self.assertRaisesRegex(RuntimeError,"WRITER_PRESENT"):
            m.desired(h,r,o,route)
    def test_rejects_origin_and_site_drift(self):
        for field,value in [("site_uuid","bad"),("canonical_origin","https://fake.example")]:
            h,r,o,route=fixture()
            r["targets"]["egypt-tour-gates:production"]={
              "site_uuid":UUID["egypt-tour-gates"],
              "canonical_origin":ORIGIN["egypt-tour-gates"],
              "read_only_hostinger_binding":True}
            r["targets"]["egypt-tour-gates:production"][field]=value
            with self.subTest(field=field),self.assertRaises(RuntimeError):
                m.desired(h,r,o,route)
    def test_invalid_original_registry_refused(self):
        h,r,o,route=fixture();h["revoked"]=True
        with self.assertRaisesRegex(RuntimeError,"ORIGINAL_HOST_OR_ROUTER_CONTRACT_CHANGED"):
            m.desired(h,r,o,route)
        h,r,o,route=fixture();r["auth_model"]="open-unrestricted"
        with self.assertRaisesRegex(RuntimeError,"ORIGINAL_HOST_OR_ROUTER_CONTRACT_CHANGED"):
            m.desired(h,r,o,route)
    def test_staging_untouched(self):
        h,r,o,route=fixture()
        h["scopes"]["egypt-tour-gates:staging"]={"allow_operator":True}
        h2,r2,_=m.desired(h,r,o,route)
        self.assertEqual(h2["scopes"]["egypt-tour-gates:staging"],h["scopes"]["egypt-tour-gates:staging"])
        self.assertEqual(r2["targets"].keys(),{"egypt-tour-gates:production","tourism-almallahgroup:production"})
if __name__=="__main__":unittest.main(verbosity=2)
