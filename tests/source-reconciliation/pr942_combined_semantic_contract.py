#!/usr/bin/env python3
"""Exact-source collision review gate; technical evidence only, never signoff."""
import hashlib
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SUPPLEMENT = ROOT / 'docs/source-reconciliation/pr942-conflict-provenance-supplement-20261120.json'
P = 'wp-content/plugins/mad4b-site-control-plane/'

# Reviewed both 882/920 parent behaviors and the PR942 composite, plus later 964.
# These are semantic expectations, not production approvals.
REVIEW = {
    '.github/workflows/country-nationality-switchers.yml': ('browser-compat', 'Actual Playwright image and browser assertions remain'),
    '.github/workflows/mad4b-local-oauth-standalone.yml': ('oauth-browser', 'Pinned Chromium install does not remove browser test'),
    'governance/production-readiness/capability-coverage.json': ('governance', 'Review entries remain CI only, not authorizing'),
    'specs/007-content-intelligence-workflow-platform/post-merge-capability-fabric-closure.json': ('traceability', 'Historical baseline remains non-authorizing'),
    'tools/readiness_test_execution.py': ('execution-admission', 'Exact standalone CLI fixtures; no wildcard PHP execution'),
    P+'includes/class-mad4b-scp-provider-field-intelligence.php': ('tenant-isolation', 'Site identity precedes CPT/taxonomy discovery'),
    P+'includes/class-mad4b-scp-skill-registry.php': ('catalog-identity', 'Enrolled exact site and explicit Skills opt-in'),
    P+'includes/class-mad4b-scp-skills-admin-ui.php': ('admin-boundary', 'Company operations read separate from administrator Skills editor and source writes'),
    P+'mad4b-site-control-plane.php': ('activation', 'Local Ollama remains unloaded pending separate activation'),
    P+'tests/integration-pr415-pr416-pr420-pr421-contract.py': ('qa31', 'Exact Admin successor negative behavior is exercised'),
    P+'tests/site-neutrality-policy.json': ('site-neutrality', 'Generic field intelligence no longer defaults to tourism CPT'),
}


def check(require, message):
    if not require:
        raise AssertionError(message)


def current_blob(path):
    raw = (ROOT / path).read_bytes()
    return hashlib.sha1(b'blob ' + str(len(raw)).encode('ascii') + b'\0' + raw).hexdigest()


# The original PR942/964 conflict ledger is immutable. A later exact review-only
# Governance manifest successor can be admitted only by its fixed Git blob and
# explicit non-authorizing security metadata. Unknown future bytes stay denied.
GOVERNANCE_REVIEWED_SUCCESSOR = '0f4930fe976ff604f30c600c044812fdb7de3fa7'
GOVERNANCE_CWV_REVIEWED_SUCCESSOR = '18df447206263ee9bd79772068376dc24ebf9400'
CWV_REVIEWED_BOOTSTRAP = '97887a449a3abf473a043d2da0737a3c2f2ab7ae'
CWV_REVIEWED_HELPER = '366b5b085976f87a633aa4a7737596eea14dcd6b'

GOVERNANCE_PATH = 'governance/production-readiness/capability-coverage.json'


def require_cwv_review(coverage):
    identifier='service:'+P+'includes/class-mad4b-scp-cwv-site-admission.php'
    capabilities=[r for r in coverage.get('reviewed_capabilities',[])
        if r.get('id')==identifier and r.get('status')=='reviewed_for_ci_only'
        and r.get('production_authorized') is False
        and r.get('live_evidence_required') is True
        and 'wp-content/plugins/mad4b-site-control-plane/tests/cwv-site-admission-hermetic.php'
            in r.get('regression_tests',[])]
    check(len(capabilities)==1,'CWV Service capability source review absent or authorizing')
    for path, source in (
        (P+'mad4b-site-control-plane.php', CWV_REVIEWED_BOOTSTRAP),
        (P+'includes/class-mad4b-scp-cwv-site-admission.php', CWV_REVIEWED_HELPER),
    ):
        matches = [entry for entry in coverage.get('reviewed_changes', [])
                   if entry.get('path') == path
                   and entry.get('source_blob_sha') == source
                   and entry.get('status') == 'reviewed_for_ci_only'
                   and entry.get('production_authorized') is False
                   and entry.get('live_evidence_required') is True
                   and 'wp-content/plugins/mad4b-site-control-plane/tests/cwv-site-admission-hermetic.php'
                       in entry.get('regression_tests', [])]
        check(len(matches) == 1, 'CWV exact-source CI-only provenance missing: '+path)


