# Media Rights / Original305 unified source review (CI only)

- Original parent 57746e6270f68308418cf84ba70a8421a430398e; read the latest parent first.
- Three 2026-11-16 Media Rights source changes were reviewed with an exact Git blob for each source and mapped original controls.
- New source-backed hermetic regression calls actual Content Experience, Remote Rights and Remote Import Adapter PHP source; tested on PHP 7.4 and 8.5.
- PHP 8-only str_starts_with was replaced in two runtime paths with strict-prefix strpos checks, preserving behavior.
- Existing local WordPress Media Library files without usage metadata remain warnings, not claimed copyright licenses.
- Unknown or unresolved images, explicit restrictions, expired/invalid licensing dates, incorrect CC BY public-domain classifications, and changed remote file provenance remain denied.
- The WordPress admin WP-CLI integration scenario is **not** certified by hermetic tests; its standalone script skips without an authorized WordPress context.
- Reviewed source coverage is not live readiness. Host312 proof, Original305 owner approval, signed release and independent Original313 receipt remain required.
- No production deployment, grant, signing, WP changes or remote imports were performed in this source review.
