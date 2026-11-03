"""Source-backed regression contract: ChatGPT MCP Client remains the AI default.

This check never changes live WordPress options or grants execution authority.
The original execution router and queue are the source of truth.
"""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
INC = ROOT / "ops/mad4b-governed-task-context-v1/plugin/includes"


class ChatGPTMCPDefaultTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.router = (INC / "class-mad4b-hub-execution-router.php").read_text()
        cls.queue = (INC / "class-mad4b-hub-queue-lifecycle.php").read_text()
        cls.ai = (INC / "class-mad4b-hub-ai-queue-pipeline.php").read_text()
        cls.selection = (INC / "class-mad4b-hub-executor-selection.php").read_text()

    def test_client_default_is_fixed_and_user_visible(self):
        self.assertIn("const DEFAULT_MODE = 'mcp_client';", self.router)
        self.assertIn("'default_mode'=>self::DEFAULT_MODE", self.router)
        self.assertIn("'default_executor'=>self::DEFAULT_MODE", self.router)
        self.assertIn("'mcp_client_default'=>true", self.router)
        self.assertIn("'chatgpt_client_uses_connected_mcp'=>true", self.router)

    def test_unconfigured_jobs_fall_back_to_mcp_client(self):
        self.assertIn("return self::DEFAULT_MODE;", self.router)
        self.assertIn("'mcp_client'", self.selection)
        self.assertIn("'mcp_client'", self.queue)

    def test_background_worker_is_disabled_by_default(self):
        self.assertIn("($v['background_ai_enabled']??false)===true", self.router)
        self.assertIn("&& self::background_available()", self.router)
        self.assertIn("self::error('background_ai_not_enabled')", self.router)
        self.assertIn("self::error('background_ai_runner_missing',503)", self.router)

    def test_background_requires_explicit_job_selection(self):
        self.assertIn("public static function select($input)", self.router)
        self.assertIn("if($mode==='background_ai'", self.router)
        self.assertIn("'background_ai_requires_explicit_opt_in'=>true", self.router)

    def test_client_handoff_is_not_a_server_model_execution(self):
        self.assertIn("'backend_model_called'=>false", self.router)
        self.assertIn("'publication_performed'=>false", self.router)
        self.assertIn("'artifact_written'=>false", self.router)
        self.assertIn("'client_execution_required'=>", self.router)

    def test_oauth_is_verified_not_inferred_from_wp_cli(self):
        self.assertIn("verified_bearer_client_is(", self.router)
        self.assertIn("https://chatgpt.com/oauth/client.json", self.router)
        self.assertIn("'wpcli_fallback_no_oauth_impersonation'=>true", self.router)

    def test_mcp_handoff_waits_for_external_client(self):
        self.assertIn("if($executor==='mcp_client')return 'WAITING_EXECUTOR';", self.queue)
        self.assertIn("'mcp_client'", self.selection)

    def test_guard_tasks_are_not_mistaken_for_model_execution(self):
        self.assertIn("'assigned_executor'=>'vps_runner'", self.ai)
        self.assertIn("vps_runner_for_guard_tasks_mcp_client_for_external_output", self.ai)
        self.assertIn("'model_invoked'=>false", self.ai)

    def test_review_and_publishing_authority_remain_separate(self):
        self.assertIn("'review_mode_independent'=>true", self.router)
        self.assertIn("'default_content_review_mode'=>'autonomous'", self.router)
        self.assertIn("'publication_performed'=>false", self.router)


if __name__ == "__main__":
    unittest.main()
