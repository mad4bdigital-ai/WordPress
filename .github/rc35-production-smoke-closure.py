#!/usr/bin/env python3
from pathlib import Path

path = Path('wp-content/plugins/mad4b-site-control-plane/tests/runtime-dynamic-skills-production-smoke.php')
text = path.read_text(encoding='utf-8')

replacements = {
    "if ( 'environment_not_staging' !== $autoconfig['blocker'] ) $fail( 'Production autoconfig blocker is unexpected.' );":
    "if ( 'site_profile_unconfigured' !== $autoconfig['blocker'] ) $fail( 'Unconfigured Production autoconfig blocker is unexpected.' );",
    "if ( ! in_array( 'environment_not_staging', isset( $cert['blockers'] ) ? $cert['blockers'] : array(), true ) ) $fail( 'Production certification did not fail on environment boundary.' );":
    "if ( ! in_array( 'site_profile_not_enrolled', isset( $cert['blockers'] ) ? $cert['blockers'] : array(), true ) ) $fail( 'Unconfigured Production Skill certification did not fail on Site Profile enrollment.' );",
    "if ( ! in_array( 'environment_not_staging', isset( $write_cert['blockers'] ) ? $write_cert['blockers'] : array(), true ) ) $fail( 'Production write certification did not fail on the environment boundary.' );":
    "if ( ! in_array( 'site_profile_unconfigured', isset( $write_cert['blockers'] ) ? $write_cert['blockers'] : array(), true ) ) $fail( 'Unconfigured Production write certification did not fail on Site Profile authority.' );",
}

for old, new in replacements.items():
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'expected exactly one match, found {count}: {old!r}')
    text = text.replace(old, new, 1)

if 'environment_not_staging' in text:
    raise SystemExit('stale environment_not_staging assertion remains in Production smoke')

path.write_text(text, encoding='utf-8')
print('RC35 Production smoke closure applied')
