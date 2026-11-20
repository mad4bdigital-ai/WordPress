#!/usr/bin/env python3
"""Execute each newly mapped regression test and record exact-head CI execution.

Local Python scripts are run as subprocesses. PHPUnit files need an available
PHPUnit runner; php -l alone is deliberately not counted as functional testing.
No PR-provided JSON or static filepath can assert test success without execution.
"""
from __future__ import annotations
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
SHA = re.compile(r"^[a-f0-9]{40}$")

# These exact tests exercise the real POSIX euid==0 branch of original
# Host CLI authority. CI's normal runner is unprivileged, so launch ONLY
# reviewed, offline fixtures with a passwordless root process. This is not
# evidence of a production Host NHI, grant, operator approval, or signed lane.
# sudo's default environment reset prevents inherited user/token identity.
HOST_ROOT_HERMETIC_REGRESSIONS = frozenset((
    "tests/execution-authority-identity-native-hermetic.php",
    "tests/authority-auto-reconcile-host-hermetic.php",
    "tests/authority-servers-original-review-suite.py",
))

# Review-bound CLI PHP scripts, not a blanket PHP execution policy.
# Each path contains exit-on-failure assertions and a positive PASS marker.
# Other PHP files continue to require PHPUnit; unknown paths fail closed.
STANDALONE_PHP_REGRESSIONS = frozenset((
    # Source-reviewed company/native Fluent Forms read-only notice preview.
    # Fake forms, options and transports; never writes or schedules email.
    "tests/company-email-native-notice-preview-hermetic.php",
    # Exact, nonmutating Context Authority classification preview with stub
    # WordPress options, user, source registry, and Site Profile; only reads.
    "wp-content/plugins/mad4b-site-control-plane/tests/context-classification-readonly-preview-runtime.php",
    # PR942 exact, source-reviewed scoped CIMD compatibility fixtures.
    # All three execute under PHP 7.4 and 8.5 as isolated CLI scripts using
    # in-memory WordPress/OAuth doubles; none sends tokens, accesses a real
    # database, or changes site state. This never widens arbitrary PHP running.
    "wp-content/plugins/mad4b-site-control-plane/tests/cimd-scoped-bearer-identity-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/chatgpt-auto-binding-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/reconnect-hardening-runtime.php",
    # Twelve reviewed PR882/PR920 source-merger PHP fixtures are pure CLI:
    # synthetic WordPress functions, explicit exit-on-fail assertions, no DB,
    # remote network, site initialization, or grant capability. We verified
    # each under a read-only, network-disabled PHP container on this exact tree.
    "wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-live-catalog-read-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-live-skills-list-paging-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-live-source-skills-parity-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-cimd-client-security-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-native-diagnostics-read-parity-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-switcher-flag-scheme-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-wpml-site-origin-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-wpml-site-origin-rest-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-wpml-site-origin-cli-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-signed-host-agent-negative-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-signed-host-context-review-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-cli-context-identity-runtime.php",
    # Reviewed standalone source-only fixtures expected by the historic
    # source integration self-test. These paths are *admitted to execute*,
    # never assumed to pass: stale frozen-source checks continue to fail when
    # explicitly requested by a release cohort. No wildcard PHP execution.
    'wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-agent-authority-source-only-runtime.php',
    'wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-authority-review-source-only-runtime.php',
    'wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-content-quality-dependencies-source-only-runtime.php',
    'wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-context-local-document-normalizer-runtime.php',
    'wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-fcp-local-observability-runtime.php',
    'wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-fcp-queue-readback-runtime.php',
    'wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-hub-lifecycle-source-only-runtime.php',
    'wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-local-transport-boundary-runtime.php',
    'wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-provider-field-read-parity-runtime.php',
    'wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-readiness-preactivation-source-only-runtime.php',
    'wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-readonly-global-runtime-lifecycle-runtime.php',
    'wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-readonly-native-settings-locator-runtime.php',
    'wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-readonly-provider-installed-artifact-runtime.php',
    'wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-readonly-unified-gate-funnel-runtime.php',
    'wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-scheduler-source-closure-runtime.php',
    'wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-unactivated-source-parity-runtime.php',
    'wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-visual-seo-strategy-runtime.php',
    # Explicit per-company CWV admission fixture: 17 positive and negative
    # source-only cases, synthetic Site Profile/options/schedule, with no real
    # WordPress, Cron writes, database, browser or external network access.
    # The bounded PHP runner checks the PASS marker after actual execution.
    "wp-content/plugins/mad4b-site-control-plane/tests/cwv-site-admission-hermetic.php",
    # Exact site-neutral destination Term Meta preflight fixture: fake identity,
    # fake DB baseline, simulated JetEngine admin POST, WP metadata filter
    # rejection, benign first-party URLs and a no-network WP_Error response.
    # No actual site state, publishing, remote fetch, authorization or secrets.
    "wp-content/plugins/mad4b-site-control-plane/tests/destination-term-save-preflight-runtime.php",
    # Read-only destination Term Meta alert regression: isolated synthetic DB,
    # exact CSV media URL boundaries, fake HTML readback, and negative closure
    # cases. No real WordPress, network, authorizations or production writes.
    "wp-content/plugins/mad4b-site-control-plane/tests/destination-term-alerts-runtime.php",
    # Original Site Profile tested with hermetic WPML/WordPress functions.
    # 12 positive/negative canonical-origin checks, PHP 7.4-compatible and
    # source-only: no network, cookies, database, grants or Production change.
    "tests/visitor-session-wpml-origin-hermetic.php",
    # Exact source-reviewed PHP CLI contracts with isolated WordPress stubs
    # and negative site/provider grant checks. These scripts are not PHPUnit
    # suites. No real WordPress, external API, owner approval or site write.
    # Every path is exhaustively bounded below; arbitrary PHP still denied.
    "wp-content/plugins/mad4b-site-control-plane/tests/chatgpt-file-transfer-permission-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/dynamic-company-environment-provider-parity-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/optional-governance-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/staging-write-grant-reviewed-inventory-runtime.php",
    # No enrolled Host signer, no original grant, no WordPress/remote I/O.
    "wp-content/plugins/mad4b-site-control-plane/tests/host-agent-fail-closed-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/context-scan-media-integrity/isolated-contract.php",
    # The existing Context-document runtime has its own first-party fake WP
    # context; 7.4/8.3/8.5 exercised SVG fail-closed and regular text.
    # Explicitly review this named regression only; no generic PHP relaxation.
    "wp-content/plugins/mad4b-site-control-plane/tests/context-document-normalization-runtime.php",
    # Exact ChatGPT per-connector metadata and redirect safety in isolated PHP.
    "wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-cimd-client-security-runtime.php",
    # Three separately reviewed original source-only fixtures. The Operation
    # Registry uses a process-scoped /tmp catalog and negative mutation policy;
    # Presentation Evidence and Integrity use fake WP state, local temporary
    # files or in-memory DB and deny implicit visual/Elementor mutations.
    # No real WordPress install, provider network, owner approval or grants.
    "tests/operation-registry-native-policy-boundary-hermetic.php",
    "tests/presentation-browser-evidence-local-import-hermetic.php",
    "tests/presentation-integrity-jetengine-source-hermetic.php",
    # Real Adaptive Search provider enrollment runs only against first-party
    # in-memory fixture HTTP/WordPress stores; successful assertions precede
    # the terminal PASS and unsuccessful cases retain exit(1).
    "wp-content/plugins/mad4b-site-control-plane/tests/adaptive-search-provider-enrollment-runtime.php",
    # Reviewed isolated fixtures: fake Site Profile lineage, fake-DB Drive
    # continuation, provider TTL with stub OAuth, and in-memory QA30 Admin.
    # No real site, provider HTTP, credentials, mutation or release authority.
    "wp-content/plugins/mad4b-site-control-plane/tests/brand-asset-source-binding-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/drive-scan-continuation-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/provider-session-ttl-partial-context.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/qa30-native-general-settings-rest-lifecycle.php",
    # Official MCP Adapter DTO test is allowed only with the exact pinned,
    # SHA256-checked packaged provider extracted into an isolated /tmp tree.
    # The real registered DTOs and negative schemas execute; no network/OAuth
    # or WordPress mutation. Unknown PHP remains denied.
    "wp-content/plugins/mad4b-site-control-plane/tests/mcp-official-required-preflight-runtime.php",
    # Exact no-WordPress Brand Quality/issue-ledger closure fixture. It uses
    # synthetic WP functions and a positive fail-on-assertion PASS marker;
    # no DB, publish, email or authorization. Unknown PHP stays denied.
    "tests/brand-quality-readiness-coverage-hermetic.php",
    # The exact first-party media-rights fixture uses a temporary image
    # placeholder, hermetic WordPress stubs and fail-on-assertion checks.
    # It cannot grant a license, publish, perform network access, or write a
    # live site; unknown PHP files still require the trusted PHPUnit runner.
    "tests/media-rights/media-rights-source-convergence-hermetic.php",
    # Original Catalog Intent read-only source planner, deterministic fake
    # WordPress abilities and invalid URLs. No crawl, content creation, write,
    # network, or signed owner admission; unknown PHP paths remain blocked.
    "tests/catalog-intent-source-relevance-hermetic.php",
    # Isolated signed Central source+Company rollout fixture uses an ephemeral
    # RSA key and an .invalid origin; executes assertions with zero live grants.
    "tests/central-company-release-signed-readonly-hermetic.php",
    # Original ChatbotX 11-read/3-write source registration and negative
    # mutation checks, using only in-memory fake WordPress / no network.
    "tests/chatbotx-native-adapter-surface-hermetic.php",
    # Reviewed no-network per-company CPT allowlist security regression.
    "wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-chatbotx-site-neutral-runtime.php",
    # Original site source pages and stubbed receipt denial tests only.
    # No cryptographic verification, live grant, network or site mutation.
    "tests/company-code-cryptographic-pages-hermetic.php",
    # Exact no-network company settings REST permission fixture with
    # bounded positive and denial assertions; unknown PHP remains blocked.
    "tests/company-communication-policy-rest-hermetic.php",
    # Exact original Company Master Admin actor/tenant REST contract fixture.
    # No user creation or role migration; production/staging denial asserted.
    "tests/company-master-native-rest-gates-hermetic.php",
    # Exact fake-DB tenant policy-only CAS, independent-owner reviewer and
    # transaction rollback fixture. External site/provider writes stay zero.
    "tests/company-migration-decisions-transaction-hermetic.php",
    # Exact original company Skill Set fixture; real policy JSON loaded into
    # a temporary local Skill Registry. No network/provider execution or
    # authority mutation; acceptance remains explicitly untested.
    "tests/company-reconcile-skill-set-no-executor-hermetic.php",
    # Exact, in-memory WP-CLI read/inventory with scheduled intent/commit
    # audit and negative Host/no-SQL execution; no real WordPress mutation.
    # Original read-only Company Release and FAORE staging inspection;
    # synthetic WordPress stubs reject grant, deployment, options and broker
    # mutations. Exact source test only; no live Original313 authority.
    "tests/native-release-and-staging-canary-denial-hermetic.php",
    # Reviewed native Search Console site-only OAuth/retained-observation
    # fixture: in-memory provider/WP stubs; no HTTP, real grants or writes.
    "tests/native-search-console-site-scope-php74-hermetic.php",
    # Audited no-WordPress source fixtures: deny incomplete Brand owner proof,
    # ensure Selected HEAD remains non-authorizing, provider OAuth health
    # plans do not grant refresh, and Search Provider budget/rights denials.
    "wp-content/plugins/mad4b-site-control-plane/tests/brand-context-owner-evidence-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/control-plane-test-router-source-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/provider-connection-center-runtime.php",
    "tests/search-provider-autoprovision-runtime-denials-hermetic.php",
    # Three exact source-audited negative fixtures: admin-only CWV REST,
    # readonly Provider field/locale metadata and synthetic bounded Cron.
    # No real WordPress DB, provider HTTP or license/grant mutations.
    "tests/readiness-core-web-vitals-rest-cron-hermetic.php",
    "tests/provider-field-intelligence-site-readonly-php74-hermetic.php",
    "tests/readiness-native-cron-ownership-hermetic.php",
    # Exact independently inspected offline WordPress source fixtures: native
    # Server catalog isolation, Origin research evidence-only stubs, and
    # Firecrawl route/SSRF denials. No live provider HTTP, site writes, grants
    # or credential lookup. Each asserts denials before canonical terminal PASS.
    "tests/servers-native-core-catalog-hermetic.php",
    "tests/origin-external-research-source-bound-hermetic.php",
    "tests/unified-provider-routing-native-safety-hermetic.php",
    # Source-only Skills Cron dedupe, in-memory WP scheduler and denied
    # nonenrolled/mismatched origin before any Skill filesystem or DB writes.
    "tests/native-skills-seeder-enrollment-cron-hermetic.php",
    # Tour Highlights Elementor/JetEngine/WPML source behavior uses only
    # in-memory WP metadata/terms and .invalid URLs; denied publishing and
    # cross-locale writes are asserted. No site/database mutations.
    "tests/native-tour-highlights/acceptance.php",
    "tests/native-cli-context-and-company-inventory-hermetic.php",
    # Exact hermetic source-only Company/ContentJob/Media Rights regressions:
    # fake FluentBoards, in-memory WP users/roles/CAS and role transactions,
    # synthetic provider callbacks and local image/path stubs. No network,
    # WP/DB/Commerce writes, live tenant operations or release grants.
    # Real external provider calls are replaced by source-owned denial stubs.
    "tests/company-task-handoff-native-cas-hermetic.php",
    "tests/company-tenant-migration-rollback-hermetic.php",
    "tests/company-user-native-lifecycle-hermetic.php",
    "tests/content-completeness-original-hermetic.php",
    "tests/content-job-groups-admission-original-hermetic.php",
    "tests/content-job-register-native-hermetic.php",
    "tests/content-job-structural-qa-admission-original-hermetic.php",
    "tests/content-media-local-missing-rights-original-hermetic.php",
    "tests/content-media-rights-guard-original-hermetic.php",
    # Twenty exact reviewed, standalone source-owned denial fixtures for
    # identity, granted scopes, tenant vault, Developer/DFSB, Site Profile,
    # native Elementor, Firecrawl provider and frontend sampling boundaries.
    # All source tests use fake WP providers/HTTP, no real network/DB/site
    # execution, persistent grant, publish or protected environment mutation.
    "tests/converged-grant-eligibility-hermetic.php",
    "tests/core-ai-governance-usage-and-admission-hermetic.php",
    "tests/core-connector-vault-original-hermetic.php",
    "tests/delegation-owner-original-hermetic.php",
    "tests/developer-policy-v2-original-hermetic.php",
    "tests/developer-workspace-admission-original-hermetic.php",
    "tests/dfsb-environment-policy-original-hermetic.php",
    "tests/dfsb-read-adapter-original-hermetic.php",
    "tests/drive-scan-cron-original-hermetic.php",
    "tests/dynamic-provider-manifest-original-hermetic.php",
    "tests/elementor-native-mcp-awareness-original-hermetic.php",
    "tests/execution-authority-identity-native-hermetic.php",
    "tests/fcp-local-observability-site-bound-hermetic.php",
    "tests/firecrawl-page-store-original-hermetic.php",
    "tests/firecrawl-provider-php74-crawl-and-host-scope-hermetic.php",
    "tests/firecrawl-research-adapter-provider-presence-hermetic.php",
    "tests/frontend-impact-same-origin-budget-hermetic.php",
    "tests/geo-quality-original-hermetic.php",
    "tests/global-runtime-lifecycle-original-hermetic.php",
    "tests/guided-continuation-write-surface-denial-hermetic.php",
    # Exact source-only Host Bridge Ed25519 readback, JetEngine, LiteSpeed,
    # local brand, locale and MCP scope tests. Host Bridge cleanup uses native
    # bounded temporary directory recursion, never any shell executor.
    "tests/host-node-inventory-signed-readback-hermetic.php",
    "tests/hub-jetengine-consumer-fence-original-hermetic.php",
    "tests/litespeed-adapter-original-hermetic.php",
    "tests/local-brand-draft-adapter-original-hermetic.php",
    "tests/locale-experience-alerts-owned-observations-hermetic.php",
    "tests/mcp-adapter-dynamic-integrity-original-hermetic.php",
    "tests/mcp-protocol-scope-original-hermetic.php",
    "tests/mcp-provider-matrix-readonly-inheritance-hermetic.php",
    # Canonical Inspector + Server evidence on synthetic WordPress Ability;
    # owner scopes and grant checks are not mocked as approved live writes.
    "tests/server-descriptor-canonical-multilane-hermetic.php",
    # Exact source-owned no-WordPress capability lane fixture: 19 fail-on-
    # assertion positive and negative checks, no database/network/real grants.
    # The full foreign-class recovery remains a separate actual WP CI job.
    "wp-content/plugins/mad4b-site-control-plane/tests/server-capability-lane-isolation.php",
    "tests/remote-execution/chatgpt-optional-catalog-smoke.php",
    "tests/remote-execution/developer-preparation-bridge-smoke.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/mcp-catalog-adaptive-budget.php",
    # QA31 exact hermetic PHP fixtures; arbitrary PHP execution remains blocked.
    "wp-content/plugins/mad4b-site-control-plane/tests/live-write-provider-snapshot-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/native-plugin-management-menu-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/adaptive-interaction-broker/contract-test.php",
    # Exact, hermetic PHP scripts; never promote arbitrary runtime PHP to CLI.
    "wp-content/plugins/mad4b-site-control-plane/tests/ai-domain-executors-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/ai-native-owner-receipts-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/ai-only-local-early-auth-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/ai-grant-mode-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/ai-model-routing-worker-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/ai-review-worker-guard-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/ai-review-crossenv-scope-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/ai-review-credential-expiry-hermetic.php",
    # Exact signed-market and source-bootstrap fixtures execute without WordPress.
    # Review-bound paths only; unlisted PHP still requires PHPUnit.
    "tests/switchers/test-market-compatibility.php",
    "tests/switchers/test-market-context-boundaries.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/market-bootstrap-compatibility.php",
    # Exact no-network PHP fixture reviewed for external Skill source discovery only.
    "wp-content/plugins/mad4b-site-control-plane/tests/external-skill-sources-runtime.php",
    # CI-reviewed standalone Skill fixtures: exit-on-assertion-failure and
    # require a positive PASS marker. Authenticated WordPress smoke is excluded.
    "wp-content/plugins/mad4b-site-control-plane/tests/external-skill-source-provider-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/skill-task-abilities-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/skill-task-composition-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/egress-policy-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/market-source-read-abilities-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/market-source-calendar-cron-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/market-source-calendar-authority-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/provider-setup-coverage-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/authority-setup-fields-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/background-authorization-verified-identity-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/unified-policy-gate-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/unified-queue-extension-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/unified-workflow-inspect-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/context-pipeline-admission-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/oauth-preflight-denial-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/passwordless-mcp-identity-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/cross-plugin-lifecycle-admission-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/browser-operator-integrity-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/plugin-lifecycle-preflight-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/local-client-request-boundary-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/context-admin-integrity-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/google-revocation-boundary-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/oauth-resource-signature-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/unified-admin-rest-permissions-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/media-inventory-authorization-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/ai-source-repair-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/unified-approval-ai-first/local-rest-fastpath-contract.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/public-holiday-hermetic-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/application-password-agent-bridge-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/ai-review-lifecycle-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/ai-review-lifecycle-media-evidence-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/ai-review-lifecycle-history-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/provider-connections-admin-read-hermetic.php",
    # First-party native LiteSpeed read / cache purge source evidence.
    "tests/litespeed-adapter-original-hermetic.php",
    # Original first-party Brand/Provider source receipts: no live mutation.
    "tests/context-provider-gateway-original-hermetic.php",
    # Exact original WordPress evidence-only source regression suites.
    "tests/external-media-ingest-original-hermetic.php",
    "tests/cli-readiness-original-hermetic.php",
    # Native source grant PLAN only: no REST owner promotion or grant mutation.
    "tests/agent-grant-plan-original-hermetic.php",
    # Original native pending-ticket gate, no creation of a real approval ticket.
    "tests/staging-write-planning-guard-original-hermetic.php",
    # Original MCP runtime, REST capability and native write preflight; no real grants.
    "tests/mcp-protocol-scope-original-hermetic.php",
    "tests/unified-gateway-permission-original-hermetic.php",
    "tests/authorization-preflight-original-hermetic.php",
    # Original nonmutating Developer/diagnostic gate and NHI Undo journal fixtures.
    "tests/developer-authority-access-original-hermetic.php",
    "tests/mcp-request-scope-original-hermetic.php",
    # Original metadata-only catalog, DB preflight, and zero-touch plugin/skill cases.
    "tests/artifacts-original-hermetic.php",
    "tests/research-intelligence-original-hermetic.php",
    "tests/remote-operation-catalog-original-hermetic.php",
    "tests/plugin-bootstrap-original-hermetic.php",
    "tests/skill-provider-source-original-hermetic.php",
    # Native P1 scheduler and review routing admission (no live Google or agent approval).
    "tests/drive-scan-cron-original-hermetic.php",
    "tests/brand-review-routing-original-hermetic.php",
    # Original first-party P1 content/read-only storage and owner admission.
    "tests/firecrawl-page-store-original-hermetic.php",
    "tests/readiness-workflow-options-original-hermetic.php",
    "tests/content-job-structural-qa-admission-original-hermetic.php",
    "tests/content-job-groups-admission-original-hermetic.php",
    "tests/content-relationship-owner-cert-original-hermetic.php",
    # Native first-party origin/relationship admission and exact central CWA source.
    "tests/origin-evidence-workflow-original-hermetic.php",
    "tests/content-relationship-writeback-admission-original-hermetic.php",
    "tests/central-write-acceptance-permission-original-hermetic.php",
    "tests/central-transport-write-source-hermetic.php",
    # First-party P1 visual/content/interaction original no-write admission suites.
    "tests/content-completeness-original-hermetic.php",
    "tests/content-relationship-graph-site-original-hermetic.php",
    "tests/visual-seo-strategy-original-hermetic.php",
    "tests/adaptive-interaction-ui-original-hermetic.php",
    "tests/content-relationship-media-owner-original-hermetic.php",
    "tests/contentjob-structural-cwa-original-hermetic.php",
    # First-party Content Readiness isolated Ledger, exact original PHP runtime.
    "wp-content/plugins/mad4b-site-control-plane/tests/content-readiness/isolated-contract.php",
    # Exact first-party no-write admin, source rights and QA evidence fixtures.
    "tests/admin-navigation-manifest-original-hermetic.php",
    "tests/admin-surfaces-authorization-original-hermetic.php",
    "tests/content-media-rights-guard-original-hermetic.php",
    # First-party CCT public-credit postcommit only: no external WordPress state.
    "tests/media-rights-cct-after-commit-original-hermetic.php",
    # Original local WordPress image rights remain unknown, not fabricated.
    "tests/content-media-local-missing-rights-original-hermetic.php",
    "tests/developer-workspace-admission-original-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/content-intelligence-pipeline-contract.php",
    # Exact read-only destination term-meta link regression: isolated stubs,
    # no WordPress boot, HTTP, filesystem writes or arbitrary PHP execution.
    "wp-content/plugins/mad4b-site-control-plane/tests/brand-integrity-destination-term-links-runtime.php",


    # Original Scheduler and Queue behavior plus Skill Registry exact enrollment.
    "wp-content/plugins/mad4b-site-control-plane/tests/scheduler-admission-contract.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/scheduler-backlog-contract.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/remote-work-queue-cancellation-runtime.php",
    "tests/skill-registry-enrollment-original-hermetic.php",






    "wp-content/plugins/mad4b-site-control-plane/tests/undo-intent-fault-injection.php",


    # Hermetic native provider/add-on source fixture.
    "wp-content/plugins/mad4b-site-control-plane/tests/wordpress-addon-execution-guard-contract.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/runtime-obstacle-resolution-runtime.php",

    "wp-content/plugins/mad4b-site-control-plane/tests/provider-compatibility-engine-contract.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/provider-first-party-context-artifact-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/context-ai-agent-review-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/generation-snapshot-exception-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/no-input-read-ability-schema-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/operator-doctor-contract.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/oauth-secondary-diagnostic-exception-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/staging-mcp-skills-recertify-contract.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/developer-external-backend-discovery-contract.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/developer-runner-lifecycle-fetch-contract.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/runtime-backend-registry-contract.php",
    # Original OAuth subject remapping regression with fake site/client, no token validation or live grant.
    "wp-content/plugins/mad4b-site-control-plane/tests/oauth-multi-user-tenant-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/runtime-backend-deactivation-contract.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/runtime-runner-bridge-contract.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/performance-differential-diagnostic-contract.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/mcp-origin-error-preservation-runtime.php",
    "tests/remote-execution/operator-grants-convergence-smoke.php",
    "tests/remote-execution/operator-grant-rollout-smoke.php",
    "tests/remote-execution/operator-bridge-admission.php",
    "tests/remote-execution/operator-operation-policy-smoke.php",
    "tests/remote-execution/operator-oauth-session-smoke.php",
    "tests/remote-execution/operator-runner-profile-smoke.php",
    # QA32B: hermetic site-private RBAC fixtures with exit-on-failure assertions,
    # positive PASS marker and no WordPress, network, filesystem writes or secrets.
    "wp-content/plugins/mad4b-site-control-plane/tests/private-operations-behavioral-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/private-operations-isolated-php-contract.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/self-update-selected-head-release-trust-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/staging-source-selector-runtime.php",
    "tests/remote-execution/unified-operator-session-policy-smoke.php",
    # Original authority lifecycle code, fake PHP memory only. Exact reviewed
    # host-CAS fixtures: never permit arbitrary PHP CLI execution via wildcard.
    "tests/authority-auto-reconcile-host-hermetic.php",
    "tests/agent-registry-exact-revoke-cas-hermetic.php",
    # Original host actor and owner review inbox, no runtime site writes.
    "tests/execution-authority-identity-native-hermetic.php",
    "tests/authority-review-inbox-native-hermetic.php",
    # Original source-bound registry write preflight, PHP 7.4/8.5 tested.
    "tests/remote-execution/governed-write-admission-smoke.php",
    # Native signed Brand Draft Context receipt and quality preflight test;
    # no WordPress, network, or Production write in this exact fixture.
    "wp-content/plugins/mad4b-site-control-plane/tests/context-preflight-runtime.php",
    # Review-bound original host, MCP registration and runtime guards.
    # No network, site mutation or arbitrary CLI PHP file is accepted.
    "tests/servers-original-native-transport-hermetic.php",
    # Three first-party native read-only service contracts; hermetic fake
    # WordPress and temporary isolated plugin filesystem, no site writes.
    "tests/original-native-service-trust-hermetic.php",
    # Explicit original remote readback and content QA review tests.
    "tests/original-queue-and-review-modes-native.php",
    "tests/original-research-tour-workflow-native.php",
    "tests/global-runtime-lifecycle-original-hermetic.php",
    "tests/mutation-principal-first-party-hermetic.php",
    "tests/developer-policy-v2-original-hermetic.php",
    "tests/core-connector-vault-original-hermetic.php",
    "tests/dfsb-environment-policy-original-hermetic.php",
    "tests/mcp-adapter-dynamic-integrity-original-hermetic.php",
    "tests/source-lifecycle-original-hermetic.php",
    "tests/site-lifecycle-rest-original-hermetic.php",
    "tests/staging-context-fixtures-original-hermetic.php",
    "tests/staging-brand-trial-original-hermetic.php",
    "tests/staging-fixture-drafts-original-hermetic.php",
    "tests/readiness-draft-only-original-hermetic.php",
    "tests/readiness-scopes-events-original-hermetic.php",
    # Exact non-authorizing first-party provider/owner fixtures, no live WordPress.
    "tests/company-cloudflare-admission-original-hermetic.php",
    "tests/company-email-routing-original-hermetic.php",
    "tests/ai-task-routing-original-hermetic.php",
    "tests/delegation-owner-original-hermetic.php",
    # Exact original MCP transport and provider discovery read-only assertions.
    "tests/transport-context-permission-original-hermetic.php",
    "tests/provider-mcp-path-original-hermetic.php",

    "tests/content-relationship-terms-original-hermetic.php",
    # Newly reviewed first-party read-only Elementor, geo and migration fixtures.
    "tests/elementor-native-mcp-awareness-original-hermetic.php",
    "tests/geo-quality-original-hermetic.php",
    "tests/migration-decision-catalog-original-hermetic.php",
    "tests/hub-jetengine-consumer-fence-original-hermetic.php",
    # Native first-party source tests for vetted and dormant adapters only.
    "tests/local-brand-draft-adapter-original-hermetic.php",
    "tests/dfsb-read-adapter-original-hermetic.php",
    "tests/operator-runner-profile-original-hermetic.php",
    "tests/capability-dependency-planner-original-hermetic.php",
    "tests/switcher-sources-original-hermetic.php",
    # Exact original read-only source/CAS/transaction regression fixtures.
    "tests/operational-integrity-original-hermetic.php",
    "tests/dynamic-provider-manifest-original-hermetic.php",
    "tests/plugin-transaction-plan-original-hermetic.php",






    "tests/adaptive-asset-bridge-original-hermetic.php",

    "tests/staging-grant-agent-proof-native.php",
    "tests/server-registration-descriptor-boundary-hermetic.php",
    "tests/staging-write-delegated-identity-native-hermetic.php",
    "tests/staging-write-host-candidate-native-hermetic.php",
    "tests/runtime-convergence-owner-gate-native-hermetic.php",
    "tests/staging-host-candidate-gate-native-hermetic.php",
    "tests/native-capability-registry-adapter-first-party.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/deployment-binding-native-runtime.php",


    "wp-content/plugins/mad4b-site-control-plane/tests/original305-host-review-denial-runtime.php",
))



