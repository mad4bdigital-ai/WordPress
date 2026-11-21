"""Protect the single current MAD4B Server owner, without granting transport access."""
from pathlib import Path
import re
import unittest

ROOT=Path(__file__).resolve().parents[1]
PLUGIN=ROOT/'wp-content/plugins/mad4b-site-control-plane'
CORE=PLUGIN/'includes/class-mad4b-scp-server-core-catalog.php'
WRITER=PLUGIN/'includes/class-mad4b-scp-server-write-catalog.php'
FACADE=PLUGIN/'includes/class-mad4b-scp-servers.php'
AUTHORITY=PLUGIN/'includes/class-mad4b-scp-staging-write-authority.php'
HOST_TRAIT=PLUGIN/'includes/trait-mad4b-scp-servers-vps-local-identity.php'

class ServersCurrentSourceOwner(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.core=CORE.read_text()
        cls.writer=WRITER.read_text()
        cls.facade=FACADE.read_text()
        cls.host_trait=HOST_TRAIT.read_text()
        cls.authority=AUTHORITY.read_text()

    def test_live_provider_projection_has_exactly_one_source_owner(self):
        declaration='public static function live_write_provider_snapshot()'
        self.assertEqual(self.writer.count(declaration),1)
        self.assertNotIn(declaration,self.facade,
                         'Do not copy the older live facade method over its extracted trait')
        self.assertIn('use MAD4B_SCP_Server_Write_Catalog;',self.facade)
        self.assertIn("require_once __DIR__ . '/class-mad4b-scp-server-write-catalog.php';",self.facade)
        self.assertIn('MAD4B_SCP_Servers::live_write_provider_snapshot()',self.authority)
        self.assertIn('self::registered_adapter_write_candidates()',self.writer)
        self.assertIn('self::write_tools()',self.writer)
        self.assertIn("= 'core'",self.writer)
        self.assertIn('in_array( $ability_name, $surface_candidates, true )',self.writer)
        self.assertNotIn('wp_insert_post(',self.writer)

    def test_native_core_catalog_stays_one_trait_not_a_second_facade(self):
        self.assertIn('trait MAD4B_SCP_Server_Core_Catalog',self.core)
        self.assertIn('use MAD4B_SCP_Server_Core_Catalog;',self.facade)
        self.assertIn('private static function declared_core_tools(',self.core)
        self.assertNotIn('private static function declared_core_tools(',self.facade)
        self.assertIn("return array( 'mad4b-read', 'mad4b-chatgpt'",self.facade)

    def test_host_stdio_surface_does_not_become_remote_write_grant(self):
        # A trait is not evidence of a live guard unless the real Servers
        # class loads and uses that one first-party source. Check binding,
        # uniqueness, then the actual original Host restrictions.
        self.assertIn("require_once __DIR__ . '/trait-mad4b-scp-servers-vps-local-identity.php';", self.facade)
        self.assertIn('use MAD4B_SCP_Servers_VPS_Local_Identity_Trait;', self.facade)
        self.assertEqual(self.host_trait.count('trait MAD4B_SCP_Servers_VPS_Local_Identity_Trait'), 1)
        self.assertNotIn('private static function vps_local_actor_verified()', self.facade)
        content=self.host_trait
        for gate in ('vps_local_stdio_requested()', "'mcp-adapter' !== (string) $args[$i]",
                     "'serve' !== (string) $args[$i+1]",
                     "'--server=mad4b-vps-local'",
                     "'registered_operator'",
                     "posix_geteuid()", "get_current_user_id()",
                     "'staging'", "'registry_bound'", "'host_cli'",
                     "MAD4B_SCP_Site_Profile::site_uuid()",
                     "'mad4b_vps_local_identity_required'"):
            with self.subTest(gate=gate):
                self.assertIn(gate,content)
        self.assertIn('if ( ! self::vps_local_read_verified() && ! self::vps_local_actor_verified() )',content)
        # The original provider-for-ability mount fence remains on Servers,
        # not on the detached trait.
        self.assertIn("'mad4b-vps-local' === $server_id", self.facade)
        self.assertIn("self::core_tools( 'mad4b-vps-local' )", self.facade)

    def test_required_tool_failure_preserved_not_degraded_to_provider_warning(self):
        self.assertIn("mcp_required_tool_preflight_failed",self.facade)
        self.assertIn("empty( $preflight['ready'] )",self.facade)
        self.assertIn("self::$registrations[ $id ]['catalog_evidence']",self.facade)
        self.assertIn("self::$registrations[ $id ]['error']",self.facade)

if __name__ == '__main__':
    unittest.main()