def validate_governance_successor(coverage):
    check(coverage.get('authorizing') is False
          and coverage.get('production_promotion_from_ci') is False,
          'New Governance source cannot authorize Production')
    for basename, sha, fixture in (
        ('class-mad4b-scp-reconnect-hardening.php',
         '388408d37737ee2bacb2b97614be3fa375b334b8',
         'wp-content/plugins/mad4b-site-control-plane/tests/reconnect-hardening-runtime.php'),
        ('class-mad4b-scp-artifacts.php',
         '60750fb4a0cf8180f012254acd3fc2ccc43bba78',
         'tests/artifacts-original-hermetic.php'),
    ):
        rows = [r for r in coverage.get('reviewed_changes', [])
                if r.get('path', '').endswith('/' + basename)
                and r.get('source_blob_sha') == sha
                and fixture in r.get('regression_tests', [])
                and r.get('status') == 'reviewed_for_ci_only'
                and r.get('live_evidence_required') is True
                and r.get('production_authorized') is False]
        check(len(rows) == 1, 'Governance exact reviewed successor missing: ' + basename)


# Original 57-path semantic ledger stays frozen. An explicitly reviewed
# test-only runner successor permits exactly one new hermetic PHP entry.
RUNNER_CWV_REVIEWED_SUCCESSOR = '0d7af9a1ebe0b3503cc1ff581c8344805c0b8981'
RUNNER_CWV_ALLOWED_BLOCK = ("    # Explicit per-company CWV admission fixture: 17 positive and negative\n"
    "    # source-only cases, synthetic Site Profile/options/schedule, with no real\n"
    "    # WordPress, Cron writes, database, browser or external network access.\n"
    "    # The bounded PHP runner checks the PASS marker after actual execution.\n"
    '    "wp-content/plugins/mad4b-site-control-plane/tests/cwv-site-admission-hermetic.php",\n')
RUNNER_CWV_SELFTEST_BLOCK = (
    '    cwv="wp-content/plugins/mad4b-site-control-plane/tests/cwv-site-admission-hermetic.php"\n'
    '    assert command_for(cwv) == ["php", str((ROOT / cwv).resolve())]\n'
    '    assert cwv in STANDALONE_PHP_REGRESSIONS\n'
    '    assert has_assertion_pass("PASS: CWV_SITE_SCOPED_ADMISSION 17 legacy/tenant/security cases; no writes\\n")\n'
    '    assert not has_assertion_pass("FAIL: CWV_SITE_SCOPED_ADMISSION unauthorized policy\\n")\n')


def require_exact_cwv_runner_successor(content, preceding_blob):
    check(isinstance(content,str),'Runner source missing')
    check(content.count(RUNNER_CWV_ALLOWED_BLOCK)==1,
          'Unreviewed standalone PHP allowlist expansion')
    check(content.count(RUNNER_CWV_SELFTEST_BLOCK)==1,
          'Missing or different CWV test execution/denial contract')
    original=content.replace(RUNNER_CWV_ALLOWED_BLOCK,'',1).replace(RUNNER_CWV_SELFTEST_BLOCK,'',1)
    identity=lambda value:hashlib.sha1(
        b'blob '+str(len(value.encode('utf-8'))).encode('ascii')+b'\0'+value.encode('utf-8')).hexdigest()
    check(identity(content)==RUNNER_CWV_REVIEWED_SUCCESSOR,'Unreviewed runner source SHA')
    check(identity(original)==preceding_blob,'Runner successor changes unrelated code')


