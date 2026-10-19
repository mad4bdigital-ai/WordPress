#!/usr/bin/env python3
"""Source-level security/certification contract for the separate ETG writer."""
import json
from pathlib import Path
root=Path(__file__).resolve().parents[1]
read=(root/'includes/adapters/class-mad4b-scp-etg-dfsb-adapter.php').read_text()
write=(root/'includes/adapters/class-mad4b-scp-etg-dfsb-write-adapter.php').read_text()
boot=(root/'mad4b-site-control-plane.php').read_text()
policy=json.loads((root/'config/adapter-registration-policy.json').read_text())
def must(ok,reason):
    if not ok: raise SystemExit("FAIL "+reason)
must("'content' => array()" in read and "'admin' => array()" in read,"original read adapter changed scope")
must("class-mad4b-scp-etg-dfsb-write-adapter.php" in boot,"writer not bootstrapped")
name='MAD4B_SCP_ETG_DFSB_Write_Adapter'
must(name in policy['approved_classes'] and name in policy['registered_hook_classes'],"writer lacks explicit reviewed registration")
for fragment in (
    "protected function mutation_requires_certification(){return true;}",
    "'staging'===wp_get_environment_type()",
    "current_user_can('manage_options')",
    "'admin'=>array(self::SLOT_APPLY,self::PROFILE_APPLY)",
    "MAD4B_SCP_Reversible_Adapter_Mutations::current_mutation_context()",
    "self::SLOT_APPLY=>self::SLOT_ROLLBACK",
    "self::PROFILE_APPLY=>self::PROFILE_ROLLBACK",
    "public function capture_reversible_state(",
    "public function read_reversible_state(",
    "public function restore_reversible_state(",
    "hash_equals((string)$record['after_sha256'],$currentSha)",
    "snapshot_fingerprint",
    "expected_proposed_sha256",
    "'seo_publication_performed'=>false",
    "array('post_types','require_post_type_binding','post_type_authority','routes')",
):
    must(fragment in write,"writer safety contract missing: "+fragment)
for forbidden in ("wp_remote_request(", "wp_insert_post(", "shell_exec(", "eval(", "WP_CLI::runcommand("):
    must(forbidden not in write,"writer forbidden arbitrary authority: "+forbidden)
must("mad4b/mutation-undo" in write,"central undo not declared")
print("PASS ETG separate governed writer: staging, native reversible journal, no SEO side channel")
