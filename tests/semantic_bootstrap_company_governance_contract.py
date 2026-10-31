#!/usr/bin/env python3
"""Exact merged bootstrap order and company governance scope, no live mutation."""
from pathlib import Path
p=Path(__file__).resolve().parents[1]/'wp-content/plugins/mad4b-site-control-plane'
s=(p/'mad4b-site-control-plane.php').read_text(); g=(p/'includes/class-mad4b-scp-company-readiness-governance.php').read_text()
required=['execution-authority','authority-auto-reconciliation','authority-reviews-bridge','provider-installed-artifact','global-runtime-lifecycle']
for name in required:
 marker="require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-"+name+".php';"
 assert s.count(marker)==1,(name,s.count(marker))
 assert (p/('includes/class-mad4b-scp-'+name+'.php')).is_file()
assert s.index('class-mad4b-scp-execution-authority.php')<s.index('class-mad4b-scp-agent-registry.php')
assert s.index('class-mad4b-scp-provider-installed-artifact.php')<s.index('class-mad4b-scp-provider-compatibility-certification.php')
assert s.index('class-mad4b-scp-global-runtime-lifecycle.php')<s.index('class-mad4b-scp-capability-dependency-planner.php')
for token in ('mad4b_company_cross_tenant_denied','mad4b_company_assignment_required',"current_user_can('mad4b_manage_company_operations')",'host_binding_verified'):
 assert token in g,token
assert 'tours.dev.mad4b.com' not in g
assert 'etg_commerce_staging' not in g
print('SEMANTIC_BOOTSTRAP_COMPANY_GOVERNANCE: PASS ordered source includes and tenant gates')