def validate(supplement, source_text):
    check(supplement['release_authorized'] is False, 'Unexpected release approval')
    check(supplement['semantic_conflict_review_completed'] is False, 'Unexpected semantic approval')
    collision_rows = [r for r in supplement['rows'] if r['resolution_by_blob'] == 'combined_or_revised']
    check(len(collision_rows) == 11, 'Expected 11 combined source resolutions')
    check({r['path'] for r in collision_rows} == set(REVIEW), 'Combined path inventory drift')
    for r in collision_rows:
        path = r['path']
        check(r['semantic_review_completed'] is False, 'Unexpected functional signoff: ' + path)
        current = current_blob(path)
        if path == GOVERNANCE_PATH and current != r['followup_964']:
            check(current in (GOVERNANCE_REVIEWED_SUCCESSOR, GOVERNANCE_CWV_REVIEWED_SUCCESSOR),
                  'Unreviewed Governance successor SHA: ' + current)
            coverage = json.loads(source_text(path))
            validate_governance_successor(coverage)
            if current == GOVERNANCE_CWV_REVIEWED_SUCCESSOR:
                require_cwv_review(coverage)
        elif path == 'tools/readiness_test_execution.py' and current != r['followup_964']:
            check(current == RUNNER_CWV_REVIEWED_SUCCESSOR,
                  'Unreviewed CI runner successor SHA: ' + current)
            require_cwv_review(json.loads(source_text(GOVERNANCE_PATH)))
            require_exact_cwv_runner_successor(source_text(path),r['followup_964'])
        elif path == P+'mad4b-site-control-plane.php' and current != r['followup_964']:
            check(current == CWV_REVIEWED_BOOTSTRAP,
                  'Unreviewed Bootstrap successor SHA: ' + current)
            check(current_blob(P+'includes/class-mad4b-scp-cwv-site-admission.php')
                  == CWV_REVIEWED_HELPER, 'Unreviewed CWV helper source SHA')
            require_cwv_review(json.loads(source_text(GOVERNANCE_PATH)))
            bootstrap=source_text(path)
            controlled=("if ( MAD4B_SCP_CWV_Site_Admission::admitted() ) {\n"
                        "    require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-core-web-vitals.php';\n}")
            check(bootstrap.count(controlled)==1,
                  'CWV activation does not have one bounded per-site guard')
        else:
            check(r['followup_964'] == current, 'Unreviewed code drift: ' + path)
    field = source_text(P+'includes/class-mad4b-scp-provider-field-intelligence.php')
    discover = field.split('public static function discover($input=[])', 1)[1].split('public static function ', 1)[0]
    check(discover.index('MAD4B_SCP_Site_Profile::status()') < discover.index('post_type_exists($subtype)'), 'Identity must precede type probing')
    check("$subtype=(string)($input['subtype']??'post')" in discover, 'Generic discovery cannot default to tours')
    check('deployment_binding_match' in discover and 'environment_match' in discover, 'Site binding must be exact')
    locale = field.split('public static function locale_media_audit($post_id):array', 1)[1].split('public static function ', 1)[0]
    check(locale.index('MAD4B_SCP_Site_Profile::status()') < locale.index('get_post($post_id)'), 'Media post lookup before site verification')
    reg = source_text(P+'includes/class-mad4b-scp-skill-registry.php')
    cat = reg.split('public static function catalog_enabled()', 1)[1].split('public static function ', 1)[0]
    for required in ('origin_enrolled()', 'site_urls_match_enrollment()', 'skills_enabled()'):
        check(required in cat, 'Missing mandatory Skill enrollment/opt-in gate ' + required)
    ui = source_text(P+'includes/class-mad4b-scp-skills-admin-ui.php')
    op = ui.split('public static function operations_snapshot()', 1)[1].split('public static function ', 1)[0]
    check(op.index("current_user_can( 'mad4b_manage_company_operations' )") < op.index('MAD4B_SCP_Skill_Registry::list_skills()'), 'Company capability guard must precede reads')
    check("current_user_can( 'manage_options' )" in ui, 'Administrator Skills authoring guard missing')
    check("SCP_External_Skill_Sources::handle_admin( 'add', $_POST )" in ui, 'Admin source-add dispatch lost')
    check("SCP_External_Skill_Sources::handle_admin( 'remove', $_POST )" in ui, 'Admin source-remove dispatch lost')
    external = source_text(P+'includes/class-external-skill-sources.php')
    handler = external.split('public static function handle_admin(', 1)[1].split('public static function ', 1)[0]
    check('check_admin_referer(' in handler and "current_user_can( 'manage_options' )" in handler, 'External source mutation must retain nonce and admin cap')
    bootstrap = source_text(P+'mad4b-site-control-plane.php')
    check("require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-local-ollama-ability.php';" not in bootstrap, 'Unapproved Ollama activation')
    check((ROOT / (P+'includes/class-mad4b-scp-local-ollama-ability.php')).is_file(), 'Ollama source must be retained')
    switcher = source_text('.github/workflows/country-nationality-switchers.yml')
    for required in ('mcr.microsoft.com/playwright/python:v1.56.0-noble', 'python -m playwright install chromium', 'browser-smart-suggestions:'):
        check(required in switcher, 'Switcher real-browser job regressed: '+required)
    oauth = source_text('.github/workflows/mad4b-local-oauth-standalone.yml')
    check('npx playwright install chromium' in oauth and 'node ./test.mjs' in oauth, 'OAuth browser assertions removed')
    neutral = json.loads(source_text(P+'tests/site-neutrality-policy.json'))
    # Locate the policy declaration without changing any policy or exemption.
    field_policy = [v for k,v in neutral.get('reviewed_business_models', {}).items() if k.endswith('class-mad4b-scp-provider-field-intelligence.php')]
    check(field_policy and field_policy[0].get('models') == [], 'Tourism default remains in site neutrality policy')
    successor = source_text(P+'tests/integration-pr415-pr416-pr420-pr421-contract.py')
    check('integration-feature007-admin-successor-contract.py' in successor, 'Exact successor negative check missing')
    readiness = source_text('tools/readiness_test_execution.py')
    check('STANDALONE_PHP_REGRESSIONS = frozenset((' in readiness, 'Reviewed standalone allowlist missing')
    check('tours-dev-provider-field-read-parity-runtime.php' in readiness, 'Exact Field Intelligence regression not admitted')
    check('admin-pages-wordpress.php' in readiness, 'Actual WP smoke not included')
    closure = json.loads(source_text('specs/007-content-intelligence-workflow-platform/post-merge-capability-fabric-closure.json'))
    check(closure['baseline_source_review']['authorizing'] is False, 'Traceability baseline became release approval')
    coverage = json.loads(source_text('governance/production-readiness/capability-coverage.json'))
    check(isinstance(coverage, dict), 'Coverage manifest invalid')
    check(coverage.get('authorizing') is False and coverage.get('production_promotion_from_ci') is False, 'CI coverage cannot promote Production')
    return collision_rows