def ensure(ok, message):
    if not ok:
        raise ValueError(message)


# Only these two reviewed, self-contained Node vm/DOM source fixtures may
# execute as JS. No arbitrary scripts, browser network or WordPress admin.
STANDALONE_JS_REGRESSIONS = frozenset((
    "tests/journey-asset-bootstrap-runtime.js",
    "tests/operational-remediation-tabs-runtime.js",
))


# Genuine disposable WordPress tests, not bare PHP or forged PHPUnit results.
# These exact fixtures read source-registered Abilities and fail if assertions fail.
WORDPRESS_RUNTIME_REGRESSIONS = frozenset((
    # Actual disposable WordPress authenticated Admin smoke; never execute
    # as fake hermetic PHP or untrusted PHPUnit in the build runner.
    "wp-content/plugins/mad4b-site-control-plane/tests/admin-pages-wordpress.php",
    "tests/test_brand_integrity_lifecycle_runtime.php",
    "tests/test_brand_integrity_runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/runtime-firecrawl-unready-write-mount.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/runtime-local-oauth-chatgpt-http-smoke.php",
))
WP_CLI_PHAR = "/tmp/wp-cli.phar"
WP_SANDBOX = "/tmp/mad4b-readiness-wordpress"


def trusted_wordpress_readback(test, stdout):
    # This one authenticated smoke has a review-bound PASS envelope, not bare JSON.
    # Evaluate it before the generic JSON decoder; preserve every field/denial gate.
    if test == "wp-content/plugins/mad4b-site-control-plane/tests/runtime-local-oauth-chatgpt-http-smoke.php":
        prefix = "mad4b.site-control-plane.runtime-local-oauth-chatgpt-http.v7: PASS "
        if not isinstance(stdout, str) or not stdout.startswith(prefix):
            return False
        try:
            result = json.loads(stdout[len(prefix):].strip())
        except (ValueError, TypeError):
            return False
        expected = (
            "session_established", "bearer_identity_verified", "prior_site_uuid_aliases_denied",
            "registration_bearer_invariant", "preauth_internal_primitives_hidden",
            "read_bearer_step_up_hidden", "read_bearer_step_up_denied",
            "step_up_bearer_scope_verified", "step_up_bearer_projection_visible",
            "foreign_step_up_client_hidden", "foreign_step_up_client_denied",
            "false_exact_identity_fail_closed", "browser_read_dispatch_verified",
        )
        return (isinstance(result, dict) and all(result.get(k) is True for k in expected)
                and isinstance(result.get("tool_count"), int) and result["tool_count"] > 0
                and result.get("http_filter_calls") == 0
                and result.get("unpreempted_http_calls") == 0)
    try:
        payload = json.loads(stdout.strip())
    except (ValueError, TypeError):
        return False
    if not isinstance(payload, dict):
        return False
    if test == "tests/test_brand_integrity_lifecycle_runtime.php":
        return (payload.get("contract") == "mad4b.brand-integrity.lifecycle-qa.v1"
                and isinstance(payload.get("tests"), int) and payload["tests"] >= 20
                and payload.get("passed") == payload["tests"]
                and payload.get("failures") == [] and isinstance(payload.get("actual_post_id"), int)
                and payload["actual_post_id"] > 0)
    if test == "tests/test_brand_integrity_runtime.php":
        return (payload.get("contract") == "mad4b.brand-integrity.runtime-test.v1"
                and isinstance(payload.get("total"), int) and payload["total"] >= 20
                and payload.get("passed") == payload["total"]
                and payload.get("failures") == [])
    if test == "wp-content/plugins/mad4b-site-control-plane/tests/runtime-firecrawl-unready-write-mount.php":
        available = payload.get("adapter_available")
        mounted = payload.get("write_mounted")
        return (payload.get("contract") == "mad4b.firecrawl-native-unready-mount-regression.v1"
                and payload.get("ability_registered") is True
                and payload.get("authorizing") is False
                and payload.get("mutation_performed") is False
                and payload.get("external_provider_called") is False
                and isinstance(available, bool) and isinstance(mounted, bool)
                and payload.get("state") == ("ELIGIBILITY_PRESENT_NOT_EXECUTED"
                                             if available else "DORMANT_WRITE_NOT_MOUNTED")
                and (available or not mounted))
    return False


