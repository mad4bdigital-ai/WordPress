# Staging Content Hub test-only isolation

This patch is source-only. It is intended for a controlled Staging rollout after fresh verified database backup.

- `010-mad4b-test-data-isolation.php`: site-neutral MU guard excluding explicitly classified QA records from WP_Query public listings, including explicit `post__in` selection. Admin editing is unaffected.
- `class-mad4b-hub-source-reader.php`: guarded read-only Content Hub source reader; prevents test-only records and child rates of test-only parents from becoming content/price candidates.
- Classification: `_mad4b_is_test_data=1` and `_mad4b_data_classification=test_only`.
- Existing synthetic content must be put into Draft and classified before migration. Classification and Draft are independent fail-closed safeguards.
- Do not change global Production, source sites, DNS, SMTP or any live checkout; retain existing Content Hub preview-only no-distribution settings.
- This source patch does not certify arbitrary custom SQL queries or unreviewed third-party integrations; they need separate audit.
- GitHub is a source repository, not an operational approval authority.

Evidence: `qa/qa-isolation-content-hub-20261029` on authorized staging VPS (backup must remain off GitHub).
