#!/usr/bin/env python3
"""Source-bound review of existing original PHP fixtures, not a production release.

Only curated fixture/source pairs are permitted. The original WP classes are
loaded in isolated PHP CLI with no network, no writable site, no source changes.
The review gates independently enforce exact Git blob, controls and CI evidence.
"""
from pathlib import Path
import re
import shutil
import subprocess

ROOT=Path(__file__).resolve().parents[1]
P='wp-content/plugins/mad4b-site-control-plane/'
# PHP fixture basename, included source basenames, expected actual pass marker.
CASES=(
 # Provider mutation, retry recovery, and dynamic provider-setup direct
 # original source tests. CI-only, never grants a Production release.
 ("provider-transport-eligibility-runtime.php",
  ("class-mad4b-scp-provider-transport-eligibility.php",),
  "mad4b.provider-transport-eligibility.runtime.v1: PASS"),
 ("market-growth-competitor-dmc-runtime.php",
  ("class-mad4b-scp-recovery-attempt-budget.php",),
  "PASS general competitor market rules, site-neutral engine, pricing and bounded durable retry circuit"),
 ("provider-setup-coverage-runtime.php",
  ("class-mad4b-scp-provider-setup-coverage.php",),
  "SUMMARY pass=32 fail=0"),
 ("central-transport-write-source-hermetic.php",
  ("class-mad4b-scp-capability-transport-routing.php",
   "class-mad4b-scp-central-write-acceptance.php"),
  "PASS: CENTRAL_TRANSPORT_WRITE_ORIGINAL"),
 ("unified-gate-dynamic-original-hermetic.php",
  ("class-mad4b-scp-unified-gate-funnel.php",),
  "PASS: UNIFIED_GATE_DYNAMIC_ORIGINAL"),
 ("context-write-mount-runtime.php",
  ("class-mad4b-scp-context-adapter.php","class-mad4b-scp-context-provider-gateway.php"),
  "mad4b.site-control-plane.context-write-mount.runtime.v6: PASS"),
 ("litespeed-optimization-hook-runtime.php",
  ("class-mad4b-scp-litespeed-adapter.php",),
  "LITESPEED_OPTIMIZATION_HOOK_RUNTIME_PASS"),
 ("skill-task-abilities-runtime.php",
  ("class-mad4b-scp-skills-adapter.php","class-mad4b-scp-skill-abilities.php"),
  "Skill Task Abilities registration fixtures: PASS"),
 ("imp01-import-preview-runtime.php",
  ("class-mad4b-scp-activity-import-review.php","class-mad4b-scp-activity-import-snapshot.php"),
  "PASS IMP01 signed REST review intake"),
 ("adaptive-operation-router-runtime.php",
  ("class-mad4b-scp-adaptive-operation-router.php",),
  "mad4b.site-control-plane.adaptive-operation-router.runtime.v1: PASS"),
 ("wordpress-addon-execution-guard-contract.php",
  ("class-mad4b-scp-addon-registry.php",),
  "PASS: mad4b.wordpress-addon-execution-binding.v1 original exact source assertions"),
 ("adaptive-interaction-broker/contract-test.php",
  ("class-mad4b-scp-adaptive-interaction-broker.php",),
  "PASS: native adaptive interaction original checkpoint and readback source assertions"),
 ("qa31-w1-agent-route-boundary-runtime.php",
  ("class-mad4b-scp-agent-grant-lifecycle.php",),
  "QA31_W1_AGENT_REST_ROUTING: PASS"),
 ("ai-review-worker-guard-hermetic.php",
  ("class-mad4b-scp-ai-review-worker.php",),
  "PASS: 29 source-only checks"),
 ("application-password-agent-bridge-hermetic.php",
  ("class-mad4b-scp-application-password-agent-bridge.php",),
  "SUMMARY pass=18 fail=0"),
 ("authorization-replay-audit-runtime.php",
  ("class-mad4b-scp-authorization.php",),
  "mad4b.authorization-replay-audit.runtime.v1: PASS"),
 ("unified-capability-dependency-runtime.php",
  ("class-mad4b-scp-capability-dependency-planner.php",),
  "PASS unified dependency plan"),
 ("projection-registration-lifecycle.php",
  ("class-mad4b-scp-chatgpt-tool-projection.php",),
  "PASS protected reflection:"),
 ("context-pipeline-admission-hermetic.php",
  ("class-mad4b-scp-context-source-pipeline.php",),
  "PASS: 29 source pipeline admission"),
 ("dynamic-hardening-pure-runtime.php",
  ("class-mad4b-scp-dynamic-provider-contract.php",),
  "mad4b.dynamic-hardening-pure-runtime.v1: PASS"),
 ("write-only-owner-handoff-runtime.php",
  ("class-mad4b-scp-full-staging-authority.php",),
  "WRITE_ONLY_OWNER_HANDOFF_RUNTIME_PASS"),
 ("provider-mcp-write-compiler-contract.php",
  ("class-mad4b-scp-governance-abilities.php",),
  "MAD4B per-ability MCP write compiler and approval runtime contract passed."),
 ("wp-native-self-approval-runtime.php",
  ("class-mad4b-scp-live-truth.php",),
  "PASS: self-approval, single execution claim"),
 ("market-source-calendar-authority-hermetic.php",
  ("class-mad4b-scp-market-profiles.php",),
  "PASS: 33 original market/source/calendar owner lifecycle checks"),
 ("oauth-multi-user-tenant-runtime.php",
  ("class-mad4b-scp-oauth-subject-user-bridge.php",),
  "MAD4B tenant-bound multi-user OAuth mapping: PASS"),
 ("operational-integrity-local-runtime.php",
  ("class-mad4b-scp-operational-integrity.php",),
  "PASS: local operational-integrity synthetic runtime"),
 ("provider-compatibility-engine-contract.php",
  ("class-mad4b-scp-provider-compatibility-certification.php",),
  "PASS: MAD4B provider compatibility engine original assertions"),
 ("recovery-route-resolver-runtime.php",
  ("class-mad4b-scp-recovery-route-resolver.php",),
  "PASS governed recovery:"),
 ("remote-work-queue-cancellation-runtime.php",
  ("class-mad4b-scp-remote-work-queue.php",),
  "mad4b.remote-work-queue-cancellation.runtime.v1: PASS"),
 ("staging-write-grant-plan-read-lane-runtime.php",
  ("class-mad4b-scp-staging-write-grant-reconciliation-plan.php",),
  "PASS: grant reconciliation planning stays on original read lane"),
 ("approval-plan-route-preflight-runtime.php",
  ("class-mad4b-scp-staging-write-planning-guard.php",),
  "APPROVAL_PLAN_ROUTE_PREFLIGHT_RUNTIME=PASS"),
 ("transport-permission-containment-runtime.php",
  ("class-mad4b-scp-transport-context.php",),
  "TRANSPORT_PERMISSION_CONTAINMENT_PASS"),
 ("unified-agent-connection-runtime.php",
  ("class-mad4b-scp-unified-agent-connection.php",),
  "UNIFIED_CONNECTION_RUNTIME: PASS"),
 ("unified-capability-pagination-runtime.php",
  ("class-mad4b-scp-unified-capability-gateway.php",),
  "PASS unified capability indexed cursor:"),
)
def main():
    php=shutil.which('php')
    assert php, "Original PHP runtime must be present. No static-only review fallback."
    seen=set()
    for basename,classes,marker in CASES:
        test=(ROOT/'tests'/basename if basename in (
            'central-transport-write-source-hermetic.php',
            'unified-gate-dynamic-original-hermetic.php'
        ) else ROOT/P/'tests'/basename)
        assert test.is_file() and not test.is_symlink(),test
        body=test.read_text(errors='replace')
        for name in classes:
            source=ROOT/P/'includes'/name
            if not source.is_file():source=ROOT/P/'includes'/'adapters'/name
            assert source.is_file() and not source.is_symlink(),source
            assert re.search(r'(?:require|include).*'+re.escape(name),body), \
                "Fixture not directly calling original source: "+name
            assert name not in seen, "Duplicate original source review: "+name
            seen.add(name)
        process=subprocess.run([php,str(test)],cwd=ROOT,timeout=85,capture_output=True,text=True)
        output=process.stdout+"\n"+process.stderr
        assert process.returncode==0 and marker in output and not re.search(
            r'(?m)^(?:FAIL[ :]|Fatal error:|Parse error:|Uncaught .*Error:)',output), \
            'Original PHP fixture failed: '+basename+' '+output[-1500:]
    print(f"PASS: READINESS_EXISTING_ORIGINAL_MATRIX {len(CASES)} native PHP "
          f"fixtures; {len(seen)} distinct first-party source files; "
          "CI-only and no site mutation")

if __name__=='__main__':
    main()