def command_for(test):
    path = (ROOT / test).resolve()
    ensure(path.is_relative_to(ROOT), "Test escapes checkout")
    ensure((test.startswith("tests/") or
            test.startswith("wp-content/plugins/mad4b-site-control-plane/tests/")) and
           ".." not in Path(test).parts and path.is_file(),
           "Untrusted or absent regression-test path " + test)
    if test in HOST_ROOT_HERMETIC_REGRESSIONS:
        ensure(not path.is_symlink(), "Privileged hermetic test symlink denied")
        if path.suffix == ".php":
            ensure(test in STANDALONE_PHP_REGRESSIONS,
                   "Unreviewed privileged PHP fixture denied")
            return ["/usr/bin/sudo", "-n", "--", "php", str(path)]
        ensure(test == "tests/authority-servers-original-review-suite.py"
               and path.suffix == ".py",
               "Unreviewed privileged Python suite denied")
        return ["/usr/bin/sudo", "-n", "--", sys.executable, str(path)]
    if path.suffix == ".py":
        return [sys.executable, str(path)]
    if path.suffix == ".php":
        if test in WORDPRESS_RUNTIME_REGRESSIONS:
            ensure(not path.is_symlink(), "WordPress runtime test symlink denied")
            return ["php", WP_CLI_PHAR, "eval-file", str(path),
                    "--path=" + WP_SANDBOX, "--user=mad4b-ci-admin"]
        if test == "wp-content/plugins/mad4b-site-control-plane/tests/adaptive-search-provider-enrollment-runtime.php":
            ensure(not path.is_symlink(), "Adaptive Search isolated test symlink denied")
            return ["php", str(path), "--readiness-pass"]
        if test == "wp-content/plugins/mad4b-site-control-plane/tests/mcp-official-required-preflight-runtime.php":
            ensure(not path.is_symlink(), "Official MCP test symlink denied")
            return ["php", str(path), "/tmp/mad4b-readiness-certified-mcp-adapter/mcp-adapter"]
        if test in STANDALONE_PHP_REGRESSIONS:
            ensure(not path.is_symlink(), "Standalone test symlink denied " + test)
            return ["php", str(path)]
        # Unreviewed PHP still requires a dedicated trusted PHPUnit runner.
        runner = ROOT / "vendor/bin/phpunit"
        if not runner.is_file():
            runner = ROOT / "wp-content/plugins/mad4b-site-control-plane/vendor/bin/phpunit"
        ensure(runner.is_file(), "No trusted PHPUnit runner for " + test)
        return ["php", str(runner), str(path)]
    if path.suffix == ".js":
        ensure(test in STANDALONE_JS_REGRESSIONS and not path.is_symlink(),
               "Unreviewed JS regression-test path " + test)
        return ["node", str(path)]
    raise ValueError("No supported behavioral test executor for " + test)


