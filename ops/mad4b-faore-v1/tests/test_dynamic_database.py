import unittest
import sys
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
from dynamic_runtime import resolve_database

RESOURCE="a"*24


def item(name="db-replacement",resource=RESOURCE,env="production",
         service="db",health="healthy",running=True):
    return {"Id":"b"*64,"Name":"/"+name,"State":{"Running":running,"Health":{"Status":health}},
        "Config":{"Labels":{
            "coolify.managed":"true","coolify.type":"service",
            "coolify.environmentName":env,"com.docker.compose.service":service,
            "com.docker.compose.project":resource,"coolify.serviceUuid":resource,
        }}}


class DynamicDatabaseTests(unittest.TestCase):
    def test_database_same_resource(self):
        out=resolve_database(RESOURCE,"production",discover=lambda:[item()])
        self.assertEqual(out["database_state"],"DATABASE_OBSERVED")
        self.assertEqual(out["database_container"],"db-replacement")
        self.assertFalse(out["database_write_authorized"])

    def test_different_resource_or_environment_denied(self):
        self.assertEqual(resolve_database(RESOURCE,"production",
            discover=lambda:[item(resource="b"*24)])["database_reason"],
            "DATABASE_NOT_FOUND")
        self.assertEqual(resolve_database(RESOURCE,"production",
            discover=lambda:[item(env="staging")])["database_state"],"BLOCKED")

    def test_duplicate_fails_closed(self):
        out=resolve_database(RESOURCE,"production",
            discover=lambda:[item("db-a"),item("db-b")])
        self.assertEqual(out["database_reason"],"AMBIGUOUS_DATABASES")

    def test_health_and_role_required(self):
        for entry in (item(health="unhealthy"),item(service="redis"),item(running=False)):
            with self.subTest(entry=entry):
                self.assertEqual(resolve_database(RESOURCE,"production",
                    discover=lambda:[entry])["database_state"],"BLOCKED")

    def test_untrusted_resource_rejected(self):
        with self.assertRaises(ValueError):
            resolve_database("../../other","production",discover=lambda:[])


if __name__=="__main__":
    unittest.main()
