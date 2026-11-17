# Wayfatours Production: exact scoped read-only Skills recovery

This five-file preimage-verified manual recovery adds read-only Skill match,
composition and company Master Admin Operations & Skills inspection.

Tests on live Wayfatours:
- WordPress registered both abilities.
- Match returned read_only, no authority, three candidates.
- Composition returned read_only_plan, execution_ready false.
- Master Admin read-only 25/25 catalog works without manage_options.
- Anonymous request is denied.
- EN and ES home routes returned HTTP 200.
- App ID remains empty; Production authoring remains disabled.
- Existing role grants remain untouched and require owner migration.

The manifest pins exact original and patched SHA256, source-only checks and
backup receipts under
/opt/mad4b-company-provisioning/reports/wayfa-readonly-skills-20261117/.

Manual code-only change does not imply Original313 release admission,
approved grants, Site Profile identity mapping, or final CI passing.