def has_assertion_pass(stdout):
    # The allowlisted *executable* fixture must exit zero and report at least
    # one assertion. Accept either positive PASS lines or a bounded terminal
    # counter; reject zero-count, failure and PHP lint-only output.
    if not isinstance(stdout, str) or not stdout:
        return False
    if re.search(r"(?m)^(?:FAIL(?::|\s)|[^\n]{3,130}: FAIL(?:\s|$))", stdout):
        return False
    if re.search(r"(?m)^PASS(?::|\s)", stdout):
        return True
    if re.search(r"(?m)^[A-Za-z][A-Za-z0-9 _./:-]{2,110}: PASS(?: [1-9][0-9]*(?: [A-Za-z][A-Za-z ]{0,30})?)?[ \t]*$", stdout):
        return True
    return bool(re.search(
        r"(?m)^SUMMARY (?:pass=[1-9][0-9]* fail=0|"
        r"checks=[1-9][0-9]* failures=0)[ \t]*$", stdout))


def run_tests(impact, head, timeout):
    ensure(SHA.fullmatch(head or "") and impact.get("exact_head_sha") == head,
           "Exact HEAD mismatch in regression test plan")
    ensure(impact.get("contract") == "mad4b.dynamic-readiness-capability-impact.v1"
           and impact.get("authorizing") is False and
           impact.get("production_authorized") is False,
           "Untrusted coverage execution request")
    tests = impact.get("required_regression_tests")
    ensure(isinstance(tests, list) and len(tests) == len(set(tests)),
           "Missing or duplicate execution plan")
    # Preflight every exact mapped runner before starting ANY test. This is
    # discovery only, not executable allowlisting: unknown PHP remains blocked.
    # Reporting all admission gaps together avoids repeated 85-job CI cycles.
    admission_gaps = []
    for name in tests:
        try:
            ensure(isinstance(name, str), "Regression path must be string")
            command_for(name)
        except (ValueError, OSError, TypeError) as error:
            admission_gaps.append((str(name), str(error)))
    if admission_gaps:
        detail = "; ".join(f"{name}: {reason}" for name, reason in admission_gaps[:40])
        raise ValueError(
            "Untrusted mapped regression runners ("
            + str(len(admission_gaps)) + "): " + detail
            + ("; additional admissions withheld" if len(admission_gaps) > 40 else "")
        )
    results = []
    for name in tests:
        ensure(isinstance(name, str), "Regression path must be string")
        cmd = command_for(name)
        try:
            child_env = os.environ.copy()
            if name == "wp-content/plugins/mad4b-site-control-plane/tests/runtime-local-oauth-chatgpt-http-smoke.php":
                # Materialize real OAuth/MCP tools only in this isolated process.
                child_env["MAD4B_SCP_MCP_CLI_REQUEST"] = "1"
            result = subprocess.run(cmd, cwd=ROOT, capture_output=True, text=True,
                                    timeout=timeout, check=False, env=child_env)
            ok = result.returncode == 0
            if name in WORDPRESS_RUNTIME_REGRESSIONS:
                ok = ok and trusted_wordpress_readback(name, result.stdout)
            if name in STANDALONE_PHP_REGRESSIONS or name in STANDALONE_JS_REGRESSIONS:
                # Require positive assertions, never accept exit(0) or lint-only
                # output. Existing original contracts also print N test cases
                # in an anchored success-only terminal summary.
                ok = ok and has_assertion_pass(result.stdout)
            tail = (result.stdout + "\n" + result.stderr)[-5000:]
            outcome = "PASS" if ok else "FAIL"
            code = result.returncode
        except (subprocess.TimeoutExpired, OSError) as exc:
            outcome = "FAIL"
            tail = str(exc)[-5000:]
            code = -1
        results.append({
            "path": name, "result": outcome, "return_code": code,
            "output_tail_sha256": hashlib.sha256(tail.encode()).hexdigest(),
            "reason": "" if outcome == "PASS" else tail[-500:],
        })
    done = all(x["result"] == "PASS" for x in results)
    return {
        "contract": "mad4b.readiness-executed-regressions.v1",
        "exact_head_sha": head, "source": "CURRENT_CI_SUBPROCESS_EXECUTION",
        "github_run_id": os.environ.get("GITHUB_RUN_ID"),
        "status": ("PASS_ALL_MAPPED_TESTS" if done else "FAIL_MAPPED_TESTS")
            if tests else "NO_NEW_MAPPED_TESTS",
        "required_count": len(tests), "passed_count": sum(
            x["result"] == "PASS" for x in results),
        "tests": results, "authorizing": False,
        "live_evidence_verified": False, "production_authorized": False,
    }