def report_rows(rows):
    return {
        'contract': 'mad4b.pr942-combined-collision-semantic-source-check.non-authorizing.v1',
        'historical_refs': {
            'pr882': '65bb2791f7b46c5887f3e814feb8b48604a3e0ae',
            'pr920': 'e1b8c8aa83fd72bea87cfe6511f8200b57420b12',
            'pr942': 'ed7ae0508cd927aa89294e56840d468f9ea8233e',
            'pr964': '9f610f0abc56f4d0cbe4249036f338acd6e46167',
        },
        'original_conflict_count': 57,
        'combined_collision_count': len(rows),
        'guarded_source_contract_check': 'passed',
        'behavioral_harnesses': [
            'tours-dev-provider-field-read-parity-runtime.php',
            'tours-dev-live-source-skills-parity-runtime.php',
            'tours-dev-live-catalog-read-runtime.php',
            'external-skill-sources-runtime.php',
            'external-skill-source-provider-runtime.php',
            'admin-route-registry-runtime.php',
            'admin-surface-coverage-runtime.php',
            'tours-dev-chatbotx-site-neutral-runtime.php',
            'skill-task-abilities-runtime.php',
            'integration-pr415-pr416-pr420-pr421-contract.py',
            'site-neutrality-contract.py',
            'admin-route-registry-contract.py',
            'skill-runtime-certification-contract.py',
            'tools/readiness_test_execution.py self-test',
        ],
        'per_path': [
            {
                'path': row['path'],
                'sha_pr882': row['source_882'],
                'sha_pr920': row['source_920'],
                'sha_pr942': row['reviewed_942'],
                'sha_followup_964': row['followup_964'],
                'risk_axis': REVIEW[row['path']][0],
                'verified_source_expectation': REVIEW[row['path']][1],
                'human_functional_signoff': False,
                'runtime_acceptance': 'pending_exact_company_staging',
            }
            for row in sorted(rows, key=lambda r: r['path'])
        ],
        'review_completed': False,
        'release_authorized': False,
        'production_deploy_authorized': False,
        'merge_authorized': False,
    }


def main():
    supplement = json.loads(SUPPLEMENT.read_text())
    rows = validate(supplement, lambda p: (ROOT / p).read_text())
    report = json.dumps(report_rows(rows), sort_keys=True, indent=2) + '\n'
    report_path = ROOT / 'docs/source-reconciliation/pr942-combined-collision-semantic-check-20261120.json'
    if len(sys.argv) == 2 and sys.argv[1] == '--report':
        print(report, end='')
    elif len(sys.argv) == 2 and sys.argv[1] == '--verify-report':
        check(report_path.read_text() == report, 'Stored review report is stale or forged')
        print('PASS: exact 11 composite collision source checks and report, release denied')
    elif len(sys.argv) == 1:
        print('PASS: exact 11 composite collision source checks; no functional signoff')
    else:
        raise SystemExit('Usage: pr942_combined_semantic_contract.py [--report|--verify-report]')
    return rows


if __name__ == '__main__':
    main()
