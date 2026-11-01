# ETG Woo Lab Portable MU Adapters

Canonical source package for 32 reviewed MU PHP files; exact SHA-256 manifest plus prior-source hashes. Not a global tenant deployment or activation of commercial/payment features.

Source: mu-plugins/*.php
Manifest: manifest.json
Installer: tools/deploy_reviewed_mu.py
Docker PHP linter: tools/php_lint_via_lab_docker.sh
Tests: tests/test_deploy.py
PR source quality CI: .github/workflows/etg-woo-lab-portable-mu.yml
Serial opt-in master-SHA release: .github/workflows/etg-woo-lab-portable-mu-deploy.yml

The deployer validates the 32 source files, PHP syntax, the exact original or current SHA-256 of every destination, and refuses symlinks, missing files and unreviewed drift. It backs up changes with an audit receipt and permits hash-checked rollback. It never changes database records, sandbox guards or any other site files.

On the Woo Lab VPS:
MU_PHP_BIN=/path/to/tools/php_lint_via_lab_docker.sh python3 tools/deploy_reviewed_mu.py --check
MU_PHP_BIN=/path/to/tools/php_lint_via_lab_docker.sh python3 tools/deploy_reviewed_mu.py --dry-run --target /opt/hostinger-migrations/hosting-3/etg-woocommerce-lab/site-copy/wp-content/mu-plugins
MU_PHP_BIN=/path/to/tools/php_lint_via_lab_docker.sh python3 tools/deploy_reviewed_mu.py --apply --target /opt/hostinger-migrations/hosting-3/etg-woocommerce-lab/site-copy/wp-content/mu-plugins --backup-dir /opt/commerce-components/deploy/backups/APPROVED_COMMIT

Two files also appear in the source-only shared-product-core snapshot: local-tour-rate-filter-provider-router.php and shared-extra-display-mode.php. This site-specific overlay must deploy after that package. The shared-core snapshot is not production-authorized and must not be auto-deployed as part of this package.

Automatic GitHub-to-Woo-Lab delivery is opt-in: WOO_LAB_MU_AUTO_DEPLOY=true repository variable, protected woo-lab GitHub Environment, and SSH host/user/private-key/pinned-known-host secrets. The workflow must receive approvals and a real deploy receipt before claiming active automated releases. No Production or Staging mutation.

VPS evidence: /opt/commerce-components/evidence/etg-normal-feature-gates-20261029 and /opt/commerce-components/evidence/etg-mu-canonical-package-20261029.

## Currency display regression fix — 2026-10-31

The currency shortcode no longer disappears when the FX snapshot is missing or older than 48 hours. The USD recovery state remains visible and unsupported selections are disabled. This change replaces the prior switcher source and preserves the 32-file exact manifest. The separate `packages/mad4b-fx-resilience` package contains the independently scheduled FX refresh adapter; it must be deployed with the site-specific guarded release, not inferred to be enabled from this portable UI source alone.