def self_test():
    cwv="wp-content/plugins/mad4b-site-control-plane/tests/cwv-site-admission-hermetic.php"
    assert command_for(cwv) == ["php", str((ROOT / cwv).resolve())]
    assert cwv in STANDALONE_PHP_REGRESSIONS
    assert has_assertion_pass("PASS: CWV_SITE_SCOPED_ADMISSION 17 legacy/tenant/security cases; no writes\n")
    assert not has_assertion_pass("FAIL: CWV_SITE_SCOPED_ADMISSION unauthorized policy\n")
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/original305-host-review-denial-runtime.php")[0] == "php"
    for test in WORDPRESS_RUNTIME_REGRESSIONS:
        assert command_for(test) == ["php", WP_CLI_PHAR, "eval-file",
                                      str((ROOT / test).resolve()),
                                      "--path=" + WP_SANDBOX, "--user=mad4b-ci-admin"]
        assert not trusted_wordpress_readback(test, "PASS: just a line")
        assert not trusted_wordpress_readback(test, "{}")
    assert trusted_wordpress_readback(
        "tests/test_brand_integrity_runtime.php",
        json.dumps({"contract": "mad4b.brand-integrity.runtime-test.v1",
                    "total": 29, "passed": 29, "failures": []}))
    assert not trusted_wordpress_readback(
        "tests/test_brand_integrity_runtime.php",
        json.dumps({"contract": "mad4b.brand-integrity.runtime-test.v1",
                    "total": 29, "passed": 28, "failures": [{}]}))
    oauth_path = "wp-content/plugins/mad4b-site-control-plane/tests/runtime-local-oauth-chatgpt-http-smoke.php"
    oauth_evidence = {
        "session_established": True,
        "bearer_identity_verified": True,
        "prior_site_uuid_aliases_denied": True,
        "registration_bearer_invariant": True,
        "preauth_internal_primitives_hidden": True,
        "read_bearer_step_up_hidden": True,
        "read_bearer_step_up_denied": True,
        "step_up_bearer_scope_verified": True,
        "step_up_bearer_projection_visible": True,
        "foreign_step_up_client_hidden": True,
        "foreign_step_up_client_denied": True,
        "false_exact_identity_fail_closed": True,
        "browser_read_dispatch_verified": True,
        "tool_count": 43,
        "http_filter_calls": 0,
        "unpreempted_http_calls": 0,
    }
    oauth_prefix = "mad4b.site-control-plane.runtime-local-oauth-chatgpt-http.v7: PASS "
    oauth_serialized = oauth_prefix + json.dumps(oauth_evidence)
    assert trusted_wordpress_readback(oauth_path, oauth_serialized)
    assert not trusted_wordpress_readback(oauth_path, json.dumps(oauth_evidence))
    assert not trusted_wordpress_readback(oauth_path, oauth_serialized + "\\nUNEXPECTED_OUTPUT")
    for invalid_field, invalid_value in (
        ("tool_count", 0),
        ("read_bearer_step_up_denied", False),
        ("foreign_step_up_client_hidden", False),
        ("unpreempted_http_calls", 1),
    ):
        altered = dict(oauth_evidence)
        altered[invalid_field] = invalid_value
        assert not trusted_wordpress_readback(oauth_path, oauth_prefix + json.dumps(altered))
    assert has_assertion_pass("PASS: verified real case\n")
    assert has_assertion_pass("SUMMARY pass=8 fail=0\n")
    assert has_assertion_pass("SUMMARY checks=21 failures=0\n")
    assert has_assertion_pass("BROWSER_OPERATOR_INTEGRITY: PASS 15\n")
    assert has_assertion_pass("Context admin recovery render/handler runtime: PASS\n")
    assert has_assertion_pass("PLUGIN_LIFECYCLE_PREFLIGHT: PASS 9 added checks\n")
    assert not has_assertion_pass("PASS: first success\nFAIL: second\n")
    assert not has_assertion_pass("SUMMARY pass=0 fail=0\n")
    assert not has_assertion_pass("SUMMARY pass=8 fail=1\n")
    assert not has_assertion_pass("No syntax errors detected\n")

    for path in (
        "wp-content/plugins/mad4b-site-control-plane/tests/private-operations-behavioral-runtime.php",
        "wp-content/plugins/mad4b-site-control-plane/tests/private-operations-isolated-php-contract.php",
        "wp-content/plugins/mad4b-site-control-plane/tests/self-update-selected-head-release-trust-runtime.php",
    ):
        assert command_for(path) == ["php", str((ROOT / path).resolve())]
    # Exact reviewed standalone contracts are executable only when their
    # source-owned assertions are registered; synthetic runtime-wide WPML
    # providers and unknown WordPress PHP fixtures remain denied.
    for reviewed in (
        "wp-content/plugins/mad4b-site-control-plane/tests/chatgpt-file-transfer-permission-runtime.php",
        "wp-content/plugins/mad4b-site-control-plane/tests/dynamic-company-environment-provider-parity-runtime.php",
        "wp-content/plugins/mad4b-site-control-plane/tests/optional-governance-runtime.php",
        "wp-content/plugins/mad4b-site-control-plane/tests/staging-write-grant-reviewed-inventory-runtime.php",
    ):
        assert command_for(reviewed) == ["php", str((ROOT / reviewed).resolve())]
    assert ("wp-content/plugins/mad4b-site-control-plane/tests/runtime-dynamic-skills-wpml-readonly-fixture.php"
            not in STANDALONE_PHP_REGRESSIONS)
    for rejected in (
        "wp-content/plugins/mad4b-site-control-plane/tests/runtime-dynamic-skills-wpml-readonly-fixture.php",
        "wp-content/plugins/mad4b-site-control-plane/tests/unknown-arbitrary-php.php",
    ):
        assert rejected not in STANDALONE_PHP_REGRESSIONS
    for reviewed in (
        "tests/operation-registry-native-policy-boundary-hermetic.php",
        "tests/presentation-browser-evidence-local-import-hermetic.php",
        "tests/presentation-integrity-jetengine-source-hermetic.php",
    ):
        assert command_for(reviewed) == ["php", str((ROOT / reviewed).resolve())]
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/mcp-official-required-preflight-runtime.php") == [
        "php", str((ROOT / "wp-content/plugins/mad4b-site-control-plane/tests/mcp-official-required-preflight-runtime.php").resolve()), "/tmp/mad4b-readiness-certified-mcp-adapter/mcp-adapter"]
    assert command_for("tests/brand-quality-readiness-coverage-hermetic.php") == [
        "php", str((ROOT / "tests/brand-quality-readiness-coverage-hermetic.php").resolve())]
    assert command_for("tests/media-rights/media-rights-source-convergence-hermetic.php") == [
        "php", str((ROOT / "tests/media-rights/media-rights-source-convergence-hermetic.php").resolve())]
    assert command_for("tests/catalog-intent-source-relevance-hermetic.php") == [
        "php", str((ROOT / "tests/catalog-intent-source-relevance-hermetic.php").resolve())]
    assert command_for("tests/central-company-release-signed-readonly-hermetic.php") == [
        "php", str((ROOT / "tests/central-company-release-signed-readonly-hermetic.php").resolve())]
    assert command_for("tests/chatbotx-native-adapter-surface-hermetic.php") == [
        "php", str((ROOT / "tests/chatbotx-native-adapter-surface-hermetic.php").resolve())]
    assert command_for("tests/company-code-cryptographic-pages-hermetic.php") == [
        "php", str((ROOT / "tests/company-code-cryptographic-pages-hermetic.php").resolve())]
    assert command_for("tests/company-communication-policy-rest-hermetic.php") == [
        "php", str((ROOT / "tests/company-communication-policy-rest-hermetic.php").resolve())]
    assert command_for("tests/company-master-native-rest-gates-hermetic.php") == [
        "php", str((ROOT / "tests/company-master-native-rest-gates-hermetic.php").resolve())]
    assert command_for("tests/company-migration-decisions-transaction-hermetic.php") == [
        "php", str((ROOT / "tests/company-migration-decisions-transaction-hermetic.php").resolve())]
    assert command_for("tests/company-reconcile-skill-set-no-executor-hermetic.php") == [
        "php", str((ROOT / "tests/company-reconcile-skill-set-no-executor-hermetic.php").resolve())]
    assert command_for("tests/native-cli-context-and-company-inventory-hermetic.php") == [
        "php", str((ROOT / "tests/native-cli-context-and-company-inventory-hermetic.php").resolve())]
    for reviewed_source in (
        "tests/converged-grant-eligibility-hermetic.php",
        "tests/core-ai-governance-usage-and-admission-hermetic.php",
        "tests/core-connector-vault-original-hermetic.php",
        "tests/delegation-owner-original-hermetic.php",
        "tests/developer-policy-v2-original-hermetic.php",
        "tests/developer-workspace-admission-original-hermetic.php",
        "tests/dfsb-environment-policy-original-hermetic.php",
        "tests/dfsb-read-adapter-original-hermetic.php",
        "tests/drive-scan-cron-original-hermetic.php",
        "tests/dynamic-provider-manifest-original-hermetic.php",
        "tests/elementor-native-mcp-awareness-original-hermetic.php",
        "tests/execution-authority-identity-native-hermetic.php",
        "tests/fcp-local-observability-site-bound-hermetic.php",
        "tests/firecrawl-page-store-original-hermetic.php",
        "tests/firecrawl-provider-php74-crawl-and-host-scope-hermetic.php",
        "tests/firecrawl-research-adapter-provider-presence-hermetic.php",
        "tests/frontend-impact-same-origin-budget-hermetic.php",
        "tests/geo-quality-original-hermetic.php",
        "tests/global-runtime-lifecycle-original-hermetic.php",
        "tests/guided-continuation-write-surface-denial-hermetic.php",
    ):
        expected = (["/usr/bin/sudo", "-n", "--", "php"]
                    if reviewed_source in HOST_ROOT_HERMETIC_REGRESSIONS
                    else ["php"])
        assert command_for(reviewed_source) == expected + [
            str((ROOT / reviewed_source).resolve())]
    for exact_host_and_provider in (
        "tests/host-node-inventory-signed-readback-hermetic.php",
        "tests/hub-jetengine-consumer-fence-original-hermetic.php",
        "tests/litespeed-adapter-original-hermetic.php",
        "tests/local-brand-draft-adapter-original-hermetic.php",
        "tests/locale-experience-alerts-owned-observations-hermetic.php",
        "tests/mcp-adapter-dynamic-integrity-original-hermetic.php",
        "tests/mcp-protocol-scope-original-hermetic.php",
        "tests/mcp-provider-matrix-readonly-inheritance-hermetic.php",
    ):
        assert command_for(exact_host_and_provider) == ["php", str((ROOT / exact_host_and_provider).resolve())]
    for reviewed in (
        "tests/company-task-handoff-native-cas-hermetic.php",
        "tests/company-tenant-migration-rollback-hermetic.php",
        "tests/company-user-native-lifecycle-hermetic.php",
        "tests/content-completeness-original-hermetic.php",
        "tests/content-job-groups-admission-original-hermetic.php",
        "tests/content-job-register-native-hermetic.php",
        "tests/content-job-structural-qa-admission-original-hermetic.php",
        "tests/content-media-local-missing-rights-original-hermetic.php",
        "tests/content-media-rights-guard-original-hermetic.php",
    ):
        assert command_for(reviewed) == ["php", str((ROOT / reviewed).resolve())]
    for js in STANDALONE_JS_REGRESSIONS:
        assert command_for(js) == ["node", str((ROOT / js).resolve())]
    for exact_root in sorted(HOST_ROOT_HERMETIC_REGRESSIONS):
        root_cmd = command_for(exact_root)
        assert root_cmd[:3] == ["/usr/bin/sudo", "-n", "--"]
        assert root_cmd[-1] == str((ROOT / exact_root).resolve())
        assert root_cmd[3] == (sys.executable if exact_root.endswith(".py") else "php")
    assert len(HOST_ROOT_HERMETIC_REGRESSIONS) == 3
    assert command_for("tests/server-descriptor-canonical-multilane-hermetic.php") == [
        "php", str((ROOT / "tests/server-descriptor-canonical-multilane-hermetic.php").resolve())]
    assert has_assertion_pass("PASS: brand quality exact context revision and same-site issue closure\n")
    # No wildcard admission: unknown PHP still requires a trusted PHPUnit runner.
    try:
        command_for("wp-content/plugins/mad4b-site-control-plane/tests/private-operations-foreign-smoke.php")
    except ValueError:
        pass
    else:
        raise AssertionError("Unreviewed operations PHP fixture admitted")
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/staging-source-selector-runtime.php")[0] == "php"
    assert command_for("tests/production_readiness_registry_contract.py")[0] == sys.executable
    assert command_for("tests/remote-execution/operator-grants-convergence-smoke.php")[0] == "php"
    assert command_for("tests/remote-execution/governed-write-admission-smoke.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/context-preflight-runtime.php")[0] == "php"
    assert command_for("tests/servers-original-native-transport-hermetic.php")[0] == "php"
    assert command_for("tests/original-native-service-trust-hermetic.php")[0] == "php"
    assert command_for("tests/server-registration-descriptor-boundary-hermetic.php")[0] == "php"
    assert command_for("tests/staging-write-delegated-identity-native-hermetic.php")[0] == "php"
    assert command_for("tests/staging-write-host-candidate-native-hermetic.php")[0] == "php"
    assert command_for("tests/runtime-convergence-owner-gate-native-hermetic.php")[0] == "php"
    assert command_for("tests/staging-host-candidate-gate-native-hermetic.php")[0] == "php"
    assert command_for("tests/native-release-and-staging-canary-denial-hermetic.php")[0] == "php"
    assert command_for("tests/native-search-console-site-scope-php74-hermetic.php")[0] == "php"
    assert command_for("tests/native-skills-seeder-enrollment-cron-hermetic.php")[0] == "php"
    assert command_for("tests/native-tour-highlights/acceptance.php")[0] == "php"
    assert command_for("tests/native-capability-registry-adapter-first-party.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/deployment-binding-native-runtime.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/generation-snapshot-exception-runtime.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/adaptive-interaction-broker/contract-test.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/no-input-read-ability-schema-runtime.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/operator-doctor-contract.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/oauth-secondary-diagnostic-exception-runtime.php")[0] == "php"
    assert command_for("tests/remote-execution/operator-bridge-admission.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/ai-domain-executors-runtime.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/ai-native-owner-receipts-runtime.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/ai-only-local-early-auth-runtime.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/ai-grant-mode-runtime.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/ai-model-routing-worker-runtime.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/ai-review-worker-guard-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/ai-review-crossenv-scope-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/ai-review-credential-expiry-hermetic.php")[0] == "php"
    for path in (
        "tests/switchers/test-market-compatibility.php",
        "tests/switchers/test-market-context-boundaries.php",
        "wp-content/plugins/mad4b-site-control-plane/tests/market-bootstrap-compatibility.php",
    ):
        assert command_for(path) == ["php", str((ROOT / path).resolve())]
    assert not has_assertion_pass("UNREVIEWED_TEST=PASS\n")
    for standalone in (
        "external-skill-source-provider-runtime.php",
        "skill-task-abilities-runtime.php",
        "skill-task-composition-runtime.php",
        "egress-policy-runtime.php",
    ):
        exact = "wp-content/plugins/mad4b-site-control-plane/tests/" + standalone
        assert command_for(exact) == ["php", str((ROOT / exact).resolve())]
    # Combined source PRs keep every formerly reviewed hermetic path exact.
    for exact in (
        "wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-live-catalog-read-runtime.php",
        "wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-live-skills-list-paging-runtime.php",
        "wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-live-source-skills-parity-runtime.php",
        "wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-cimd-client-security-runtime.php",
        "wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-native-diagnostics-read-parity-runtime.php",
        "wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-switcher-flag-scheme-runtime.php",
        "wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-wpml-site-origin-runtime.php",
        "wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-wpml-site-origin-rest-runtime.php",
        "wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-wpml-site-origin-cli-runtime.php",
        "wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-signed-host-agent-negative-runtime.php",
        "wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-signed-host-context-review-runtime.php",
        "wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-cli-context-identity-runtime.php",
    ):
        assert command_for(exact) == ["php", str((ROOT / exact).resolve())]
    # Exact hermetic native company notice comparison, not a generic PHP runner.
    assert command_for("tests/company-email-native-notice-preview-hermetic.php") == [
        "php", str((ROOT / "tests/company-email-native-notice-preview-hermetic.php").resolve())
    ]
    # Original review remains intact; only the new exact Context preview
    # fixture is executable as isolated PHP. No generic PHP wildcard.
    exact="wp-content/plugins/mad4b-site-control-plane/tests/context-classification-readonly-preview-runtime.php"
    assert command_for(exact)==["php",str((ROOT/exact).resolve())]
    # Exact PR942 signed-bearer fixtures: standalone source-only regression
    # executors, not PHPUnit or WordPress runtime jobs.
    for exact in (
        "wp-content/plugins/mad4b-site-control-plane/tests/cimd-scoped-bearer-identity-runtime.php",
        "wp-content/plugins/mad4b-site-control-plane/tests/chatgpt-auto-binding-runtime.php",
        "wp-content/plugins/mad4b-site-control-plane/tests/reconnect-hardening-runtime.php",
    ):
        assert command_for(exact) == ["php", str((ROOT / exact).resolve())]
    # Exact-path only; no ambient PHP test runner or generic allowlist wildcard.
    for exact in (
        "wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-readonly-native-settings-locator-runtime.php",
        "wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-readonly-provider-installed-artifact-runtime.php",
        "wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-readonly-unified-gate-funnel-runtime.php",
        "wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-readonly-global-runtime-lifecycle-runtime.php",
    ):
        assert command_for(exact) == ["php", str((ROOT / exact).resolve())]
    # Four exact hermetic fixtures, no wildcard permission to run arbitrary PHP.
    for exact in (
        "wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-context-local-document-normalizer-runtime.php",
        "wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-provider-field-read-parity-runtime.php",
        "wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-visual-seo-strategy-runtime.php",
        "wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-local-transport-boundary-runtime.php",
    ):
        assert command_for(exact) == ["php", str((ROOT / exact).resolve())]
    # Eight source-only quality dependencies with explicit per-company CPT gate.
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-content-quality-dependencies-source-only-runtime.php") == ["php", str((ROOT / "wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-content-quality-dependencies-source-only-runtime.php").resolve())]
    # Original unactivated Hub/source lifecycle negative runtime.
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-hub-lifecycle-source-only-runtime.php") == ["php", str((ROOT / "wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-hub-lifecycle-source-only-runtime.php").resolve())]
    # Parent/preparation/event classes are source-only, no mail or hooks.
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-readiness-preactivation-source-only-runtime.php") == ["php", str((ROOT / "wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-readiness-preactivation-source-only-runtime.php").resolve())]
    # Explicit no-network scheduler source and company role fixture.
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-scheduler-source-closure-runtime.php") == ["php", str((ROOT / "wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-scheduler-source-closure-runtime.php").resolve())]
    # The six-source SHA fixture does not boot WordPress or grant authority.
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-authority-review-source-only-runtime.php") == ["php", str((ROOT / "wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-authority-review-source-only-runtime.php").resolve())]
    # Exact background agent / host authority negative fixture, no server work.
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-agent-authority-source-only-runtime.php") == ["php", str((ROOT / "wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-agent-authority-source-only-runtime.php").resolve())]
    # New source parity fixtures are bounded, non-mutating PHP scripts.
    for exact in (
        "wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-unactivated-source-parity-runtime.php",
        "wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-chatbotx-site-neutral-runtime.php",
    ):
        assert command_for(exact) == ["php", str((ROOT / exact).resolve())]
    # Only these explicitly reviewed GET-only original provider read fixtures.
    for exact in (
        "wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-fcp-local-observability-runtime.php",
        "wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-fcp-queue-readback-runtime.php",
    ):
        assert command_for(exact) == ["php", str((ROOT / exact).resolve())]
    # WordPress-integrated smoke cannot be silently run as bare PHPUnit or CLI.
    try:
        command_for("wp-content/plugins/mad4b-site-control-plane/tests/runtime-dynamic-skills-smoke.php")
    except ValueError:
        pass
    else:
        raise AssertionError("WordPress runtime smoke was incorrectly admitted as standalone")
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/market-source-read-abilities-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/market-source-calendar-cron-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/market-source-calendar-authority-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/provider-setup-coverage-runtime.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/authority-setup-fields-runtime.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/background-authorization-verified-identity-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/unified-policy-gate-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/unified-queue-extension-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/unified-workflow-inspect-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/context-pipeline-admission-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/oauth-preflight-denial-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/passwordless-mcp-identity-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/cross-plugin-lifecycle-admission-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/browser-operator-integrity-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/plugin-lifecycle-preflight-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/local-client-request-boundary-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/context-admin-integrity-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/google-revocation-boundary-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/oauth-resource-signature-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/unified-admin-rest-permissions-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/media-inventory-authorization-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/ai-source-repair-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/unified-approval-ai-first/local-rest-fastpath-contract.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/public-holiday-hermetic-runtime.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/application-password-agent-bridge-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/ai-review-lifecycle-runtime.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/ai-review-lifecycle-media-evidence-runtime.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/ai-review-lifecycle-history-runtime.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/provider-connections-admin-read-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/context-ai-agent-review-runtime.php")[0] == "php"
    try:
        command_for("wp-content/plugins/mad4b-site-control-plane/tests/runtime-gateway-cookie-http.php")
    except ValueError:
        pass
    else:
        raise AssertionError("WP-authenticated HTTP fixture must not run as bare standalone PHP")
    # Explicit, source-owned, isolated MCP lane fixture only. Do not admit
    # the full WordPress mixed-class HTTP fixture as bare PHP or PHPUnit.
    isolated = "wp-content/plugins/mad4b-site-control-plane/tests/server-capability-lane-isolation.php"
    assert command_for(isolated) == ["php", str((ROOT / isolated).resolve())]
    try:
        command_for("wp-content/plugins/mad4b-site-control-plane/tests/runtime-mcp-mixed-class-first-request.php")
    except ValueError:
        pass
    else:
        raise AssertionError("Mixed-class runtime test gained untrusted standalone execution")
    assert has_assertion_pass("PASS: exact MCP descriptor routing and quarantine 19 positive-negative checks\n")
    assert has_assertion_pass("QA31 Live Write Provider Snapshot: PASS\n")
    assert has_assertion_pass("PASS: native plugin list retains MAD4B sidebar without heavy lifecycle\n")
    for path in ("README.md", "../secrets.py", "tests/does-not-exist.py"):
        try:
            command_for(path)
        except ValueError:
            pass
        else:
            raise AssertionError("Unsafe or missing test accepted: " + path)
    head = "a" * 40
    plan = {
        "contract": "mad4b.dynamic-readiness-capability-impact.v1",
        "exact_head_sha": head, "authorizing": False,
        "production_authorized": False, "required_regression_tests": [],
    }
    record = run_tests(plan, head, 15)
    assert record["status"] == "NO_NEW_MAPPED_TESTS"
    assert record["production_authorized"] is False
    plan["required_regression_tests"] = ["tests/not-exist.py"]
    try:
        run_tests(plan, head, 15)
    except ValueError:
        pass
    else:
        raise AssertionError("Nonexecuted file reported as pass")
    print("READINESS_EXECUTED_REGRESSIONS_SELF_TEST: PASS")


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("command", choices=("self-test", "evaluate"))
    parser.add_argument("--head-sha", default="")
    parser.add_argument("--impact", default="artifacts/production-readiness/capability-impact.json")
    parser.add_argument("--out", default="artifacts/production-readiness")
    parser.add_argument("--timeout-seconds", type=int, default=120)
    args = parser.parse_args()
    try:
        if args.command == "self-test":
            self_test()
            return 0
        ensure(1 <= args.timeout_seconds <= 300, "Bounded test timeout needed")
        plan = json.loads(Path(args.impact).read_text(encoding="utf-8"))
        report = run_tests(plan, args.head_sha, args.timeout_seconds)
        target = Path(args.out)
        target.mkdir(parents=True, exist_ok=True)
        (target / "executed-regressions.json").write_text(
            json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        print("READINESS_EXECUTED_REGRESSIONS:", report["status"],
              report["passed_count"], "/", report["required_count"])
        return 0 if report["status"] != "FAIL_MAPPED_TESTS" else 1
    except (ValueError, OSError, KeyError, TypeError) as exc:
        print("READINESS_EXECUTED_REGRESSIONS_FAIL_CLOSED:", exc, file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
