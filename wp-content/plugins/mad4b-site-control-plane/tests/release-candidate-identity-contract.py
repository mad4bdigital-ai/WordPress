#!/usr/bin/env python3
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]
PLUGIN = ROOT / 'wp-content/plugins/mad4b-site-control-plane'
MAIN = PLUGIN / 'mad4b-site-control-plane.php'
RUNTIME = PLUGIN / 'MAD4B-RUNTIME-BUILD.txt'
README = PLUGIN / 'README.md'
HANDOFF = PLUGIN / 'config/staging-deployment-handoff.json'
PACKAGE_WORKFLOW = ROOT / '.github/workflows/mad4b-control-plane-package.yml'

main = MAIN.read_text(encoding='utf-8')
runtime = RUNTIME.read_text(encoding='utf-8')
readme = README.read_text(encoding='utf-8')
workflow = PACKAGE_WORKFLOW.read_text(encoding='utf-8')
handoff = json.loads(HANDOFF.read_text(encoding='utf-8'))

header = re.search(r'^ \* Version: ([^ ]+)$', main, re.MULTILINE)
constant = re.search(r"define\( 'MAD4B_SCP_VERSION', '([^']+)' \);", main)
runtime_release = re.search(r'^release=(.+)$', runtime, re.MULTILINE)
readme_version = re.search(r'^Current plugin version: \*\*([^*]+)\*\*\.$', readme, re.MULTILINE)

if not all((header, constant, runtime_release, readme_version)):
    raise SystemExit('candidate version identity is incomplete')

versions = {
    'plugin_header': header.group(1),
    'runtime_constant': constant.group(1),
    'runtime_build': runtime_release.group(1),
    'readme': readme_version.group(1),
}
if len(set(versions.values())) != 1:
    raise SystemExit(f'candidate version drift: {versions}')

version = next(iter(versions.values()))
if not re.fullmatch(r'\d+\.\d+\.\d+-rc\.\d+', version):
    raise SystemExit(f'invalid release candidate version: {version}')

required_workflow_fragments = (
    'SOURCE_SHA: ${{ github.event.pull_request.head.sha || github.sha }}',
    "source = os.environ['SOURCE_SHA']",
    "'source_commit_sha': source",
    "artifact_identity = f'mad4b-site-control-plane-general-distribution-kit-{source}'",
    "'commit': os.environ.get('SOURCE_SHA')",
)
for fragment in required_workflow_fragments:
    if fragment not in workflow:
        raise SystemExit(f'package exact-head source binding missing: {fragment}')

if handoff.get('producer', {}).get('source_binding') != 'exact_head_sha':
    raise SystemExit('deployment handoff is not exact-head bound')
if handoff.get('producer', {}).get('artifact_name_template') != 'mad4b-site-control-plane-general-distribution-kit-{exact_head_sha}':
    raise SystemExit('deployment artifact identity is not exact-head bound')

if re.search(r'\b[0-9a-f]{40}\b', runtime, re.I):
    raise SystemExit('runtime build marker must not hard-code a source commit SHA')

print(f'release candidate identity coherence: PASS ({version})')
